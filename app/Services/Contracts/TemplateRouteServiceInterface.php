<?php

namespace App\Services\Contracts;

interface TemplateRouteServiceInterface
{
    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array;

    public function detail(int $id): array;

    public function create(array $data): array;

    public function update(int $id, array $data): array;

    public function delete(int $id): bool;

    public function importTemplates(array $templates, int $userId): array;

    public function listOrderedByWorkOrders(int $limit = 10, int $page = 1): array;

    public function replaceBatch(string $batchNumber, array $templates): array;
}
