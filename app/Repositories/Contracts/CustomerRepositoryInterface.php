<?php

namespace App\Repositories\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface CustomerRepositoryInterface extends RepositoryInterface
{
    /**
     * Retrieve paginated customers list with optional filters and ordering.
     */
    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator;

    /**
     * Retrieve customer options for dropdowns.
     */
    public function options(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator;
}
