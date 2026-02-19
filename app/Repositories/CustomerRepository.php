<?php

namespace App\Repositories;

use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CustomerRepository extends BaseRepository implements CustomerRepositoryInterface
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        if ($name = Arr::get($filters, 'customer_name')) {
            $query->where('customer_name', 'LIKE', "%{$name}%");
        }

        if ($code = Arr::get($filters, 'customer_code')) {
            $query->where('customer_code', 'LIKE', "%{$code}%");
        }

        if ($status = Arr::get($filters, 'status')) {
            $query->where('status', $status);
        }

        if ($country = Arr::get($filters, 'country')) {
            $query->where('country', 'LIKE', "%{$country}%");
        }

        if ($city = Arr::get($filters, 'city')) {
            $query->where('city', 'LIKE', "%{$city}%");
        }

        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $query->orderBy($orderBy, $direction);

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function options(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->select(['id', 'customer_name', 'customer_code']);

        $withWorkOrders = Arr::get($filters, 'with_work_orders');
        if (!is_null($withWorkOrders)) {
            $flag = filter_var($withWorkOrders, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]);
            if ($flag === null) {
                $flag = (bool) $withWorkOrders;
            }
            if ($flag) {
                $query->whereExists(function ($subQuery) {
                    $subQuery->select(DB::raw(1))
                        ->from('work_orders')
                        ->where(function ($match) {
                            $match->whereColumn('work_orders.customer_id', 'customers.id')
                                ->orWhere(function ($codeMatch) {
                                    $codeMatch->whereNull('work_orders.customer_id')
                                        ->whereNotNull('customers.customer_code')
                                        ->where('customers.customer_code', '!=', '')
                                        ->whereColumn('work_orders.customer_code', 'customers.customer_code');
                                });
                        });
                });
            }
        }

        if ($search = Arr::get($filters, 'search')) {
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('customer_name', 'LIKE', "%{$search}%")
                    ->orWhere('customer_code', 'LIKE', "%{$search}%");
            });
        }

        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $query->orderBy($orderBy, $direction);

        return $query->paginate($limit, ['id', 'customer_name', 'customer_code'], 'page', $page);
    }
}
