<?php

namespace App\Repositories;

use App\Models\WorkOrderComment;
use App\Repositories\Contracts\WorkOrderCommentRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class WorkOrderCommentRepository extends BaseRepository implements WorkOrderCommentRepositoryInterface
{
    public function __construct(WorkOrderComment $workOrderComment)
    {
        parent::__construct($workOrderComment);
    }

    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->with(['user']);

        if ($workOrderId = Arr::get($filters, 'work_order_id')) {
            $query->where('work_order_id', $workOrderId);
        }

        if ($threadId = Arr::get($filters, 'thread_id')) {
            $query->where('thread_id', $threadId);
        }

        if ($parentId = Arr::get($filters, 'parent_id')) {
            $query->where('parent_id', $parentId);
        }

        if ($type = Arr::get($filters, 'type')) {
            $query->where('type', $type);
        }

        if ($visibility = Arr::get($filters, 'visibility')) {
            $query->where('visibility', $visibility);
        }

        if ($status = Arr::get($filters, 'status')) {
            $query->where('status', $status);
        }

        if ($userId = Arr::get($filters, 'user_id')) {
            $query->where('user_id', $userId);
        }

        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        switch ($orderBy) {
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
