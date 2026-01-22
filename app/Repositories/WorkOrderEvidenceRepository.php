<?php

namespace App\Repositories;

use App\Models\WorkOrderEvidence;
use App\Repositories\Contracts\WorkOrderEvidenceRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class WorkOrderEvidenceRepository extends BaseRepository implements WorkOrderEvidenceRepositoryInterface
{
    public function __construct(WorkOrderEvidence $workOrderEvidence)
    {
        parent::__construct($workOrderEvidence);
    }

    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        if ($workOrderNo = Arr::get($filters, 'work_order_no')) {
            $query->where('work_order_no', $workOrderNo);
        }

        if ($routeName = Arr::get($filters, 'route_name')) {
            $query->where('route_name', $routeName);
        }

        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        switch ($orderBy) {
            case 'work_order_no':
            case 'route_name':
            case 'created_at':
            case 'updated_at':
            case 'id':
                $query->orderBy($orderBy, $direction);
                break;
            default:
                $query->orderBy('id', 'desc');
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }
}
