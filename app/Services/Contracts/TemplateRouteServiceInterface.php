<?php

namespace App\Services\Contracts;

interface TemplateRouteServiceInterface
{
    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array;

    public function getOptions(
        array $filters = [],
        array $order = [],
        int $limit = 10,
        int $page = 1,
        bool $withWorkOrdersCount = false
    ): array;

    public function getTopUsed(array $filters = [], int $limit = 5): array;

    public function detail(int $id): array;

    public function create(array $data): array;

    public function update(int $id, array $data): array;

    public function createVersion(int $id, array $data): array;

    public function delete(int $id): bool;

    public function importTemplates(array $templates, int $userId): array;

    public function listOrderedByWorkOrders(int $limit = 10, int $page = 1): array;

    public function replaceBatch(string $batchNumber, array $templates): array;

    public function listVersionsByCustomerPartNo(string $customerPartNo, bool $latestOnly = false): array;
}
