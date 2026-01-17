<?php

namespace App\Repositories;

use App\Models\Diecut;
use App\Repositories\Contracts\DiecutRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class DiecutRepository extends BaseRepository implements DiecutRepositoryInterface
{
    public function __construct(Diecut $diecut)
    {
        parent::__construct($diecut);
    }

    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        if ($batchNumber = Arr::get($filters, 'batch_number')) {
            $query->where('batch_number', 'LIKE', "%{$batchNumber}%");
        }

        if ($diecutNo = Arr::get($filters, 'diecut_no')) {
            $query->where('diecut_no', 'LIKE', "%{$diecutNo}%");
        }

        if ($diecutType = Arr::get($filters, 'diecut_type')) {
            $query->where('diecut_type', 'LIKE', "%{$diecutType}%");
        }

        if ($search = Arr::get($filters, 'q')) {
            $query->where(function ($q) use ($search) {
                $q->where('diecut_no', 'LIKE', "%{$search}%")
                    ->orWhere('diecut_type', 'LIKE', "%{$search}%");
            });
        }

        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        switch ($orderBy) {
            case 'diecut_no':
            case 'diecut_type':
            case 'id':
                $query->orderBy($orderBy, $direction);
                break;
            default:
                $query->orderBy('id', 'desc');
        }

        return $query->paginate($limit, ['*'], 'page', $page);
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
}
