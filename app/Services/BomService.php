<?php

namespace App\Services;

use App\Http\Resources\Bom\BomResource;
use App\Models\Bom;
use App\Repositories\Contracts\BomRepositoryInterface;
use App\Services\Contracts\BomServiceInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class BomService implements BomServiceInterface
{
    public function __construct(
        protected BomRepositoryInterface $bomRepository
    ) {
    }

    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        return BomResource::collection(
            $this->bomRepository->listing($filters, $order, $limit, $page)
        )->response()->getData(true);
    }

    public function createBatch(array $boms): array
    {
        $result = $this->createBatchInternal($boms);
        $this->bumpStatsCacheVersion();

        return $result;
    }

    protected function createBatchInternal(array $boms): array
    {
        $created = [];
        $failed = [];
        $compositeKeys = [];

        foreach ($boms as $bom) {
            $key = $this->buildCompositeKey($bom);
            if ($key !== '|') {
                $compositeKeys[] = $key;
            }
        }

        $existingKeyMap = $this->loadExistingCompositeKeys($compositeKeys);
        $seenInPayload = [];

        foreach ($boms as $bom) {
            $compositeKey = $this->buildCompositeKey($bom);
            $partNo = Arr::get($bom, 'part_no');
            $customerCode = Arr::get($bom, 'customer_code');

            if ($compositeKey === '|') {
                $failed[] = [
                    'part_no' => $partNo,
                    'customer_code' => $customerCode,
                    'message' => 'Missing identifiers to evaluate duplicates (Customer Code + Part No.).',
                ];
                continue;
            }

            if (isset($seenInPayload[$compositeKey])) {
                $failed[] = [
                    'part_no' => $partNo,
                    'customer_code' => $customerCode,
                    'message' => 'Duplicate in request skipped (Customer Code + Part No.).',
                ];
                continue;
            }

            if (isset($existingKeyMap[$compositeKey])) {
                $failed[] = [
                    'part_no' => $partNo,
                    'customer_code' => $customerCode,
                    'message' => 'Duplicate of an existing BOM skipped (Customer Code + Part No.).',
                ];
                continue;
            }

            try {
                $this->ensureBatchNumber($bom);
                $created[] = $this->bomRepository->create($bom);
                $seenInPayload[$compositeKey] = true;
            } catch (Throwable $e) {
                $failed[] = [
                    'part_no' => $partNo,
                    'customer_code' => $customerCode,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'items' => BomResource::collection(collect($created))->resolve(),
            'count' => count($created),
            'failed' => count($failed),
            'errors' => $failed,
        ];
    }

    public function listByBatch(string $batchNumber, int $limit = 10, int $page = 1): array
    {
        $filters = [
            'batch_number' => $batchNumber,
        ];

        return BomResource::collection(
            $this->bomRepository->listing($filters, ['id', 'desc'], $limit, $page)
        )->response()->getData(true);
    }

    public function replaceBatch(string $batchNumber, array $boms): array
    {
        $deleted = $this->bomRepository->deleteByBatch($batchNumber);

        $normalized = array_map(function (array $bom) use ($batchNumber): array {
            $bom['batch_number'] = $batchNumber;
            return $bom;
        }, $boms);

        $result = $this->createBatchInternal($normalized);
        $this->bumpStatsCacheVersion();

        return array_merge($result, [
            'batch_number' => $batchNumber,
            'deleted' => $deleted,
        ]);
    }

    public function getStats(array $filters = []): array
    {
        $normalizedFilters = $this->normalizeStatsFilters($filters);
        $cacheKey = $this->buildStatsCacheKey($normalizedFilters);

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($normalizedFilters) {
            return $this->computeStats($normalizedFilters);
        });
    }

    protected function computeStats(array $filters): array
    {
        $baseQuery = $this->applyFilters(Bom::query(), $filters);
        $materialsCondition = $this->buildAnyCodeCondition([
            'material_1_code',
            'material_2_code',
            'material_3_code',
            'material_4_code',
        ]);
        $colorsCondition = $this->buildAnyCodeCondition([
            'colour_code_1',
            'colour_code_2',
            'colour_code_3',
            'colour_code_4',
        ]);

        $aggregate = (clone $baseQuery)->selectRaw(
            "COUNT(*) as total_rows,
            SUM(CASE WHEN {$materialsCondition} THEN 1 ELSE 0 END) as rows_with_materials,
            SUM(CASE WHEN {$colorsCondition} THEN 1 ELSE 0 END) as rows_with_colors"
        )->first();

        $totalRows = (int) ($aggregate->total_rows ?? 0);
        $rowsWithMaterials = (int) ($aggregate->rows_with_materials ?? 0);
        $rowsWithColors = (int) ($aggregate->rows_with_colors ?? 0);

        $uniqueCustomers = (int) (clone $baseQuery)
            ->whereNotNull('customer_code')
            ->whereRaw("TRIM(customer_code) <> ''")
            ->distinct()
            ->count('customer_code');
        $uniqueParts = (int) (clone $baseQuery)
            ->whereNotNull('part_no')
            ->whereRaw("TRIM(part_no) <> ''")
            ->distinct()
            ->count('part_no');
        $uniqueBatches = (int) (clone $baseQuery)
            ->whereNotNull('batch_number')
            ->whereRaw("TRIM(batch_number) <> ''")
            ->distinct()
            ->count('batch_number');

        $materialUnion = $this->buildUnionQuery($filters, [
            'material_1_code',
            'material_2_code',
            'material_3_code',
            'material_4_code',
        ]);
        $colorUnion = $this->buildUnionQuery($filters, [
            'colour_code_1',
            'colour_code_2',
            'colour_code_3',
            'colour_code_4',
        ]);

        $totalMaterials = (int) DB::query()->fromSub($materialUnion, 'materials')->count();
        $totalColors = (int) DB::query()->fromSub($colorUnion, 'colors')->count();
        $uniqueMaterials = (int) DB::query()
            ->fromSub($materialUnion, 'materials')
            ->distinct()
            ->count('code');
        $uniqueColors = (int) DB::query()
            ->fromSub($colorUnion, 'colors')
            ->distinct()
            ->count('code');

        $topMaterials = DB::query()
            ->fromSub($materialUnion, 'materials')
            ->select('code', DB::raw('COUNT(*) as total'))
            ->groupBy('code')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row) => ['label' => $row->code, 'value' => (int) $row->total])
            ->values()
            ->all();

        $topColors = DB::query()
            ->fromSub($colorUnion, 'colors')
            ->select('code', DB::raw('COUNT(*) as total'))
            ->groupBy('code')
            ->orderByDesc('total')
            ->limit(6)
            ->get();
        $topColorTotal = (int) $topColors->sum('total');
        $colorMix = $topColors
            ->map(fn ($row) => ['name' => $row->code, 'value' => (int) $row->total])
            ->values()
            ->all();
        if ($totalColors > $topColorTotal) {
            $colorMix[] = [
                'name' => 'Other',
                'value' => $totalColors - $topColorTotal,
            ];
        }

        $topCustomers = $this->buildTopList($filters, 'customer_code', 6);
        $topParts = $this->buildTopList($filters, 'part_no', 6);
        $topBatches = $this->buildTopList($filters, 'batch_number', 6);

        $materialCoverage = $totalRows > 0 ? ($rowsWithMaterials / $totalRows) * 100 : 0;
        $colorCoverage = $totalRows > 0 ? ($rowsWithColors / $totalRows) * 100 : 0;
        $avgMaterials = $totalRows > 0 ? $totalMaterials / $totalRows : 0;
        $avgColors = $totalRows > 0 ? $totalColors / $totalRows : 0;

        return [
            'total_rows' => $totalRows,
            'unique_customers' => $uniqueCustomers,
            'unique_parts' => $uniqueParts,
            'unique_materials' => $uniqueMaterials,
            'unique_colors' => $uniqueColors,
            'unique_batches' => $uniqueBatches,
            'rows_with_materials' => $rowsWithMaterials,
            'rows_with_colors' => $rowsWithColors,
            'material_coverage' => $materialCoverage,
            'color_coverage' => $colorCoverage,
            'avg_materials' => $avgMaterials,
            'avg_colors' => $avgColors,
            'top_materials' => $topMaterials,
            'color_mix' => $colorMix,
            'top_customers' => $topCustomers,
            'top_parts' => $topParts,
            'top_batches' => $topBatches,
        ];
    }

    protected function normalizeStatsFilters(array $filters): array
    {
        $normalized = [];

        foreach ($filters as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed === '') {
                    continue;
                }
                $normalized[$key] = $trimmed;
                continue;
            }

            $normalized[$key] = $value;
        }

        ksort($normalized);

        return $normalized;
    }

    protected function buildStatsCacheKey(array $filters): string
    {
        $payload = empty($filters) ? 'all' : json_encode($filters);
        if ($payload === false) {
            $payload = serialize($filters);
        }

        return 'bom_stats:' . $this->getStatsCacheVersion() . ':' . md5($payload);
    }

    protected function getStatsCacheVersion(): int
    {
        $key = 'bom_stats_version';
        $version = Cache::get($key);

        if (!is_numeric($version)) {
            Cache::forever($key, 1);
            return 1;
        }

        return (int) $version;
    }

    protected function bumpStatsCacheVersion(): void
    {
        $key = 'bom_stats_version';
        $version = Cache::get($key);
        $next = is_numeric($version) ? ((int) $version + 1) : 1;

        Cache::forever($key, $next);
    }

    protected function buildCompositeKey(array $bom): string
    {
        $customerCode = strtolower(trim((string) Arr::get($bom, 'customer_code', '')));
        $partNo = strtolower(trim((string) Arr::get($bom, 'part_no', '')));

        if ($customerCode === '' || $partNo === '') {
            return '|';
        }

        return implode('|', [$customerCode, $partNo]);
    }

    protected function loadExistingCompositeKeys(array $compositeKeys): array
    {
        if (empty($compositeKeys)) {
            return [];
        }

        $expression = "LOWER(CONCAT_WS('|', TRIM(COALESCE(customer_code, '')), TRIM(COALESCE(part_no, ''))))";

        return Bom::query()
            ->selectRaw("{$expression} AS composite_key")
            ->whereIn(DB::raw($expression), array_values(array_unique($compositeKeys)))
            ->pluck('composite_key')
            ->mapWithKeys(fn ($key) => [$key => true])
            ->all();
    }

    protected function ensureBatchNumber(array &$data): void
    {
        if (array_key_exists('batch_number', $data) && !empty($data['batch_number'])) {
            return;
        }

        $data['batch_number'] = now()->format('dmy\THi');
    }

    protected function applyFilters($query, array $filters)
    {
        if ($batchNumber = Arr::get($filters, 'batch_number')) {
            $query->where('batch_number', 'LIKE', "%{$batchNumber}%");
        }

        if ($customerCode = Arr::get($filters, 'customer_code')) {
            $query->where('customer_code', 'LIKE', "%{$customerCode}%");
        }

        if ($partNo = Arr::get($filters, 'part_no')) {
            $query->where('part_no', 'LIKE', "%{$partNo}%");
        }

        if ($materialCode = Arr::get($filters, 'material_code')) {
            $query->where(function ($q) use ($materialCode) {
                $q->where('material_1_code', 'LIKE', "%{$materialCode}%")
                    ->orWhere('material_2_code', 'LIKE', "%{$materialCode}%")
                    ->orWhere('material_3_code', 'LIKE', "%{$materialCode}%")
                    ->orWhere('material_4_code', 'LIKE', "%{$materialCode}%");
            });
        }

        if ($description = Arr::get($filters, 'description')) {
            $query->where('description', 'LIKE', "%{$description}%");
        }

        if ($search = Arr::get($filters, 'q')) {
            $query->where(function ($q) use ($search) {
                $q->where('customer_code', 'LIKE', "%{$search}%")
                    ->orWhere('part_no', 'LIKE', "%{$search}%")
                    ->orWhere('material_1_code', 'LIKE', "%{$search}%")
                    ->orWhere('material_2_code', 'LIKE', "%{$search}%")
                    ->orWhere('material_3_code', 'LIKE', "%{$search}%")
                    ->orWhere('material_4_code', 'LIKE', "%{$search}%");
            });
        }

        return $query;
    }

    protected function buildUnionQuery(array $filters, array $columns)
    {
        $union = null;

        foreach ($columns as $column) {
            $subQuery = $this->applyFilters(Bom::query(), $filters)
                ->selectRaw("NULLIF(TRIM({$column}), '') as code")
                ->whereNotNull($column)
                ->whereRaw("TRIM({$column}) <> ''");

            $union = $union ? $union->unionAll($subQuery) : $subQuery;
        }

        return $union ? $union->toBase() : DB::query()->selectRaw('NULL as code')->whereRaw('1 = 0');
    }

    protected function buildAnyCodeCondition(array $columns): string
    {
        $conditions = array_map(
            fn ($column) => "({$column} IS NOT NULL AND TRIM({$column}) <> '')",
            $columns
        );

        return implode(' OR ', $conditions);
    }

    protected function buildTopList(array $filters, string $column, int $limit): array
    {
        $expression = "COALESCE(NULLIF(TRIM({$column}), ''), 'Unassigned')";

        return $this->applyFilters(Bom::query(), $filters)
            ->selectRaw("{$expression} as label, COUNT(*) as total")
            ->groupBy(DB::raw($expression))
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['label' => $row->label, 'value' => (int) $row->total])
            ->values()
            ->all();
    }
}
