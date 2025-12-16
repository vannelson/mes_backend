<?php

namespace App\Http\Controllers;

use App\Http\Requests\Customer\CustomerStoreRequest;
use App\Http\Requests\Customer\CustomerUpdateRequest;
use App\Services\Contracts\CustomerServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustomerController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected CustomerServiceInterface $customerService
    ) {
    }

    /**
     * List Customers
     * @param Request $request
     */
    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        $order = Arr::get($request->all(), 'order', ['id', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 10);
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->customerService->getList($filters, $order, $limit, $page);

            return $this->successPagination('Customers retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load customers.', 500);
        }
    }

    /**
     * Create Customer
     * @param CustomerStoreRequest $request
     */
    public function store(CustomerStoreRequest $request): JsonResponse
    {
        try {
            $customer = $this->customerService->create($request->validated());

            return $this->success('Customer created successfully!', $customer);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to create customer.', 500);
        }
    }

    /**
     * Show Customer
     * @param int $id
     */
    public function show(int $id): JsonResponse
    {
        try {
            $customer = $this->customerService->detail($id);

            return $this->success('Customer retrieved successfully!', $customer);
        } catch (Throwable $e) {
            return $this->error('Failed to load customer.', 500);
        }
    }

    /**
     * Update Customer
     * @param CustomerUpdateRequest $request
     * @param int $id
     */
    public function update(CustomerUpdateRequest $request, int $id): JsonResponse
    {
        try {
            $updated = $this->customerService->update($id, $request->validated());

            return $updated
                ? $this->success('Customer updated successfully!')
                : $this->error('Nothing to update.', 422);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to update customer.', 500);
        }
    }

    /**
     * Delete Customer
     * @param int $id
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->customerService->delete($id);

            return $this->success('Customer deleted successfully!');
        } catch (Throwable $e) {
            return $this->error('Failed to delete customer.', 500);
        }
    }
}
