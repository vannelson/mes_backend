<?php

namespace App\Services;

use App\Http\Resources\WorkOrder\WorkOrderResource;
use App\Models\TemplateRoute;
use App\Repositories\Contracts\WorkOrderRepositoryInterface;
use App\Services\Contracts\WorkOrderServiceInterface;

class WorkOrderService implements WorkOrderServiceInterface
{
    public function __construct(
        protected WorkOrderRepositoryInterface $workOrderRepository
    ) {
    }

    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        return WorkOrderResource::collection(
            $this->workOrderRepository->listing($filters, $order, $limit, $page)
        )->response()->getData(true);
    }

    public function detail(int $id): array
    {
        $workOrder = $this->workOrderRepository->findById($id)->load(['customer', 'templateRoute']);

        return (new WorkOrderResource($workOrder))->response()->getData(true);
    }

    public function create(array $data): array
    {
        $this->syncTemplateMetadata($data);
        $workOrder = $this->workOrderRepository->create($data)->load(['customer', 'templateRoute']);

        return (new WorkOrderResource($workOrder))->response()->getData(true);
    }

    public function update(int $id, array $data): bool
    {
        $this->syncTemplateMetadata($data);

        return (bool) $this->workOrderRepository->update($id, $data);
    }

    public function delete(int $id): bool
    {
        return $this->workOrderRepository->delete($id);
    }

    protected function syncTemplateMetadata(array &$data): void
    {
        if (!empty($data['template_route_id'])) {
            $templateRoute = TemplateRoute::findOrFail($data['template_route_id']);
            // reuse template metadata so work orders stay in sync with the chosen template
            $data['metadata'] = $templateRoute->metadata;
        }
    }
}
