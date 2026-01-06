<?php

namespace App\Repositories\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface TemplateRouteRepositoryInterface extends RepositoryInterface
{
    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator;

    public function findByTemplate(string $template): ?\App\Models\TemplateRoute;

    public function orderedByWorkOrders(int $limit = 10, int $page = 1): LengthAwarePaginator;
}
