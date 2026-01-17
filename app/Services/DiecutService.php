<?php

namespace App\Services;

use App\Http\Resources\Diecut\DiecutResource;
use App\Models\Diecut;
use App\Repositories\Contracts\DiecutRepositoryInterface;
use App\Services\Contracts\DiecutServiceInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class DiecutService implements DiecutServiceInterface
{
    public function __construct(
        protected DiecutRepositoryInterface $diecutRepository
    ) {
    }

    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        return DiecutResource::collection(
            $this->diecutRepository->listing($filters, $order, $limit, $page)
        )->response()->getData(true);
    }

    public function createBatch(array $diecuts): array
    {
        $created = [];
        $failed = [];
        $compositeKeys = [];

        foreach ($diecuts as $diecut) {
            $key = $this->buildCompositeKey($diecut);
            if ($key !== '') {
                $compositeKeys[] = $key;
            }
        }

        $existingKeyMap = $this->loadExistingCompositeKeys($compositeKeys);
        $seenInPayload = [];

        foreach ($diecuts as $diecut) {
            $compositeKey = $this->buildCompositeKey($diecut);
            $diecutNo = Arr::get($diecut, 'diecut_no');

            if ($compositeKey === '') {
                $failed[] = [
                    'diecut_no' => $diecutNo,
                    'message' => 'Missing diecut number to evaluate duplicates.',
                ];
                continue;
            }

            if (isset($seenInPayload[$compositeKey])) {
                $failed[] = [
                    'diecut_no' => $diecutNo,
                    'message' => 'Duplicate in request skipped (Diecut No.).',
                ];
                continue;
            }

            if (isset($existingKeyMap[$compositeKey])) {
                $failed[] = [
                    'diecut_no' => $diecutNo,
                    'message' => 'Duplicate of an existing diecut skipped (Diecut No.).',
                ];
                continue;
            }

            try {
                $this->ensureBatchNumber($diecut);
                $created[] = $this->diecutRepository->create($diecut);
                $seenInPayload[$compositeKey] = true;
            } catch (Throwable $e) {
                $failed[] = [
                    'diecut_no' => $diecutNo,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'items' => DiecutResource::collection(collect($created))->resolve(),
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

        return DiecutResource::collection(
            $this->diecutRepository->listing($filters, ['id', 'desc'], $limit, $page)
        )->response()->getData(true);
    }

    public function replaceBatch(string $batchNumber, array $diecuts): array
    {
        $deleted = $this->diecutRepository->deleteByBatch($batchNumber);

        $normalized = array_map(function (array $diecut) use ($batchNumber): array {
            $diecut['batch_number'] = $batchNumber;
            return $diecut;
        }, $diecuts);

        $result = $this->createBatch($normalized);

        return array_merge($result, [
            'batch_number' => $batchNumber,
            'deleted' => $deleted,
        ]);
    }

    protected function buildCompositeKey(array $diecut): string
    {
        $diecutNo = strtolower(trim((string) Arr::get($diecut, 'diecut_no', '')));

        if ($diecutNo === '') {
            return '';
        }

        return $diecutNo;
    }

    protected function loadExistingCompositeKeys(array $compositeKeys): array
    {
        if (empty($compositeKeys)) {
            return [];
        }

        $expression = "LOWER(TRIM(COALESCE(diecut_no, '')))";

        return Diecut::query()
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
