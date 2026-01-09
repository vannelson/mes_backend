<?php

namespace App\Repositories;

use App\Models\WorkOrder;
use App\Repositories\Contracts\WorkOrderRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class WorkOrderRepository extends BaseRepository implements WorkOrderRepositoryInterface
{
    public function __construct(WorkOrder $workOrder)
    {
        parent::__construct($workOrder);
    }

    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->with(['customer', 'templateRoute']);

        if ($workOrderNo = Arr::get($filters, 'work_order_no')) {
            $query->where('work_order_no', 'LIKE', "%{$workOrderNo}%");
        }

        if ($batchNumber = Arr::get($filters, 'batch_number')) {
            $query->where('batch_number', 'LIKE', "%{$batchNumber}%");
        }

        if ($customerId = Arr::get($filters, 'customer_id')) {
            $query->where('customer_id', $customerId);
        }

        if (($selected = Arr::get($filters, 'selected')) !== null) {
            $query->where('selected', filter_var($selected, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]) ?? (bool) $selected);
        }

        if ($mesBatchNo = Arr::get($filters, 'mes_batch_no')) {
            $query->where('mes_batch_no', 'LIKE', "%{$mesBatchNo}%");
        }

        if ($customerCode = Arr::get($filters, 'customer_code')) {
            $query->where('customer_code', 'LIKE', "%{$customerCode}%");
        }

        if ($customerName = Arr::get($filters, 'customer_name')) {
            $query->where('customer_name', 'LIKE', "%{$customerName}%");
        }

        if ($salesPersonCode = Arr::get($filters, 'sales_person_code')) {
            $query->where('sales_person_code', 'LIKE', "%{$salesPersonCode}%");
        }

        if ($orderFrom = Arr::get($filters, 'order_date_from')) {
            $query->whereDate('order_date', '>=', $orderFrom);
        }

        if ($orderTo = Arr::get($filters, 'order_date_to')) {
            $query->whereDate('order_date', '<=', $orderTo);
        }

        if ($dueFrom = Arr::get($filters, 'production_due_from')) {
            $query->whereDate('production_due_date', '>=', $dueFrom);
        }

        if ($dueTo = Arr::get($filters, 'production_due_to')) {
            $query->whereDate('production_due_date', '<=', $dueTo);
        }

        if ($templateRouteId = Arr::get($filters, 'template_route_id')) {
            $query->where('template_route_id', $templateRouteId);
        }

        if ($templateRouteBatch = Arr::get($filters, 'template_route_batch_number')) {
            $query->whereHas('templateRoute', function ($q) use ($templateRouteBatch) {
                $q->where('batch_number', $templateRouteBatch);
            });
        }

        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        switch ($orderBy) {
            case 'route_link':
                // Order by presence of a linked template route, then by id for stability
                $query
                    ->orderByRaw("template_route_id IS NULL " . ($direction === 'asc' ? 'ASC' : 'DESC'))
                    ->orderBy('template_route_id', $direction)
                    ->orderBy('id', $direction);
                break;
            case 'production_due_date':
            case 'requested_delivery_date':
            case 'status':
            case 'work_order_no':
            case 'id':
                $query->orderBy($orderBy, $direction);
                break;
            default:
                $query->orderBy('id', 'desc');
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function options(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->select(['id', 'work_order_no']);

        if ($search = Arr::get($filters, 'search')) {
            $query->where('work_order_no', 'LIKE', "%{$search}%");
        }

        if ($customerId = Arr::get($filters, 'customer_id')) {
            $query->where('customer_id', $customerId);
        }

        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $query->orderBy($orderBy, $direction);

        return $query->paginate($limit, ['id', 'work_order_no'], 'page', $page);
    }

    public function findByColumn(string $column, mixed $value)
    {
        return $this->model->newQuery()->where($column, $value)->firstOrFail();
    }

    public function withTemplateRoutes(): Collection
    {
        return $this->model
            ->newQuery()
            ->with(['customer', 'templateRoute'])
            ->whereNotNull('template_route_id')
            ->whereHas('templateRoute')
            ->where(function ($query) {
                $query
                    ->whereNotNull('metadata')
                    ->where('metadata', '<>', '')
                    ->where('metadata', '<>', '[]')
                    ->where('metadata', '<>', '{}');
            })
            ->orderBy('id', 'desc')
            ->get();
    }

    public function countByBatch(string $batchNumber): int
    {
        return $this->model->newQuery()
            ->where('batch_number', $batchNumber)
            ->count();
    }

    public function deleteByBatch(string $batchNumber): int
    {
        return $this->model->newQuery()
            ->where('batch_number', $batchNumber)
            ->delete();
    }

    public function countByTemplateRouteBatch(string $batchNumber): int
    {
        return $this->model->newQuery()
            ->whereHas('templateRoute', function ($q) use ($batchNumber) {
                $q->where('batch_number', $batchNumber);
            })
            ->count();
    }
}
