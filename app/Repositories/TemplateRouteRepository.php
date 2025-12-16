<?php

namespace App\Repositories;

use App\Models\TemplateRoute;
use App\Repositories\Contracts\TemplateRouteRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class TemplateRouteRepository extends BaseRepository implements TemplateRouteRepositoryInterface
{
    public function __construct(TemplateRoute $templateRoute)
    {
        parent::__construct($templateRoute);
    }

    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->with('manager');

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

        return $query->paginate($limit, ['*'], 'page', $page);
    }
}
