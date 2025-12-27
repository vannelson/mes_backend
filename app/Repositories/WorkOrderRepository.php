<?php

namespace App\Repositories;

use App\Models\WorkOrder;
use App\Repositories\Contracts\WorkOrderRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

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

        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $query->orderBy($orderBy, $direction);

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
}
