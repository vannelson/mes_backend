<?php

namespace App\Services\Contracts;

use Illuminate\Http\UploadedFile;

interface CalibrationMasterServiceInterface
{
    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array;

    public function detail(int $id): array;

    public function create(array $data): array;

    public function update(int $id, array $data): array;

    public function delete(int $id): bool;

    public function insights(int $nearDays = 30): array;

    public function updateStatus(int $id, ?string $calStatus): array;

    public function getHistory(int $id): array;

    public function addHistory(int $id, array $data, ?UploadedFile $certFile, ?int $loggedBy): array;

    public function uploadImage(int $id, UploadedFile $file): array;

    public function deleteImage(int $id, int $imageId): void;
}
