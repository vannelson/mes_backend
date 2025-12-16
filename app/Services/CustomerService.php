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
