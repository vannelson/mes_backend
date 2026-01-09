<?php

namespace App\Repositories;

use App\Models\Bom;
use App\Repositories\Contracts\BomRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class BomRepository extends BaseRepository implements BomRepositoryInterface
{
    public function __construct(Bom $bom)
    {
        parent::__construct($bom);
    }

    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        if ($batchNumber = Arr::get($filters, 'batch_number')) {
            $query->where('batch_number', 'LIKE', "%{$batchNumber}%");
        }

        if ($customerCode = Arr::get($filters, 'customer_code')) {
            $query->where('customer_code', 'LIKE', "%{$customerCode}%");
        }

        if ($partNo = Arr::get($filters, 'part_no')) {
            $query->where('part_no', 'LIKE', "%{$partNo}%");
        }

        if ($materialCode = Arr::get($filters, 'material_code')) {
            $query->where(function ($q) use ($materialCode) {
                $q->where('material_1_code', 'LIKE', "%{$materialCode}%")
                    ->orWhere('material_2_code', 'LIKE', "%{$materialCode}%")
                    ->orWhere('material_3_code', 'LIKE', "%{$materialCode}%")
                    ->orWhere('material_4_code', 'LIKE', "%{$materialCode}%");
            });
        }

        if ($description = Arr::get($filters, 'description')) {
            $query->where('description', 'LIKE', "%{$description}%");
        }

        if ($search = Arr::get($filters, 'q')) {
            $query->where(function ($q) use ($search) {
                $q->where('customer_code', 'LIKE', "%{$search}%")
                    ->orWhere('part_no', 'LIKE', "%{$search}%")
                    ->orWhere('material_1_code', 'LIKE', "%{$search}%")
                    ->orWhere('material_2_code', 'LIKE', "%{$search}%")
                    ->orWhere('material_3_code', 'LIKE', "%{$search}%")
                    ->orWhere('material_4_code', 'LIKE', "%{$search}%");
            });
        }

        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        switch ($orderBy) {
            case 'customer_code':
            case 'part_no':
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
