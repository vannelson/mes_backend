<?php

namespace App\Services\Contracts;

use Illuminate\Http\UploadedFile;

interface PackingServiceInterface
{
    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array;

    public function detail(int $id): array;

    public function create(array $data, ?UploadedFile $image = null, ?UploadedFile $design = null): array;

    public function createBatch(array $packings): array;

    public function createBatchWithFiles(array $packings, array $images = [], array $designs = []): array;

    public function update(int $id, array $data, ?UploadedFile $image = null, ?UploadedFile $design = null): array;

    public function delete(int $id): bool;
}
