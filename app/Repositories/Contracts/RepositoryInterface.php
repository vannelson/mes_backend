<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Interface RepositoryInterface defines common CRUD contracts.
 */
interface RepositoryInterface
{
    public function findById(int $id): Model;

    public function create(array $data): Model;

    public function update(int $id, array $data): int;

    public function updateAndGet(int $id, array $data): Model;

    public function delete(int $id): bool;
}

