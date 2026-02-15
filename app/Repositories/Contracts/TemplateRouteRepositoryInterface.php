<?php

namespace App\Repositories\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface TemplateRouteRepositoryInterface extends RepositoryInterface
{
    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator;

    public function listAll(array $filters = [], array $order = []): Collection;

    public function options(
        array $filters = [],
        array $order = [],
        int $limit = 10,
        int $page = 1,
        bool $withWorkOrdersCount = false
    ): LengthAwarePaginator;

    public function findByTemplate(string $template): ?\App\Models\TemplateRoute;

    public function orderedByWorkOrders(int $limit = 10, int $page = 1): LengthAwarePaginator;

    public function deleteByBatch(string $batchNumber): int;
}
