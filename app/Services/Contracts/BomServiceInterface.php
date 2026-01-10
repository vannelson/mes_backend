<?php

namespace App\Services\Contracts;

interface BomServiceInterface
{
    /**
     * List BOM rows with pagination.
     */
    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array;

    /**
     * Create many BOM rows in a single request.
     */
    public function createBatch(array $boms): array;

    /**
     * Paginated list of BOM rows filtered by batch number.
     */
    public function listByBatch(string $batchNumber, int $limit = 10, int $page = 1): array;

    /**
     * Replace BOM rows for a specific batch (delete then re-import).
     */
    public function replaceBatch(string $batchNumber, array $boms): array;
}
