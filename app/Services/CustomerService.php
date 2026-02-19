<?php

namespace App\Services;

use App\Http\Resources\Customer\CustomerResource;
use App\Models\WorkOrder;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Services\Contracts\CustomerServiceInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CustomerService implements CustomerServiceInterface
{
    public function __construct(
        protected CustomerRepositoryInterface $customerRepository
    ) {
    }

    /**
     * Retrieve customer list with pagination support.
     */
    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        return CustomerResource::collection(
            $this->customerRepository->listing($filters, $order, $limit, $page)
        )->response()->getData(true);
    }

    public function getOptions(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        $paginator = $this->customerRepository->options($filters, $order, $limit, $page);
        $items = $paginator->getCollection()->map(static function ($customer): array {
            return [
                'id' => $customer->id,
                'customer_name' => $customer->customer_name,
                'customer_code' => $customer->customer_code,
            ];
        })->values();

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function getTopByWorkOrders(array $filters = [], int $limit = 5): array
    {
        $query = WorkOrder::query()
            ->select(
                'customer_code',
                'customer_name',
                DB::raw('COUNT(*) as total')
            )
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->whereNotNull('customer_name')
                        ->where('customer_name', '!=', '');
                })->orWhere(function ($inner) {
                    $inner->whereNotNull('customer_code')
                        ->where('customer_code', '!=', '');
                });
            });

        if ($dueFrom = Arr::get($filters, 'production_due_from')) {
            $query->whereDate('production_due_date', '>=', $dueFrom);
        }

        if ($dueTo = Arr::get($filters, 'production_due_to')) {
            $query->whereDate('production_due_date', '<=', $dueTo);
        }

        if ($statusFilter = Arr::get($filters, 'status')) {
            $query->where('status', $statusFilter);
        }

        $scheduleFrom = Arr::get($filters, 'schedule_from');
        $scheduleTo = Arr::get($filters, 'schedule_to');
        if ($scheduleFrom || $scheduleTo) {
            $query->where(function ($range) use ($scheduleFrom, $scheduleTo) {
                if ($scheduleTo) {
                    $range->whereDate('order_date', '<=', $scheduleTo);
                }
                if ($scheduleFrom) {
                    $range->where(function ($overlap) use ($scheduleFrom) {
                        $overlap->whereDate('production_due_date', '>=', $scheduleFrom)
                            ->orWhereDate('order_date', '>=', $scheduleFrom);
                    });
                }
            });
        }

        return $query
            ->groupBy('customer_code', 'customer_name')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(static function ($row): array {
                return [
                    'customer_code' => $row->customer_code,
                    'customer_name' => $row->customer_name,
                    'work_orders' => (int) $row->total,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Load single customer by id.
     */
    public function detail(int $id): array
    {
        return (new CustomerResource($this->customerRepository->findById($id)))->response()->getData(true);
    }

    /**
     * Create Customer
     * @param array $data
     */
    public function create(array $data): array
    {
        $customer = $this->customerRepository->create($data);

        return (new CustomerResource($customer))->response()->getData(true);
    }

    /**
     * Update Customer
     * @param int $id
     * @param array $data
     */
    public function update(int $id, array $data): bool
    {
        return (bool) $this->customerRepository->update($id, $data);
    }

    /**
     * Delete Customer
     */
    public function delete(int $id): bool
    {
        return $this->customerRepository->delete($id);
    }
}
