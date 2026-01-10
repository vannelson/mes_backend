<?php

namespace App\Services;

use App\Http\Resources\Bom\BomResource;
use App\Models\Bom;
use App\Repositories\Contracts\BomRepositoryInterface;
use App\Services\Contracts\BomServiceInterface;
use Illuminate\Support\Arr;
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

        $result = $this->createBatch($normalized);

        return array_merge($result, [
            'batch_number' => $batchNumber,
            'deleted' => $deleted,
        ]);
    }

    public function getStats(int $limit = 7): array
    {
        $limit = max(1, $limit);
        $materialColumns = ['material_1_code', 'material_2_code', 'material_3_code', 'material_4_code'];
        $colorColumns = ['colour_code_1', 'colour_code_2', 'colour_code_3', 'colour_code_4'];

        $totalRows = Bom::query()->count();
        $uniqueCustomers = $this->countDistinctNonEmpty('customer_code');
        $uniqueParts = $this->countDistinctNonEmpty('part_no');
        $uniqueBatches = $this->countDistinctNonEmpty('batch_number');
        $uniqueMaterials = $this->countDistinctUnion($materialColumns);
        $uniqueColors = $this->countDistinctUnion($colorColumns);

        $rowsWithMaterials = $this->countRowsWithAny($materialColumns);
        $rowsWithColors = $this->countRowsWithAny($colorColumns);

        $materialSlots = $this->sumColumnPresence($materialColumns);
        $colorSlots = $this->sumColumnPresence($colorColumns);

        $materialCoverage = $totalRows > 0 ? ($rowsWithMaterials / $totalRows) * 100 : 0.0;
        $colorCoverage = $totalRows > 0 ? ($rowsWithColors / $totalRows) * 100 : 0.0;
        $avgMaterials = $totalRows > 0 ? $materialSlots / $totalRows : 0.0;
        $avgColors = $totalRows > 0 ? $colorSlots / $totalRows : 0.0;

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
            'top_materials' => $this->topUnionValues($materialColumns, $limit, 'label'),
            'color_mix' => $this->topUnionValues($colorColumns, $limit, 'name'),
            'top_customers' => $this->topColumnCounts('customer_code', $limit),
            'top_parts' => $this->topColumnCounts('part_no', $limit),
            'top_batches' => $this->topColumnCounts('batch_number', $limit),
        ];
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

    protected function countDistinctNonEmpty(string $column): int
    {
        return (int) Bom::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->count($column);
    }

    protected function countRowsWithAny(array $columns): int
    {
        if (empty($columns)) {
            return 0;
        }

        return (int) Bom::query()
            ->where(function ($query) use ($columns) {
                foreach ($columns as $column) {
                    $query->orWhere(function ($inner) use ($column) {
                        $inner->whereNotNull($column)->where($column, '!=', '');
                    });
                }
            })
            ->count();
    }

    protected function sumColumnPresence(array $columns): int
    {
        if (empty($columns)) {
            return 0;
        }

        $parts = [];
        foreach ($columns as $column) {
            $parts[] = "(CASE WHEN {$column} IS NULL OR {$column} = '' THEN 0 ELSE 1 END)";
        }
        $expression = implode(' + ', $parts);

        return (int) Bom::query()->selectRaw("COALESCE(SUM({$expression}), 0) as total")->value('total');
    }

    protected function buildUnionValuesQuery(array $columns)
    {
        $union = null;

        foreach ($columns as $column) {
            $sub = DB::table('boms')
                ->selectRaw("{$column} as value")
                ->whereNotNull($column)
                ->where($column, '!=', '');

            if ($union === null) {
                $union = $sub;
            } else {
                $union->unionAll($sub);
            }
        }

        return $union;
    }

    protected function countDistinctUnion(array $columns): int
    {
        $union = $this->buildUnionValuesQuery($columns);
        if (!$union) {
            return 0;
        }

        return (int) DB::query()
            ->fromSub($union, 'values')
            ->distinct()
            ->count('value');
    }

    protected function topUnionValues(array $columns, int $limit, string $labelKey): array
    {
        $union = $this->buildUnionValuesQuery($columns);
        if (!$union) {
            return [];
        }

        return DB::query()
            ->fromSub($union, 'values')
            ->select('value', DB::raw('COUNT(*) as total'))
            ->groupBy('value')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(function ($row) use ($labelKey) {
                return [
                    $labelKey => $row->value,
                    'value' => (int) $row->total,
                ];
            })
            ->values()
            ->all();
    }

    protected function topColumnCounts(string $column, int $limit, string $labelKey = 'label'): array
    {
        return Bom::query()
            ->select($column, DB::raw('COUNT(*) as total'))
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(function ($row) use ($column, $labelKey) {
                return [
                    $labelKey => $row->{$column},
                    'value' => (int) $row->total,
                ];
            })
            ->values()
            ->all();
    }
}
