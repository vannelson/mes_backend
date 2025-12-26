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

        if ($documentType = Arr::get($filters, 'document_type')) {
            $query->where('document_type', $documentType);
        }

        if ($customerId = Arr::get($filters, 'customer_id')) {
            $query->where('customer_id', $customerId);
        }

        if ($priorityType = Arr::get($filters, 'priority_type')) {
            $query->where('priority_type', $priorityType);
        }

        if ($operatorCode = Arr::get($filters, 'operator_code')) {
            $query->where('operator_code', 'LIKE', "%{$operatorCode}%");
        }

        if ($from = Arr::get($filters, 'date_from')) {
            $query->whereDate('date', '>=', $from);
        }

        if ($to = Arr::get($filters, 'date_to')) {
            $query->whereDate('date', '<=', $to);
        }

        if ($size = Arr::get($filters, 'size')) {
            $query->where('size', $size);
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
