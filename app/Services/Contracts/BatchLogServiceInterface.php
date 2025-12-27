<?php

namespace App\Services\Contracts;

interface BatchLogServiceInterface
{
    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array;

    public function detail(int $id): array;

    public function create(array $data): array;

    public function update(int $id, array $data): array;

    public function delete(int $id): bool;
}
