<?php

namespace App\Services\Contracts;

interface CustomerServiceInterface
{
    /**
     * List customers with pagination.
     */
    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array;

    /**
     * List customer options for dropdowns.
     */
    public function getOptions(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array;

    /**
     * Retrieve single customer detail.
     */
    public function detail(int $id): array;

    /**
     * Create Customer
     * @param array $data
     */
    public function create(array $data): array;

    /**
     * Update Customer
     * @param int $id
     * @param array $data
     */
    public function update(int $id, array $data): bool;

    /**
     * Delete Customer
     */
    public function delete(int $id): bool;
}
