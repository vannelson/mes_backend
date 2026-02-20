<?php

namespace App\Repositories;

use App\Models\TemplateRoute;
use App\Repositories\Contracts\TemplateRouteRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class TemplateRouteRepository extends BaseRepository implements TemplateRouteRepositoryInterface
{
    public function __construct(TemplateRoute $templateRoute)
    {
        parent::__construct($templateRoute);
    }

    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->applyFilters($filters, $order);

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function listAll(array $filters = [], array $order = []): Collection
    {
        $query = $this->applyFilters($filters, $order);

        return $query->get();
    }

    public function options(
        array $filters = [],
        array $order = [],
        int $limit = 10,
        int $page = 1,
        bool $withWorkOrdersCount = false
    ): LengthAwarePaginator
    {
        $query = $this->applyFilters($filters, $order, false)
            ->select([
                'id',
                'template',
                'batch_number',
                'sheet',
                'metadata',
                'created_at',
                'updated_at',
            ]);

        $withWorkOrders = Arr::get($filters, 'with_work_orders');
        if (!is_null($withWorkOrders)) {
            $flag = filter_var($withWorkOrders, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]);
            if ($flag === null) {
                $flag = (bool) $withWorkOrders;
            }
            if ($flag) {
                $query->whereHas('workOrders');
            }
        }

        if ($withWorkOrdersCount) {
            $query->withCount('workOrders');
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function findByTemplate(string $template): ?TemplateRoute
    {
        return $this->model->newQuery()->where('template', $template)->first();
    }

    public function orderedByWorkOrders(int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->with([
                'manager',
                'workOrders' => function ($query) {
                    $query->select([
                        'id',
                        'template_route_id',
                        'work_order_no',
                        'customer_code',
                        'customer_name',
                        'metadata',
                        'created_at',
                        'updated_at',
                    ]);
                },
            ])
            ->withCount('workOrders')
            ->orderByDesc('work_orders_count')
            ->orderBy('id')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function deleteByBatch(string $batchNumber): int
    {
        return $this->model->newQuery()
            ->where('batch_number', $batchNumber)
            ->delete();
    }

    protected function applyFilters(array $filters = [], array $order = [], bool $withManager = true)
    {
        $query = $this->model->newQuery();

        if ($withManager) {
            $query->with('manager');
        }

        if ($batchNumber = Arr::get($filters, 'batch_number')) {
            $query->where('batch_number', $batchNumber);
        }

        if ($template = Arr::get($filters, 'template')) {
            $query->where('template', 'LIKE', "%{$template}%");
        }

        if ($userId = Arr::get($filters, 'user_id')) {
            $query->where('user_id', $userId);
        }

        if ($uuid = Arr::get($filters, 'uuid')) {
            $query->where('uuid', $uuid);
        }

        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $query->orderBy($orderBy, $direction);

        return $query;
    }
}
