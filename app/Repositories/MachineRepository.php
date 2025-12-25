<?php

namespace App\Repositories;

use App\Models\Machine;
use App\Repositories\Contracts\MachineRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class MachineRepository extends BaseRepository implements MachineRepositoryInterface
{
    public function __construct(Machine $machine)
    {
        parent::__construct($machine);
    }

    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        if ($area = Arr::get($filters, 'production_area')) {
            $query->where('production_area', 'LIKE', "%{$area}%");
        }

        if ($type = Arr::get($filters, 'machine_type')) {
            $query->where('machine_type', 'LIKE', "%{$type}%");
        }

        if ($machineNo = Arr::get($filters, 'machine_no')) {
            $query->where('machine_no', 'LIKE', "%{$machineNo}%");
        }

        if ($costCenter = Arr::get($filters, 'cost_center_new')) {
            $query->where('cost_center_new', 'LIKE', "%{$costCenter}%");
        }

        if ($search = Arr::get($filters, 'q')) {
            $query->where(function ($q) use ($search) {
                $q->where('machine_type', 'LIKE', "%{$search}%")
                    ->orWhere('machine_no', 'LIKE', "%{$search}%")
                    ->orWhere('production_area', 'LIKE', "%{$search}%");
            });
        }

        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $query->orderBy($orderBy, $direction);

        return $query->paginate($limit, ['*'], 'page', $page);
    }
}
