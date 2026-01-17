<?php

namespace App\Services\Contracts;

interface DiecutServiceInterface
{
    /**
     * List diecut rows with pagination.
     */
    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array;

    /**
     * Create many diecut rows in a single request.
     */
    public function createBatch(array $diecuts): array;

    /**
     * Paginated list of diecut rows filtered by batch number.
     */
    public function listByBatch(string $batchNumber, int $limit = 10, int $page = 1): array;

    /**
     * Replace diecut rows for a specific batch (delete then re-import).
     */
    public function replaceBatch(string $batchNumber, array $diecuts): array;
}
