<?php

namespace App\Services\Contracts;

use Illuminate\Http\UploadedFile;

interface WorkOrderServiceInterface
{
    /**
     * List work orders with pagination.
     */
    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array;

    /**
     * List work order options for dropdowns.
     */
    public function getOptions(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array;

    /**
     * Retrieve single work order.
     */
    public function detail(int $id): array;

    /**
     * Retrieve single work order by column.
     */
    public function detailBy(string $column, mixed $value): array;

    /**
     * Create Work Order
     */
    public function create(array $data, array $evidenceImages = []): array;

    /**
     * Create many work orders in a single request.
     */
    public function createBatch(array $workOrders): array;

    /**
     * Update Work Order
     */
    public function update(int $id, array $data, array $evidenceImages = []): bool;

    /**
     * Sync work order operator assignments per route.
     */
    public function syncAssignments(int $id, array $routes): array;

    /**
     * Delete Work Order
     */
    public function delete(int $id): bool;

    /**
     * Import work orders from spreadsheet.
     */
    public function importFromSpreadsheet(UploadedFile $file, string $sheet): array;

    /**
     * Link template routes to work orders using customer_part_number_ref references.
     */
    public function linkTemplateRoutesByReference(
        ?string $reference = null,
        ?string $batchNumber = null
    ): array;

    /**
     * Return all work orders that have a linked template route.
     */
    public function listWithActiveTemplateRoutes(): array;

    /**
     * Paginated list of work orders filtered by batch number.
     */
    public function listByBatch(string $batchNumber, int $limit = 10, int $page = 1): array;

    /**
     * Replace work orders for a specific batch (delete then re-import).
     */
    public function replaceBatch(string $batchNumber, array $workOrders): array;

    /**
     * Paginated list of work orders filtered by template route batch number.
     */
    public function listByTemplateRouteBatch(string $batchNumber, int $limit = 10, int $page = 1): array;

    /**
     * Count work orders linked to template routes belonging to a batch number.
     */
    public function countByTemplateRouteBatch(string $batchNumber): int;

    /**
     * Provide aggregated work order summary metrics for dashboards.
     */
    public function summary(array $options = []): array;

    /**
     * List work orders with WIP status, filters, and pagination.
     */
    public function listWip(
        array $filters = [],
        int $limit = 10,
        int $page = 1,
        ?string $sortBy = null,
        ?string $sortDir = null
    ): array;
}

