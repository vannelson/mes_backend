<?php

namespace App\Repositories;

use App\Models\PackingChecklist;
use App\Repositories\Contracts\PackingChecklistRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class PackingChecklistRepository extends BaseRepository implements PackingChecklistRepositoryInterface
{
    public function __construct(PackingChecklist $packingChecklist)
    {
        parent::__construct($packingChecklist);
    }

    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        if ($workOrderNo = Arr::get($filters, 'work_order_no')) {
            $query->where('work_order_no', 'LIKE', "%{$workOrderNo}%");
        }

        if ($wdPartNo = Arr::get($filters, 'wd_part_no')) {
            $query->where('wd_part_no', 'LIKE', "%{$wdPartNo}%");
        }

        if ($userId = Arr::get($filters, 'user_id')) {
            $query->where('user_id', $userId);
        }

        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        switch ($orderBy) {
            case 'work_order_no':
            case 'wd_part_no':
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

    public function findByWdPartNo(string $wdPartNo)
    {
        return $this->model->newQuery()->where('wd_part_no', $wdPartNo)->first();
    }
}
