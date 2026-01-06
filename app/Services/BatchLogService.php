<?php

namespace App\Services;

use App\Http\Resources\BatchLog\BatchLogResource;
use App\Repositories\Contracts\BatchLogRepositoryInterface;
use App\Repositories\Contracts\WorkOrderRepositoryInterface;
use App\Services\Contracts\BatchLogServiceInterface;

class BatchLogService implements BatchLogServiceInterface
{
    public function __construct(
        protected BatchLogRepositoryInterface $batchLogRepository,
        protected WorkOrderRepositoryInterface $workOrderRepository
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

    public function delete(int $id, bool $deleteWorkOrders = false): array
    {
        $batchLog = $this->batchLogRepository->findById($id);
        $batchNo = $batchLog->batch_no;
        $workOrdersCount = $this->workOrderRepository->countByBatch($batchNo);

        if ($workOrdersCount > 0 && ! $deleteWorkOrders) {
            return [
                'deleted' => false,
                'blocked' => true,
                'work_orders_count' => $workOrdersCount,
                'message' => 'Work orders are still linked to this batch. Set delete_work_orders=1 to remove them along with the batch log.',
            ];
        }

        $deletedWorkOrders = 0;
        if ($workOrdersCount > 0 && $deleteWorkOrders) {
            $deletedWorkOrders = $this->workOrderRepository->deleteByBatch($batchNo);
        }

        $this->batchLogRepository->delete($id);

        return [
            'deleted' => true,
            'blocked' => false,
            'work_orders_count' => $workOrdersCount,
            'deleted_work_orders' => $deletedWorkOrders,
        ];
    }
}
