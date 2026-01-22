<?php

namespace App\Services\Contracts;

use Illuminate\Http\UploadedFile;

interface WorkOrderEvidenceServiceInterface
{
    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array;

    /**
     * @param array $data
     * @param UploadedFile[] $images
     */
    public function create(array $data, array $images = []): array;

    public function delete(int $id): bool;
}
