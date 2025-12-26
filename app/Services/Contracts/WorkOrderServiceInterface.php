<?php

namespace App\Services\Contracts;

interface WorkOrderServiceInterface
{
    /**
     * List work orders with pagination.
     */
    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array;

    /**
     * List work order options for dropdowns.
     */
    public function getOptions(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array;

    /**
     * Retrieve single work order.
     */
    public function detail(int $id): array;

    /**
     * Retrieve single work order by column.
     */
    public function detailBy(string $column, mixed $value): array;

    /**
     * Create Work Order
     */
    public function create(array $data): array;

    /**
     * Update Work Order
     */
    public function update(int $id, array $data): bool;

    /**
     * Delete Work Order
     */
    public function delete(int $id): bool;
}
