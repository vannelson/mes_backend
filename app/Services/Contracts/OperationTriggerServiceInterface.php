<?php

namespace App\Services\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface OperationTriggerServiceInterface
{
    public function getList(array $filters = [], array $order = [], int $limit = 20, int $page = 1): LengthAwarePaginator;

    public function detail(int $id): array;

    public function create(array $data, ?int $actorId = null): array;

    public function update(int $id, array $data, ?int $actorId = null): array;

    public function publish(int $id, ?int $actorId = null): array;

    public function disable(int $id, ?int $actorId = null): array;

    public function simulate(int $id, array $payload = []): array;
}
