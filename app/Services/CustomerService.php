<?php

namespace App\Services;

use App\Http\Resources\Customer\CustomerResource;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Services\Contracts\CustomerServiceInterface;

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
