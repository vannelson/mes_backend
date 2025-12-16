<?php

namespace App\Repositories\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface WorkOrderRepositoryInterface extends RepositoryInterface
{
    /**
     * Retrieve paginated work orders with optional filters and ordering.
     */
    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator;
}
