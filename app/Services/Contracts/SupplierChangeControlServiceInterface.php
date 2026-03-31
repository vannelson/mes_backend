<?php

namespace App\Services\Contracts;

use Illuminate\Http\UploadedFile;

interface SupplierChangeControlServiceInterface
{
    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array;

    public function detail(int $id): array;

    public function create(array $data, ?UploadedFile $attachment = null, ?string $eventNote = null, ?int $userId = null): array;

    public function update(int $id, array $data, ?UploadedFile $attachment = null, ?string $eventNote = null, ?int $userId = null): array;

    public function updateStep(int $id, int $step, ?string $status = null, ?string $note = null, ?int $userId = null): array;

    public function delete(int $id): bool;
}

