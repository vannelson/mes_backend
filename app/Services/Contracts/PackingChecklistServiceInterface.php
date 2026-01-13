<?php

namespace App\Services\Contracts;

use Illuminate\Http\UploadedFile;

interface PackingChecklistServiceInterface
{
    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array;

    public function detail(int $id): array;

    public function create(array $data, ?UploadedFile $ulLabelImage = null, ?UploadedFile $cartonLabelImage = null): array;

    public function update(int $id, array $data, ?UploadedFile $ulLabelImage = null, ?UploadedFile $cartonLabelImage = null): array;

    public function delete(int $id): bool;
}
