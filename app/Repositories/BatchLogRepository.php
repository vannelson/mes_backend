<?php

namespace App\Repositories;

use App\Models\BatchLog;
use App\Repositories\Contracts\BatchLogRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class BatchLogRepository extends BaseRepository implements BatchLogRepositoryInterface
{
    public function __construct(BatchLog $batchLog)
    {
        parent::__construct($batchLog);
    }

    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->with('user');

        if ($batchNo = Arr::get($filters, 'batch_no')) {
            $query->where('batch_no', 'LIKE', "%{$batchNo}%");
        }

        if ($operator = Arr::get($filters, 'operator')) {
            $query->where('operator', 'LIKE', "%{$operator}%");
        }

        if ($userId = Arr::get($filters, 'user_id')) {
            $query->where('user_id', $userId);
        }

        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $query->orderBy($orderBy, $direction);

        return $query->paginate($limit, ['*'], 'page', $page);
    }
}
