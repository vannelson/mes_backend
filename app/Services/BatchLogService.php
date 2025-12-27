<?php

namespace App\Services;

use App\Http\Resources\BatchLog\BatchLogResource;
use App\Repositories\Contracts\BatchLogRepositoryInterface;
use App\Services\Contracts\BatchLogServiceInterface;

class BatchLogService implements BatchLogServiceInterface
{
    public function __construct(
        protected BatchLogRepositoryInterface $batchLogRepository
    ) {
    }

    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        return BatchLogResource::collection(
            $this->batchLogRepository->listing($filters, $order, $limit, $page)
        )->response()->getData(true);
    }

    public function detail(int $id): array
    {
        return (new BatchLogResource($this->batchLogRepository->findById($id)->load('user')))->response()->getData(true);
    }

    public function create(array $data): array
    {
        $batchLog = $this->batchLogRepository->create($data)->load('user');

        return (new BatchLogResource($batchLog))->response()->getData(true);
    }

    public function update(int $id, array $data): array
    {
        $updated = (bool) $this->batchLogRepository->update($id, $data);

        if (!$updated) {
            return [];
        }

        return (new BatchLogResource($this->batchLogRepository->findById($id)->load('user')))->response()->getData(true);
    }

    public function delete(int $id): bool
    {
        return $this->batchLogRepository->delete($id);
    }
}
