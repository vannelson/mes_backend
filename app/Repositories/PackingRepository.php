<?php

namespace App\Repositories;

use App\Models\Packing;
use App\Repositories\Contracts\PackingRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class PackingRepository extends BaseRepository implements PackingRepositoryInterface
{
    public function __construct(Packing $packing)
    {
        parent::__construct($packing);
    }

    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->with(['customer', 'packingChecklist']);

        $stringFilters = [
            'wd_part_no',
            'material',
            'description',
            'batch_number',
            'image',
            'design',
            'shipping_location',
            'customer_code',
            'box_size',
            'core_label_left',
            'core_label_right',
            'hm_no',
            'ul_label_no',
            'cas',
            'code_1',
            'underline_code',
            'colour_code',
            'wd_revision',
            'revised_by_pic',
            'important',
            'remarks',
        ];

        foreach ($stringFilters as $field) {
            $value = Arr::get($filters, $field);
            if ($value !== null && $value !== '') {
                $query->where($field, 'LIKE', '%' . $value . '%');
            }
        }

        $qty = Arr::get($filters, 'qty_per_box');
        if ($qty !== null && $qty !== '') {
            $query->where('qty_per_box', 'LIKE', '%' . $qty . '%');
        }

        $rolls = Arr::get($filters, 'rolls_per_box');
        if ($rolls !== null && $rolls !== '') {
            $query->where('rolls_per_box', 'LIKE', '%' . $rolls . '%');
        }

        if ($date = Arr::get($filters, 'date_of_revised')) {
            $query->whereDate('date_of_revised', $date);
        }

        if ($search = Arr::get($filters, 'search') ?? Arr::get($filters, 'q')) {
            $query->where(function ($q) use ($search, $stringFilters) {
                foreach ($stringFilters as $field) {
                    $q->orWhere($field, 'LIKE', '%' . $search . '%');
                }
            });
        }

        $sortable = array_merge(['id', 'created_at', 'updated_at'], $stringFilters, [
            'qty_per_box',
            'rolls_per_box',
            'important',
            'date_of_revised',
        ]);

        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $orderBy = in_array($orderBy, $sortable, true) ? $orderBy : 'id';
        $direction = strtolower((string) $direction) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($orderBy, $direction)->paginate($limit, ['*'], 'page', $page);
    }
}
