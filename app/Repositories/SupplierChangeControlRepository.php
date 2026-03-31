<?php

namespace App\Repositories;

use App\Models\SupplierChangeControl;
use App\Repositories\Contracts\SupplierChangeControlRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class SupplierChangeControlRepository extends BaseRepository implements SupplierChangeControlRepositoryInterface
{
    public function __construct(SupplierChangeControl $supplierChangeControl)
    {
        parent::__construct($supplierChangeControl);
    }

    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->with(['creator', 'updater'])
            ->withCount('events');

        if ($supplierName = Arr::get($filters, 'supplier_name')) {
            $query->where('supplier_name', 'like', '%' . $supplierName . '%');
        }

        if ($status = Arr::get($filters, 'status')) {
            $query->where('status', $status);
        }

        if ($currentStep = Arr::get($filters, 'current_step')) {
            $query->where('current_step', (int) $currentStep);
        }

        if ($search = Arr::get($filters, 'search') ?? Arr::get($filters, 'q')) {
            $query->where(function ($subQuery) use ($search) {
                $subQuery
                    ->where('supplier_name', 'like', '%' . $search . '%')
                    ->orWhere('address', 'like', '%' . $search . '%')
                    ->orWhere('tel_fax', 'like', '%' . $search . '%')
                    ->orWhere('notes', 'like', '%' . $search . '%');
            });
        }

        $sortable = ['id', 'supplier_name', 'status', 'current_step', 'created_at', 'updated_at'];
        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $orderBy = in_array($orderBy, $sortable, true) ? $orderBy : 'id';
        $direction = strtolower((string) $direction) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($orderBy, $direction)->paginate($limit, ['*'], 'page', $page);
    }
}

