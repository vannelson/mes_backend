<?php

namespace App\Repositories\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface WorkOrderRepositoryInterface extends RepositoryInterface
{
    /**
     * Retrieve paginated work orders with optional filters and ordering.
     */
    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator;

    /**
     * Retrieve work order options for dropdowns.
     */
    public function options(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator;

    public function findByColumn(string $column, mixed $value);

    /**
     * Fetch all work orders that are linked to a template route.
     */
    public function withTemplateRoutes(): Collection;

    /**
     * Count work orders belonging to a specific batch number.
     */
    public function countByBatch(string $batchNumber): int;

    /**
     * Delete work orders belonging to a specific batch number.
     */
    public function deleteByBatch(string $batchNumber): int;

    /**
     * Count work orders whose template routes belong to a batch.
     */
    public function countByTemplateRouteBatch(string $batchNumber): int;
}
