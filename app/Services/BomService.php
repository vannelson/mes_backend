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
}
