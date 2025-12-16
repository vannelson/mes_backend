<?php

namespace App\Services\Contracts;

interface UserServiceInterface
{
    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array;

    public function detail(int $id): array;

    public function register(array $data): array;

    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;
}

