<?php

namespace App\Services;

use App\Http\Resources\WorkOrder\WorkOrderResource;
use App\Models\Customer;
use App\Models\Packing;
use App\Models\PackingChecklist;
use App\Models\TemplateRoute;
use App\Models\User;
use App\Models\UserWorkOrder;
use App\Models\WorkOrder;
use App\Repositories\Contracts\WorkOrderRepositoryInterface;
use App\Services\Contracts\WorkOrderServiceInterface;
use App\Services\WorkOrderImportService;
use App\Services\FirebaseRealtimeService;
use App\Services\Contracts\OperationTriggerServiceInterface;
use App\Services\WorkOrderNotificationService;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Throwable;

class WorkOrderService implements WorkOrderServiceInterface
{
    private const VIRTUALIZATION_MAX_RANGE_DAYS = 7;
    private const VIRTUALIZATION_MAX_ORDERS = 128;

    public function __construct(
        protected WorkOrderRepositoryInterface $workOrderRepository,
        protected WorkOrderImportService $workOrderImportService,
        protected FirebaseRealtimeService $firebaseRealtimeService,
        protected WorkOrderNotificationService $notificationService,
        protected OperationTriggerServiceInterface $triggerService
    ) {
    }

    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        return WorkOrderResource::collection(
            $this->workOrderRepository->listing($filters, $order, $limit, $page)
        )->response()->getData(true);
    }

    public function getOptions(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        $paginator = $this->workOrderRepository->options($filters, $order, $limit, $page);
        $items = $paginator->getCollection()->map(static function ($workOrder): array {
            return [
                'id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
            ];
        })->values();

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function listWip(
        array $filters = [],
        int $limit = 10,
        int $page = 1,
        ?string $sortBy = null,
        ?string $sortDir = null
    ): array {
        $limit = max(1, $limit);
        $page = max(1, $page);

        $select = [
            'id',
            'work_order_no',
            'customer_part_number',
            'customer_code',
            'customer_name',
            'metadata',
            'updated_at',
        ];
        if (Schema::hasColumn('work_orders', 'is_released')) {
            $select[] = 'is_released';
        }
        if (Schema::hasColumn('work_orders', 'template_route_id')) {
            $select[] = 'template_route_id';
        }

        $query = WorkOrder::query()->select($select);

        if ($workOrderNo = Arr::get($filters, 'work_order_no')) {
            $query->where('work_order_no', 'LIKE', "%{$workOrderNo}%");
        }

        if ($partNumber = Arr::get($filters, 'customer_part_number')) {
            $query->where('customer_part_number', 'LIKE', "%{$partNumber}%");
        }

        if ($customerName = Arr::get($filters, 'customer_name')) {
            $query->where('customer_name', 'LIKE', "%{$customerName}%");
        }

        if ($customerCode = Arr::get($filters, 'customer_code')) {
            $query->where('customer_code', 'LIKE', "%{$customerCode}%");
        }

        $orders = $query->orderByDesc('updated_at')->get();

        $workOrderNos = $orders->pluck('work_order_no')->filter()->values();
        $packingSet = $workOrderNos->isEmpty()
            ? collect()
            : PackingChecklist::query()
                ->whereIn('work_order_no', $workOrderNos)
                ->pluck('work_order_no')
                ->flip();
        $items = $orders->map(function (WorkOrder $order) use ($packingSet): array {
            $metadata = $this->normalizeMetadata($order->metadata);
            $routes = $this->extractRoutes($metadata);
            $routeStats = $this->resolveRouteCompletionStats($routes);
            $statusRaw = strtolower(trim((string) Arr::get($metadata, 'state.status', '')));
            $isReleased = $this->resolveIsReleased($order, $statusRaw);
            $hasPacking = $order->work_order_no
                ? $packingSet->has($order->work_order_no)
                : false;
            $statusLabel = $this->resolveWorkflowStatus($order, $metadata, $routes, $hasPacking);
            $statusKey = match ($statusLabel) {
                'Backlog' => 'backlog',
                'Completed' => 'completed',
                default => 'progress',
            };
            $hasRouteLink = !empty($order->template_route_id)
                || $routeStats['has_any']
                || $routeStats['total'] > 0;

            return [
                'id' => $order->id,
                'work_order_no' => $order->work_order_no,
                'customer_part_number' => $order->customer_part_number,
                'customer_code' => $order->customer_code,
                'customer_name' => $order->customer_name,
                'is_released' => $isReleased,
                'has_route_link' => $hasRouteLink,
                'routes_completed' => $routeStats['completed'],
                'routes_total' => $routeStats['total'],
                'has_packing' => $hasPacking,
                'wip_status' => $statusKey,
                'wip_status_label' => $this->wipStatusLabel($statusKey),
                'updated_at' => $order->updated_at?->toIso8601String(),
            ];
        })->values();

        $statusFilter = $this->normalizeWipStatusKey(Arr::get($filters, 'wip_status'));
        if ($statusFilter) {
            $items = $items->filter(
                static fn(array $row): bool => ($row['wip_status'] ?? '') === $statusFilter
            )->values();
        }

        $sortBy = $this->normalizeWipSortBy($sortBy);
        $sortDir = $this->normalizeSortDirection($sortDir);
        $items = $this->sortWipItems($items, $sortBy, $sortDir);

        $total = $items->count();
        $offset = ($page - 1) * $limit;
        $paged = $items->slice($offset, $limit)->values()->all();
        $lastPage = (int) max(1, (int) ceil($total / $limit));

        return [
            'data' => $paged,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $limit,
                'total' => $total,
            ],
        ];
    }

    public function detail(int $id): array
    {
        $workOrder = $this->workOrderRepository->findById($id)->load([
            'customer',
            'templateRoute',
            'userAssignments.user',
        ]);

        return (new WorkOrderResource($workOrder))->response()->getData(true);
    }

    public function detailBy(string $column, mixed $value): array
    {
        $workOrder = $this->workOrderRepository->findByColumn($column, $value)->load([
            'customer',
            'templateRoute',
            'userAssignments.user',
        ]);

        return (new WorkOrderResource($workOrder))->response()->getData(true);
    }

    public function create(array $data, array $evidenceImages = []): array
    {
        $this->syncCustomerSnapshot($data);
        $this->syncTemplateMetadata($data);
        $this->syncReleaseFlag($data);
        $this->ensureBatchNumber($data);

        $storedImages = $this->storeEvidenceImages($evidenceImages);
        if (!empty($storedImages)) {
            $data['evidence_images'] = $storedImages;
        }

        try {
            if (array_key_exists('metadata', $data)) {
                $data['metadata'] = $this->normalizeMetadata($data['metadata']);
                $this->normalizeRouteFlowMetadata($data['metadata']);
                $this->rebuildAssignmentSummary($data['metadata']);
            }
            $workOrder = $this->workOrderRepository->create($data)->load(['customer', 'templateRoute']);
        } catch (Throwable $e) {
            $this->deleteEvidenceImages($storedImages);
            throw $e;
        }

        if (array_key_exists('metadata', $data)) {
            $this->syncAssignmentsFromMetadata($workOrder->id, $data['metadata']);
        }

        try {
            $metadataSnapshot = $this->withProgressPct($this->normalizeMetadata($workOrder->metadata));
            $afterSnapshot = [
                'status' => $workOrder->status,
                'priority' => $workOrder->priority,
                'is_released' => $workOrder->is_released,
                'metadata' => $metadataSnapshot,
                'updated_at' => $workOrder->updated_at?->toIso8601String(),
                'completed_at' => $workOrder->completed_at?->toIso8601String(),
            ];
            $this->firebaseRealtimeService->publishWorkOrderEvent([
                'event' => 'work_order.created',
                'work_order_id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'actor_id' => null,
                'occurred_at' => now()->toIso8601String(),
                'snapshot' => $afterSnapshot,
            ]);

            $this->triggerService->executeForWorkOrderEvent(
                'work_order.created',
                $workOrder,
                [],
                $afterSnapshot
            );
        } catch (\Throwable) {
            // Realtime and automation failures should not block work order creation.
        }

        return (new WorkOrderResource($workOrder))->response()->getData(true);
    }

    public function createBatch(array $workOrders): array
    {
        $created = [];
        $failed = [];
        $updated = 0;
        $compositeKeys = [];

        foreach ($workOrders as $workOrder) {
            $key = $this->buildCompositeKey($workOrder);
            if ($key !== '||') {
                $compositeKeys[] = $key;
            }
        }

        $existingKeyMap = $this->loadExistingCompositeKeys($compositeKeys);

        foreach ($workOrders as $workOrder) {
            $compositeKey = $this->buildCompositeKey($workOrder);

            if ($compositeKey === '||') {
                $failed[] = [
                    'work_order_no' => $workOrder['work_order_no'] ?? null,
                    'message' => 'Missing identifiers to evaluate duplicates (Work Order No. + Customer Code + Customer Part No.).',
                ];

                continue;
            }

            try {
                $this->syncCustomerSnapshot($workOrder);
                $this->syncTemplateMetadata($workOrder);
                $this->syncReleaseFlag($workOrder);

                $incomingBatch = strtolower(trim((string) Arr::get($workOrder, 'batch_number', '')));
                $existingBatches = $existingKeyMap[$compositeKey] ?? [];
                $incomingSheetDate = $this->extractSheetDate(Arr::get($workOrder, 'sheet'));
                $workOrderId = null;
                $matchedBatch = null;
                $matchedDate = null;
                if (!empty($existingBatches)) {
                    foreach ($existingBatches as $batchKey => $entry) {
                        if ($incomingBatch !== '' && $batchKey === $incomingBatch) {
                            continue;
                        }
                        $candidateId = $entry['id'] ?? null;
                        if (!$candidateId) {
                            continue;
                        }
                        $candidateDate = $entry['sheet_date'] ?? null;
                        if ($workOrderId === null) {
                            $workOrderId = $candidateId;
                            $matchedBatch = $batchKey;
                            $matchedDate = $candidateDate;
                            continue;
                        }
                        if ($candidateDate !== null && ($matchedDate === null || $candidateDate > $matchedDate)) {
                            $workOrderId = $candidateId;
                            $matchedBatch = $batchKey;
                            $matchedDate = $candidateDate;
                        }
                    }
                }

                if ($workOrderId !== null && $matchedDate !== null) {
                    if ($incomingSheetDate === null || $incomingSheetDate <= $matchedDate) {
                        continue;
                    }
                }

                if ($workOrderId !== null) {
                    $updatedRow = (bool) $this->workOrderRepository->update($workOrderId, $workOrder);
                    if ($updatedRow) {
                        $updated++;
                        if (array_key_exists('metadata', $workOrder)) {
                            $this->syncAssignmentsFromMetadata($workOrderId, $workOrder['metadata']);
                        }
                    }
                    if ($incomingBatch !== '') {
                        if ($matchedBatch !== null) {
                            unset($existingKeyMap[$compositeKey][$matchedBatch]);
                        }
                        $existingKeyMap[$compositeKey] ??= [];
                        $existingKeyMap[$compositeKey][$incomingBatch] = [
                            'id' => $workOrderId,
                            'sheet_date' => $incomingSheetDate,
                        ];
                    }
                    continue;
                }

                $this->ensureBatchNumber($workOrder);

                $record = $this->workOrderRepository
                    ->create($workOrder)
                    ->load(['customer', 'templateRoute']);
                $created[] = $record;
                $recordBatch = strtolower(trim((string) ($record->batch_number ?? '')));
                $existingKeyMap[$compositeKey] ??= [];
                $existingKeyMap[$compositeKey][$recordBatch] = [
                    'id' => $record->id,
                    'sheet_date' => $this->extractSheetDate($record->sheet ?? null),
                ];
            } catch (Throwable $e) {
                $failed[] = [
                    'work_order_no' => $workOrder['work_order_no'] ?? null,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'items' => WorkOrderResource::collection(collect($created))->resolve(),
            'count' => count($created),
            'updated' => $updated,
            'failed' => count($failed),
            'errors' => $failed,
        ];
    }

    public function update(int $id, array $data, array $evidenceImages = [], ?User $actor = null): bool
    {
        $beforeOrder = $this->workOrderRepository->findById($id);
        $beforeMetadata = $this->withProgressPct($this->normalizeMetadata($beforeOrder->metadata));
        $beforeSnapshot = [
            'status' => $beforeOrder->status,
            'priority' => $beforeOrder->priority,
            'is_released' => $beforeOrder->is_released,
            'metadata' => $beforeMetadata,
            'updated_at' => $beforeOrder->updated_at?->toIso8601String(),
            'completed_at' => $beforeOrder->completed_at?->toIso8601String(),
        ];

        $notificationContext = Arr::pull($data, 'notification_context');
        $notificationMeta = Arr::pull($data, 'notification_meta', []);
        if (is_string($notificationMeta)) {
            $decoded = json_decode($notificationMeta, true);
            $notificationMeta = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($notificationMeta)) {
            $notificationMeta = [];
        }

        $this->syncCustomerSnapshot($data);
        $this->syncTemplateMetadata($data);
        $this->syncReleaseFlag($data);
        $this->applyProductionStartDate($id, $data);

        $workOrder = null;
        $storedImages = [];
        if (!empty($evidenceImages)) {
            $workOrder = $beforeOrder;
            $existingImages = is_array($workOrder->evidence_images) ? $workOrder->evidence_images : [];
            $storedImages = $this->storeEvidenceImages($evidenceImages);
            $data['evidence_images'] = array_values(array_merge($existingImages, $storedImages));
        }

        if (array_key_exists('metadata', $data)) {
            $data['metadata'] = $this->normalizeMetadata($data['metadata']);
            $this->normalizeRouteFlowMetadata($data['metadata']);
            $this->rebuildAssignmentSummary($data['metadata']);
        }

        $updated = (bool) $this->workOrderRepository->update($id, $data);

        if (!$updated && !empty($storedImages)) {
            $this->deleteEvidenceImages($storedImages);
        }

        if ($updated && array_key_exists('metadata', $data)) {
            $this->syncAssignmentsFromMetadata($id, $data['metadata']);
        }

        if ($updated) {
            $order = $this->workOrderRepository->findById($id);
            $changedFields = array_keys($data);
            $metadataSnapshot = $this->withProgressPct($this->normalizeMetadata($order->metadata));
            $afterSnapshot = [
                'status' => $order->status,
                'priority' => $order->priority,
                'is_released' => $order->is_released,
                'metadata' => $metadataSnapshot,
                'updated_at' => $order->updated_at?->toIso8601String(),
                'completed_at' => $order->completed_at?->toIso8601String(),
            ];

            try {
                $this->firebaseRealtimeService->publishVirtualizationUpdate([
                    'work_order_id' => $order->id,
                    'work_order_no' => $order->work_order_no,
                    'template_route_id' => $order->template_route_id,
                    'action' => 'work_order_update',
                ]);
                $this->firebaseRealtimeService->publishWorkOrderEvent([
                    'event' => 'work_order.updated',
                    'work_order_id' => $order->id,
                    'work_order_no' => $order->work_order_no,
                    'changed_fields' => $changedFields,
                    'actor_id' => $actor?->id,
                    'occurred_at' => now()->toIso8601String(),
                    'before_snapshot' => $beforeSnapshot,
                    'snapshot' => $afterSnapshot,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to publish work order realtime event.', [
                    'work_order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }

            try {
                $this->notificationService->notifyWorkOrder(
                    $order,
                    $actor,
                    $notificationContext,
                    $notificationMeta
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to send work order notification.', [
                    'work_order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }

            try {
                $eventType = in_array('status', $changedFields, true)
                    ? 'work_order.status_changed'
                    : 'work_order.updated';
                $this->triggerService->executeForWorkOrderEvent(
                    $eventType,
                    $order,
                    $beforeSnapshot,
                    $afterSnapshot,
                    $actor?->id
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to execute operation triggers for update.', [
                    'work_order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $updated;
    }

    public function bulkUpdateByCustomer(string $customerCode, string $customerPartNumber, array $changes): array
    {
        if (empty($changes)) {
            return [
                'updated' => 0,
            ];
        }

        $this->syncTemplateMetadata($changes);
        $this->syncReleaseFlag($changes);

        $updated = $this->workOrderRepository->updateByCustomerCodeAndPartNumber(
            $customerCode,
            $customerPartNumber,
            $changes
        );

        return [
            'updated' => $updated,
        ];
    }

    public function syncAssignments(int $id, array $routes): array
    {
        $this->workOrderRepository->findById($id);

        $rows = $this->buildAssignmentRows($id, $routes);
        $rows = $this->filterValidAssignmentRows($rows);

        DB::transaction(function () use ($id, $rows): void {
            UserWorkOrder::query()->where('work_order_id', $id)->delete();
            if (!empty($rows)) {
                UserWorkOrder::query()->insert($rows);
            }
        });

        return [
            'count' => count($rows),
        ];
    }

    public function recordTimeTracker(int $id, array $payload, User $actor): array
    {
        $workOrder = $this->workOrderRepository->findById($id);
        $metadata = $this->normalizeMetadata($workOrder->metadata);
        $beforeMetadata = $this->withProgressPct($metadata);
        $routesKey = $this->resolveRoutesKey($metadata);

        if (!isset($metadata[$routesKey]) || !is_array($metadata[$routesKey])) {
            $metadata[$routesKey] = [];
        }

        $routes = &$metadata[$routesKey];
        $location = $this->locateRouteEntry($routes, $payload);

        if (!$location) {
            throw ValidationException::withMessages([
                'route_key' => 'Route not found for time tracking.',
            ]);
        }

        if ($location['nested']) {
            $route = &$routes[$location['line_index']]['routes'][$location['route_index']];
        } else {
            $route = &$routes[$location['line_index']];
        }

        if (!is_array($route)) {
            throw ValidationException::withMessages([
                'route_key' => 'Route metadata is invalid.',
            ]);
        }

        $operatorId = (int) ($payload['operator_id'] ?? $actor->id);
        $this->assertTimeTrackerPermission($actor, $operatorId);

        $action = strtolower(trim((string) $payload['action']));
        $routeMetadata = is_array($route['metadata'] ?? null) ? $route['metadata'] : [];
        $rawTimeTracker = $routeMetadata['timeTracker'] ?? $routeMetadata['time_tracker'] ?? [];
        $timeTracker = is_array($rawTimeTracker) ? $rawTimeTracker : [];
        $entries = is_array($timeTracker['entries'] ?? null) ? $timeTracker['entries'] : [];

        $lastAction = $this->lastTimeTrackerAction($entries, $operatorId);
        $role = strtolower((string) ($actor->user_type ?? ''));
        $privileged = in_array($role, ['supervisor', 'admin'], true);
        if ($action === 'start' && $lastAction === 'stop' && !$privileged) {
            throw ValidationException::withMessages([
                'action' => 'Timer was stopped and requires supervisor restart.',
            ]);
        }
        $this->assertTimeTrackerAction($action, $lastAction);

        $printedQty = isset($payload['printed_qty']) ? $this->numericValue($payload['printed_qty']) : null;
        $operatorProgressPct = isset($payload['operator_progress_pct'])
            ? $this->numericValue($payload['operator_progress_pct'])
            : null;
        $routeProgressPct = isset($payload['route_progress_pct'])
            ? $this->numericValue($payload['route_progress_pct'])
            : null;
        $totalPrintedQty = isset($payload['total_printed_qty'])
            ? $this->numericValue($payload['total_printed_qty'])
            : null;
        $targetPrintedQty = isset($payload['target_printed_qty'])
            ? $this->numericValue($payload['target_printed_qty'])
            : null;
        $pauseReason = $payload['pause_reason'] ?? $payload['pauseReason'] ?? null;
        $pauseReasonKey = $payload['pause_reason_key'] ?? $payload['pauseReasonKey'] ?? null;
        $pauseNote = $payload['pause_note'] ?? $payload['pauseNote'] ?? null;

        $lastPrinted = $this->lastTimeTrackerPrintedQty($entries, $operatorId);
        if ($printedQty !== null && $lastPrinted !== null) {
            if (!$privileged && $printedQty < $lastPrinted) {
                throw ValidationException::withMessages([
                    'printed_qty' => 'Printed quantity must be greater than or equal to the previous entry.',
                ]);
            }
        }

        $timestamp = now()->toIso8601String();
        $entry = [
            'id' => (string) Str::uuid(),
            'action' => $action,
            'at' => $timestamp,
            'source' => $payload['source'] ?? 'manual',
            'operator_id' => $operatorId,
            'operator_name' => $this->resolveOperatorName($operatorId),
            'printed_qty' => $printedQty,
            'operator_progress_pct' => $operatorProgressPct,
            'route_progress_pct' => $routeProgressPct,
            'total_printed_qty' => $totalPrintedQty,
            'target_printed_qty' => $targetPrintedQty,
            'recorded_by' => [
                'id' => $actor->id,
                'name' => $this->resolveActorName($actor),
                'role' => $actor->user_type,
            ],
            'override' => $actor->id !== $operatorId,
        ];
        if (is_string($pauseReason) && trim($pauseReason) !== '') {
            $entry['pause_reason'] = trim($pauseReason);
        }
        if (is_string($pauseReasonKey) && trim($pauseReasonKey) !== '') {
            $entry['pause_reason_key'] = trim($pauseReasonKey);
        }
        if (is_string($pauseNote) && trim($pauseNote) !== '') {
            $entry['pause_note'] = trim($pauseNote);
        }

        $entries[] = $entry;
        $timeTracker['entries'] = $entries;
        $timeTracker['updated_at'] = $timestamp;
        $routeMetadata['timeTracker'] = $timeTracker;
        unset($routeMetadata['time_tracker']);
        $route['metadata'] = $routeMetadata;

        $this->workOrderRepository->update($id, ['metadata' => $metadata]);
        $afterMetadata = $this->withProgressPct($metadata);

        try {
            $this->firebaseRealtimeService->publishVirtualizationUpdate([
                'work_order_id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'template_route_id' => $workOrder->template_route_id,
                'route_key' => $payload['route_key'] ?? $payload['routeKey'] ?? ($route['route'] ?? $route['key'] ?? null),
                'action' => $action,
                'operator_id' => $operatorId,
                'entry_id' => $entry['id'] ?? null,
                'at' => $entry['at'] ?? null,
            ]);
        } catch (\Throwable) {
            // Firebase updates should not block time tracker writes.
        }

        try {
            $this->firebaseRealtimeService->publishWorkOrderEvent([
                'event' => 'work_order.progress',
                'work_order_id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'changed_fields' => ['metadata', 'metadata.state.progressPct'],
                'actor_id' => $actor->id,
                'occurred_at' => now()->toIso8601String(),
                'before_snapshot' => [
                    'status' => $workOrder->status,
                    'priority' => $workOrder->priority,
                    'is_released' => $workOrder->is_released,
                    'metadata' => $beforeMetadata,
                    'updated_at' => $workOrder->updated_at?->toIso8601String(),
                    'completed_at' => $workOrder->completed_at?->toIso8601String(),
                ],
                'snapshot' => [
                    'status' => $workOrder->status,
                    'priority' => $workOrder->priority,
                    'is_released' => $workOrder->is_released,
                    'metadata' => $afterMetadata,
                    'updated_at' => now()->toIso8601String(),
                    'completed_at' => $workOrder->completed_at?->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to publish work order progress event.', [
                'work_order_id' => $workOrder->id,
                'message' => $e->getMessage(),
            ]);
        }

        try {
            $this->triggerService->executeForWorkOrderEvent(
                'work_order.progress',
                $workOrder,
                [
                    'status' => $workOrder->status,
                    'priority' => $workOrder->priority,
                    'is_released' => $workOrder->is_released,
                    'metadata' => $beforeMetadata,
                    'updated_at' => $workOrder->updated_at?->toIso8601String(),
                    'completed_at' => $workOrder->completed_at?->toIso8601String(),
                ],
                [
                    'status' => $workOrder->status,
                    'priority' => $workOrder->priority,
                    'is_released' => $workOrder->is_released,
                    'metadata' => $afterMetadata,
                    'updated_at' => now()->toIso8601String(),
                    'completed_at' => $workOrder->completed_at?->toIso8601String(),
                ],
                $actor->id
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to execute operation triggers for progress.', [
                'work_order_id' => $workOrder->id,
                'message' => $e->getMessage(),
            ]);
        }

        try {
            $this->notificationService->notifyWorkOrder($workOrder, $actor, 'progress', [
                'step_key' => $route['route'] ?? $route['key'] ?? null,
                'step_name' => $route['name'] ?? null,
                'route_key' => $payload['route_key'] ?? $payload['routeKey'] ?? null,
                'action' => $action,
                'source' => $payload['source'] ?? 'manual',
            ]);
        } catch (\Throwable) {
            // Notifications should not block time tracker writes.
        }

        return [
            'entry' => $entry,
            'time_tracker' => $timeTracker,
            'route' => [
                'route' => $route['route'] ?? $route['key'] ?? null,
                'name' => $route['name'] ?? null,
                'order_seq' => $route['order_seq'] ?? $route['orderSeq'] ?? null,
            ],
        ];
    }

    protected function storeEvidenceImages(array $evidenceImages): array
    {
        $files = [];
        foreach ($evidenceImages as $file) {
            if ($file instanceof UploadedFile) {
                $files[] = $file;
            }
        }

        $stored = [];
        foreach ($files as $file) {
            $stored[] = $this->storeEvidenceImage($file);
        }

        return $stored;
    }

    protected function resolveRoutesKey(array $metadata): string
    {
        if (array_key_exists('routes', $metadata)) {
            return 'routes';
        }
        if (array_key_exists('data', $metadata)) {
            return 'data';
        }
        if (array_key_exists('steps', $metadata)) {
            return 'steps';
        }
        return 'routes';
    }

    protected function locateRouteEntry(array $routes, array $payload): ?array
    {
        $routeKey = $this->normalizeRouteToken($payload['route_key'] ?? null);
        $orderSeq = $payload['order_seq'] ?? null;
        $targetIndex = isset($payload['route_index']) ? (int) $payload['route_index'] : null;
        $flatIndex = 0;

        foreach ($routes as $lineIndex => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (array_key_exists('routes', $entry) && is_array($entry['routes'])) {
                foreach ($entry['routes'] as $routeIndex => $route) {
                    if (!is_array($route)) {
                        $flatIndex++;
                        continue;
                    }

                    if ($this->routeMatchesPayload($route, $routeKey, $orderSeq, $targetIndex, $flatIndex)) {
                        return [
                            'line_index' => $lineIndex,
                            'route_index' => $routeIndex,
                            'nested' => true,
                        ];
                    }
                    $flatIndex++;
                }
                continue;
            }

            if ($this->routeMatchesPayload($entry, $routeKey, $orderSeq, $targetIndex, $flatIndex)) {
                return [
                    'line_index' => $lineIndex,
                    'route_index' => null,
                    'nested' => false,
                ];
            }
            $flatIndex++;
        }

        return null;
    }

    protected function routeMatchesPayload(
        array $route,
        ?string $routeKey,
        mixed $orderSeq,
        ?int $targetIndex,
        int $flatIndex
    ): bool {
        if ($targetIndex !== null && $flatIndex === $targetIndex) {
            return true;
        }

        if ($orderSeq !== null && $orderSeq !== '') {
            $routeOrder = $route['order_seq'] ?? $route['orderSeq'] ?? null;
            if ($routeOrder !== null && (string) $routeOrder === (string) $orderSeq) {
                return true;
            }
        }

        if ($routeKey) {
            $candidates = [
                $route['route'] ?? null,
                $route['key'] ?? null,
                $route['name'] ?? null,
            ];
            foreach ($candidates as $candidate) {
                if ($this->normalizeRouteToken($candidate) === $routeKey) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function normalizeRouteToken(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return null;
        }
        $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;
        return $raw;
    }

    protected function assertTimeTrackerPermission(User $actor, int $operatorId): void
    {
        $role = strtolower((string) ($actor->user_type ?? ''));
        $privileged = in_array($role, ['supervisor', 'admin'], true);

        if (!$privileged && $actor->id !== $operatorId) {
            throw ValidationException::withMessages([
                'operator_id' => 'Operators can only track their own progress.',
            ]);
        }
    }

    protected function lastTimeTrackerAction(array $entries, int $operatorId): ?string
    {
        $last = null;
        $statefulActions = ['start', 'pause', 'stop'];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entryOperator = $entry['operator_id'] ?? $entry['operatorId'] ?? $entry['operator'] ?? null;
            if ((string) $entryOperator !== (string) $operatorId) {
                continue;
            }
            $action = strtolower(trim((string) ($entry['action'] ?? '')));
            if (!in_array($action, $statefulActions, true)) {
                continue;
            }
            $last = $action;
        }

        return $last ?: null;
    }

    protected function lastTimeTrackerPrintedQty(array $entries, int $operatorId): ?float
    {
        $last = null;
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entryOperator = $entry['operator_id'] ?? $entry['operatorId'] ?? $entry['operator'] ?? null;
            if ((string) $entryOperator !== (string) $operatorId) {
                continue;
            }
            if (array_key_exists('printed_qty', $entry) && $entry['printed_qty'] !== null) {
                $last = $this->numericValue($entry['printed_qty']);
            }
        }

        return $last;
    }

    protected function assertTimeTrackerAction(string $action, ?string $lastAction): void
    {
        $action = strtolower(trim($action));
        $lastAction = strtolower(trim((string) $lastAction));

        if ($action === 'start' && $lastAction === 'start') {
            throw ValidationException::withMessages([
                'action' => 'Timer is already running.',
            ]);
        }
        if ($action === 'pause' && $lastAction !== 'start') {
            throw ValidationException::withMessages([
                'action' => 'Timer must be running before pausing.',
            ]);
        }
        if ($action === 'stop' && !in_array($lastAction, ['start', 'pause'], true)) {
            throw ValidationException::withMessages([
                'action' => 'Timer must be started before stopping.',
            ]);
        }
    }

    protected function resolveOperatorName(int $operatorId): ?string
    {
        if (!$operatorId) {
            return null;
        }
        $user = User::query()->select(['id', 'firstname', 'lastname', 'middlename', 'email'])->find($operatorId);
        if (!$user) {
            return null;
        }
        $name = trim(sprintf('%s %s %s', $user->firstname, $user->middlename, $user->lastname));
        return $name !== '' ? $name : $user->email;
    }

    protected function resolveActorName(User $actor): string
    {
        $name = trim(sprintf('%s %s %s', $actor->firstname, $actor->middlename, $actor->lastname));
        return $name !== '' ? $name : ($actor->email ?? 'User');
    }

    protected function deleteEvidenceImages(array $paths): void
    {
        if (empty($paths)) {
            return;
        }

        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $clean = ltrim($path, '/');
            if (str_starts_with($clean, 'evidence_image/')) {
                $filePath = public_path('images/' . $clean);
                if (FileFacade::exists($filePath)) {
                    FileFacade::delete($filePath);
                    continue;
                }
            } elseif (str_starts_with($clean, 'images/evidence_image/')) {
                $filePath = public_path($clean);
                if (FileFacade::exists($filePath)) {
                    FileFacade::delete($filePath);
                    continue;
                }
            }
            Storage::disk('public')->delete($path);
        }
    }

    protected function storeEvidenceImage(UploadedFile $image): string
    {
        $filename = Str::uuid()->toString() . '.png';
        $tempPath = tempnam(sys_get_temp_dir(), 'work_order_');
        if ($tempPath === false) {
            throw new \RuntimeException('Failed to create temp file for image conversion.');
        }

        $mimeType = $image->getMimeType();
        $resource = match ($mimeType) {
            'image/jpeg', 'image/jpg' => imagecreatefromjpeg($image->getRealPath()),
            'image/png' => imagecreatefrompng($image->getRealPath()),
            'image/webp' => imagecreatefromwebp($image->getRealPath()),
            default => false,
        };

        if (!$resource) {
            @unlink($tempPath);
            throw new \RuntimeException('Unsupported image type for PNG conversion.');
        }

        imagealphablending($resource, true);
        imagesavealpha($resource, true);
        $saved = imagepng($resource, $tempPath, 6);
        imagedestroy($resource);

        if (!$saved) {
            @unlink($tempPath);
            throw new \RuntimeException('Failed to convert image to PNG.');
        }

        $targetDir = public_path('images/evidence_image');
        if (!FileFacade::isDirectory($targetDir)) {
            FileFacade::makeDirectory($targetDir, 0755, true);
        }
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
        FileFacade::move($tempPath, $targetPath);
        @FileFacade::chmod($targetPath, 0644);

        return "evidence_image/{$filename}";
    }
    public function delete(int $id): bool
    {
        return $this->workOrderRepository->delete($id);
    }

    public function importFromSpreadsheet(UploadedFile $file, string $sheet): array
    {
        return $this->workOrderImportService->import($file, $sheet);
    }

    public function listWithActiveTemplateRoutes(): array
    {
        $orders = $this->workOrderRepository->withTemplateRoutes();

        $filtered = $orders->filter(function (WorkOrder $order): bool {
            $template = $order->templateRoute;
            if (!$template) {
                return false;
            }

            if (!$this->metadataPresent($order->metadata)) {
                return false;
            }

            return $this->templateRouteAppearsActive($template);
        })->values();

        return [
            'data' => WorkOrderResource::collection($filtered)->resolve(),
            'count' => $filtered->count(),
        ];
    }

    public function virtualizationSnapshot(
        ?int $templateRouteId = null,
        ?string $templateRouteKey = null,
        array $filters = [],
        array $order = [],
        bool $forceFresh = false
    ): array {
        $cacheKey = 'work_orders.virtualization.' . md5(json_encode([
            'template_route_id' => $templateRouteId,
            'template_route_key' => $templateRouteKey,
            'filters' => $filters,
            'order' => $order,
        ], JSON_UNESCAPED_SLASHES));

        if ($forceFresh) {
            return $this->buildVirtualizationSnapshot($templateRouteId, $templateRouteKey, $filters, $order);
        }

        return Cache::remember($cacheKey, now()->addSeconds(8), function () use ($templateRouteId, $templateRouteKey, $filters, $order): array {
            return $this->buildVirtualizationSnapshot($templateRouteId, $templateRouteKey, $filters, $order);
        });
    }

    protected function buildVirtualizationSnapshot(
        ?int $templateRouteId = null,
        ?string $templateRouteKey = null,
        array $filters = [],
        array $order = []
    ): array {
        $this->assertVirtualizationRange($filters);

        $baseQuery = WorkOrder::query()
            ->whereNotNull('template_route_id')
            ->whereHas('templateRoute');

        $this->applyWorkOrderFilters($baseQuery, $filters);

        $total = (clone $baseQuery)->count();
        $this->assertVirtualizationOrderLimit($total);

        $query = $baseQuery->with(['customer', 'templateRoute', 'userAssignments.user']);

        $this->applyWorkOrderOrdering($query, $order);

        $orders = $query->get();

        $workOrderNos = $orders->pluck('work_order_no')->filter()->values();
        $packingSet = $workOrderNos->isEmpty()
            ? collect()
            : PackingChecklist::query()
                ->whereIn('work_order_no', $workOrderNos)
                ->pluck('work_order_no')
                ->flip();

        $filtered = $orders->values();

        $groups = $filtered->groupBy(function (WorkOrder $order) {
            $template = $order->templateRoute;
            $key = $template?->route_name_sequence_key ?: $template?->template ?: '';
            $trimmed = trim((string) $key);
            return $trimmed !== '' ? $trimmed : (string) $template?->id;
        });
        $groupList = $groups->map(function ($items, $key): array {
            $template = $items->first()->templateRoute;
            return [
                'id' => (string) $key,
                'template' => $template?->template ?? (string) $key,
                'batch_number' => $template?->batch_number,
                'sheet' => $template?->sheet,
                'route_name_sequence_key' => $template?->route_name_sequence_key ?? (string) $key,
                'route_sequence_with_machines' => null,
                'template_route_ids' => $items->pluck('template_route_id')->unique()->values()->all(),
                'work_orders_count' => $items->count(),
            ];
        })
            ->sortBy(function ($item) {
                return strtolower((string) ($item['template'] ?? ''));
            })
            ->values()
            ->all();

        $selectedKey = $templateRouteKey;
        $useAll = is_string($selectedKey) && strtolower(trim($selectedKey)) === 'all';
        if ($useAll) {
            unset($filters['template_route_id'], $filters['template_route_key']);
        }
        if (!$selectedKey && $templateRouteId) {
            $matched = $filtered->firstWhere('template_route_id', $templateRouteId);
            $selectedKey = $matched?->templateRoute?->route_name_sequence_key
                ?: $matched?->templateRoute?->template
                ?: null;
        }
        if (!$selectedKey && !empty($groupList)) {
            $selectedKey = $groupList[0]['id'];
        }
        if (!$useAll && $selectedKey && !$groups->has($selectedKey) && !empty($groupList)) {
            $selectedKey = $groupList[0]['id'];
        }

        if ($useAll) {
            $selectedKey = 'all';
            $selectedOrders = $filtered;
        } else {
            $selectedOrders = $selectedKey ? ($groups->get($selectedKey) ?? collect()) : collect();
        }
        $summary = $this->buildVirtualizationSummary($selectedOrders);

        $workOrders = $selectedOrders->values()->map(function (WorkOrder $order): array {
            $payload = (new WorkOrderResource($order))->resolve();
            $payload['user_assignments'] = $order->userAssignments
                ->map(function (UserWorkOrder $assignment): array {
                    return [
                        'id' => $assignment->id,
                        'user_id' => $assignment->user_id,
                        'route_key' => $assignment->route_key,
                        'route_code' => $assignment->route_code,
                        'route_name' => $assignment->route_name,
                        'order_seq' => $assignment->order_seq,
                        'assigned_qty' => $assignment->assigned_qty,
                        'user' => $assignment->user ? [
                            'id' => $assignment->user->id,
                            'firstname' => $assignment->user->firstname,
                            'lastname' => $assignment->user->lastname,
                            'middlename' => $assignment->user->middlename,
                            'email' => $assignment->user->email,
                            'picture_url' => $assignment->user->picture_url,
                        ] : null,
                    ];
                })
                ->values()
                ->all();
            return $payload;
        })->all();

        return [
            'groups' => $groupList,
            'selected_template_route_id' => $selectedKey,
            'summary' => $summary,
            'work_orders' => $workOrders,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    protected function assertVirtualizationRange(array $filters): void
    {
        $ranges = [
            ['label' => 'order_date', 'from' => Arr::get($filters, 'order_date_from'), 'to' => Arr::get($filters, 'order_date_to')],
            ['label' => 'production_due_date', 'from' => Arr::get($filters, 'production_due_from'), 'to' => Arr::get($filters, 'production_due_to')],
            ['label' => 'requested_delivery_date', 'from' => Arr::get($filters, 'requested_delivery_from'), 'to' => Arr::get($filters, 'requested_delivery_to')],
            ['label' => 'schedule', 'from' => Arr::get($filters, 'schedule_from'), 'to' => Arr::get($filters, 'schedule_to')],
        ];

        foreach ($ranges as $range) {
            $from = $range['from'];
            $to = $range['to'];
            if (!$from && !$to) {
                continue;
            }

            if (!$from || !$to) {
                throw ValidationException::withMessages([
                    $range['label'] => sprintf(
                        'Virtualization requires a start and end date (max %d days).',
                        self::VIRTUALIZATION_MAX_RANGE_DAYS
                    ),
                ]);
            }

            try {
                $start = \Illuminate\Support\Carbon::parse($from)->startOfDay();
                $end = \Illuminate\Support\Carbon::parse($to)->startOfDay();
            } catch (Throwable $e) {
                throw ValidationException::withMessages([
                    $range['label'] => 'Virtualization date range is invalid.',
                ]);
            }

            if ($start->gt($end)) {
                [$start, $end] = [$end, $start];
            }

            $days = $start->diffInDays($end) + 1;
            if ($days > self::VIRTUALIZATION_MAX_RANGE_DAYS) {
                throw ValidationException::withMessages([
                    $range['label'] => sprintf(
                        'Virtualization date range cannot exceed %d days.',
                        self::VIRTUALIZATION_MAX_RANGE_DAYS
                    ),
                ]);
            }
        }
    }

    protected function assertVirtualizationOrderLimit(int $count): void
    {
        if ($count <= self::VIRTUALIZATION_MAX_ORDERS) {
            return;
        }

        throw ValidationException::withMessages([
            'work_orders' => sprintf(
                'Virtualization supports up to %d work orders. Narrow the filters to continue.',
                self::VIRTUALIZATION_MAX_ORDERS
            ),
        ]);
    }

    protected function applyWorkOrderFilters($query, array $filters): void
    {
        if ($workOrderNo = Arr::get($filters, 'work_order_no')) {
            $query->where('work_order_no', 'LIKE', "%{$workOrderNo}%");
        }

        if ($batchNumber = Arr::get($filters, 'batch_number')) {
            $query->where('batch_number', 'LIKE', "%{$batchNumber}%");
        }

        if ($sheet = Arr::get($filters, 'sheet')) {
            $normalized = strtolower(trim((string) $sheet));
            if ($normalized !== '') {
                $query->whereRaw(
                    "LOWER(TRIM(COALESCE(sheet, ''))) = ?",
                    [$normalized]
                );
            }
        }

        if ($customerId = Arr::get($filters, 'customer_id')) {
            $query->where('customer_id', $customerId);
        }

        if (($selected = Arr::get($filters, 'selected')) !== null) {
            $query->where('selected', filter_var($selected, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]) ?? (bool) $selected);
        }

        if ($mesBatchNo = Arr::get($filters, 'mes_batch_no')) {
            $query->where('mes_batch_no', 'LIKE', "%{$mesBatchNo}%");
        }

        if ($customerCode = Arr::get($filters, 'customer_code')) {
            $query->where('customer_code', 'LIKE', "%{$customerCode}%");
        }

        if ($customerName = Arr::get($filters, 'customer_name')) {
            $query->where('customer_name', 'LIKE', "%{$customerName}%");
        }

        if ($customerPartNumber = Arr::get($filters, 'customer_part_number')) {
            $query->where('customer_part_number', 'LIKE', "%{$customerPartNumber}%");
        }

        if ($salesPersonCode = Arr::get($filters, 'sales_person_code')) {
            $query->where('sales_person_code', 'LIKE', "%{$salesPersonCode}%");
        }

        if ($orderFrom = Arr::get($filters, 'order_date_from')) {
            $query->whereDate('order_date', '>=', $orderFrom);
        }

        if ($orderTo = Arr::get($filters, 'order_date_to')) {
            $query->whereDate('order_date', '<=', $orderTo);
        }

        if ($dueFrom = Arr::get($filters, 'production_due_from')) {
            $query->whereDate('production_due_date', '>=', $dueFrom);
        }

        if ($dueTo = Arr::get($filters, 'production_due_to')) {
            $query->whereDate('production_due_date', '<=', $dueTo);
        }

        if ($requestedFrom = Arr::get($filters, 'requested_delivery_from')) {
            $query->whereDate('requested_delivery_date', '>=', $requestedFrom);
        }

        if ($requestedTo = Arr::get($filters, 'requested_delivery_to')) {
            $query->whereDate('requested_delivery_date', '<=', $requestedTo);
        }

        $scheduleFrom = Arr::get($filters, 'schedule_from');
        $scheduleTo = Arr::get($filters, 'schedule_to');
        if ($scheduleFrom || $scheduleTo) {
            $query->where(function ($range) use ($scheduleFrom, $scheduleTo) {
                $startField = DB::raw("COALESCE(production_start_date, production_due_date)");
                if ($scheduleTo) {
                    $range->whereDate($startField, '<=', $scheduleTo);
                }
                if ($scheduleFrom) {
                    $range->where(function ($overlap) use ($scheduleFrom, $startField) {
                        $overlap->whereDate('production_due_date', '>=', $scheduleFrom)
                            ->orWhereDate($startField, '>=', $scheduleFrom);
                    });
                }
            });
        }

        if ($orderDays = Arr::get($filters, 'order_date_days')) {
            $this->applyDayOfWeekFilter($query, 'order_date', $orderDays);
        }

        if ($dueDays = Arr::get($filters, 'production_due_days')) {
            $this->applyDayOfWeekFilter($query, 'production_due_date', $dueDays);
        }

        if ($requestedDays = Arr::get($filters, 'requested_delivery_days')) {
            $this->applyDayOfWeekFilter($query, 'requested_delivery_date', $requestedDays);
        }

        if ($templateRouteId = Arr::get($filters, 'template_route_id')) {
            $query->where('template_route_id', $templateRouteId);
        }

        if ($operatorId = Arr::get($filters, 'operator_id')) {
            $metadataMatchedIds = $this->resolveMetadataAssignedWorkOrderIds((string) $operatorId);
            $query->where(function ($q) use ($operatorId, $metadataMatchedIds) {
                $q->whereHas('userAssignments', function ($assignmentQuery) use ($operatorId) {
                    $assignmentQuery->where('user_id', $operatorId);
                });

                if (!empty($metadataMatchedIds)) {
                    $q->orWhereIn('id', $metadataMatchedIds);
                }
            });
        }

        if ($templateRouteBatch = Arr::get($filters, 'template_route_batch_number')) {
            $query->whereHas('templateRoute', function ($q) use ($templateRouteBatch) {
                $q->where('batch_number', $templateRouteBatch);
            });
        }

        if ($priority = Arr::get($filters, 'priority')) {
            $normalized = strtoupper(trim((string) $priority));
            if ($normalized !== '') {
                $normalized = str_replace([' ', '-'], '_', $normalized);
                $query->whereRaw(
                    "REPLACE(REPLACE(UPPER(TRIM(COALESCE(priority, priority_type, ''))), ' ', '_'), '-', '_') = ?",
                    [$normalized]
                );
            }
        }

        if (($isStarred = Arr::get($filters, 'is_starred')) !== null) {
            $query->where('is_starred', filter_var($isStarred, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]) ?? (bool) $isStarred);
        }
    }

    protected function applyWorkOrderOrdering($query, array $order): void
    {
        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $priorityCase = "CASE REPLACE(REPLACE(UPPER(TRIM(COALESCE(priority, priority_type, ''))), ' ', '_'), '-', '_') " .
            "WHEN 'ASAP' THEN 1 " .
            "WHEN 'VERY_URGENT' THEN 2 " .
            "WHEN 'URGENT' THEN 3 " .
            "WHEN 'MEDIUM' THEN 4 " .
            "WHEN 'LOW' THEN 5 " .
            "ELSE 99 END";

        switch ($orderBy) {
            case 'route_link':
                $query
                    ->orderByRaw("template_route_id IS NULL " . ($direction === 'asc' ? 'ASC' : 'DESC'))
                    ->orderBy('template_route_id', $direction)
                    ->orderBy('id', $direction);
                break;
            case 'starred_priority':
                $query->orderBy('is_starred', 'desc')
                    ->orderByRaw($priorityCase)
                    ->orderBy('id', $direction);
                break;
            case 'priority':
                $query->orderByRaw($priorityCase)
                    ->orderBy('id', $direction);
                break;
            case 'production_due_date':
            case 'order_date':
            case 'requested_delivery_date':
            case 'status':
            case 'work_order_no':
            case 'customer_name':
            case 'customer_code':
            case 'customer_part_number':
            case 'id':
                $query->orderBy($orderBy, $direction);
                break;
            default:
                $query->orderBy('id', 'desc');
        }
    }

    protected function applyDayOfWeekFilter($query, string $column, mixed $days): void
    {
        $tokens = $this->normalizeDayTokens($days);
        if (empty($tokens)) {
            return;
        }

        $driver = DB::getDriverName();
        $mysqlMap = [
            'mon' => 0,
            'tue' => 1,
            'wed' => 2,
            'thu' => 3,
            'fri' => 4,
            'sat' => 5,
            'sun' => 6,
        ];
        $isoMap = [
            'sun' => 0,
            'mon' => 1,
            'tue' => 2,
            'wed' => 3,
            'thu' => 4,
            'fri' => 5,
            'sat' => 6,
        ];

        if ($driver === 'mysql') {
            $values = array_values(array_unique(array_map(fn($d) => $mysqlMap[$d], $tokens)));
            $query->whereNotNull($column)
                ->whereIn(DB::raw("WEEKDAY({$column})"), $values);
            return;
        }

        if ($driver === 'pgsql') {
            $values = array_values(array_unique(array_map(fn($d) => $isoMap[$d], $tokens)));
            $query->whereNotNull($column)
                ->whereIn(DB::raw("EXTRACT(DOW FROM {$column})"), $values);
            return;
        }

        if ($driver === 'sqlite') {
            $values = array_values(array_unique(array_map(fn($d) => $isoMap[$d], $tokens)));
            $query->whereNotNull($column)
                ->whereIn(DB::raw("strftime('%w', {$column})"), $values);
            return;
        }

        if ($driver === 'sqlsrv') {
            $names = array_values(array_unique(array_map([$this, 'dayTokenToName'], $tokens)));
            $query->whereNotNull($column)
                ->whereIn(DB::raw("DATENAME(WEEKDAY, {$column})"), $names);
        }
    }

    protected function normalizeDayTokens(mixed $days): array
    {
        if ($days === null) {
            return [];
        }

        $raw = is_array($days) ? $days : preg_split('/[,\s]+/', (string) $days);
        $valid = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $tokens = [];

        foreach ($raw as $day) {
            if ($day === null) {
                continue;
            }
            $label = strtolower(trim((string) $day));
            if ($label === '') {
                continue;
            }
            $short = substr($label, 0, 3);
            if (in_array($short, $valid, true)) {
                $tokens[] = $short;
            }
        }

        return array_values(array_unique($tokens));
    }

    protected function dayTokenToName(string $token): string
    {
        return match ($token) {
            'mon' => 'Monday',
            'tue' => 'Tuesday',
            'wed' => 'Wednesday',
            'thu' => 'Thursday',
            'fri' => 'Friday',
            'sat' => 'Saturday',
            'sun' => 'Sunday',
            default => $token,
        };
    }

    public function linkTemplateRoutesByReference(
        ?string $reference = null,
        ?string $batchNumber = null,
        ?string $templateBatchNumber = null
    ): array {
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }
        @ini_set('max_execution_time', '600');
        DB::disableQueryLog();
        $referenceMode = strtolower(trim((string) $reference));
        if (in_array($referenceMode, ['customer_part_number_ref', 'customer_part_number', 'customer_part_no'], true)) {
            $referenceMode = 'customer_part_number';
        } elseif (in_array($referenceMode, ['work_order_no', 'work_order', 'wod_ref'], true)) {
            $referenceMode = 'work_order_no';
        } else {
            $referenceMode = 'auto';
        }

        $batchNumber = trim((string) $batchNumber);
        $batchNumber = $batchNumber !== '' ? $batchNumber : null;
        $templateBatchNumber = trim((string) $templateBatchNumber);
        $templateBatchNumber = $templateBatchNumber !== '' ? $templateBatchNumber : null;

        $hasTemplatePartNumberRef = Schema::hasColumn('template_routes', 'customer_part_number_ref');
        $hasTemplateWorkOrderRef = Schema::hasColumn('template_routes', 'wod_ref');
        $hasTemplateBatchNumber = Schema::hasColumn('template_routes', 'batch_number');
        $hasWorkOrderPartNumber = Schema::hasColumn('work_orders', 'customer_part_number');
        $hasWorkOrderBatchNumber = Schema::hasColumn('work_orders', 'batch_number');

        if ($referenceMode === 'customer_part_number' && (!$hasTemplatePartNumberRef || !$hasWorkOrderPartNumber)) {
            $result = [
                'linked' => 0,
                'skipped' => 0,
                'eligible' => 0,
                'template_routes' => 0,
                'reference' => $referenceMode,
                'warning' => 'Customer part number reference is unavailable. Add template_routes.customer_part_number_ref and work_orders.customer_part_number.',
            ];
            if ($batchNumber !== null)
                $result['batch_number'] = $batchNumber;
            if ($templateBatchNumber !== null)
                $result['template_batch_number'] = $templateBatchNumber;
            return $result;
        }

        $useCustomerPartNumber = $referenceMode !== 'work_order_no'
            && $hasTemplatePartNumberRef
            && $hasWorkOrderPartNumber;

        $allowWorkOrderFallback = $referenceMode !== 'customer_part_number'
            && $hasTemplateWorkOrderRef;

        $normalizeRefs = function ($value): array {
            $raw = trim((string) $value);
            if ($raw === '')
                return [];

            // split on commas, whitespace, semicolons, pipes, newlines
            $parts = preg_split('/[\s,;|]+/', $raw) ?: [];
            $parts = array_map(static fn($x) => strtoupper(trim((string) $x)), $parts);
            $parts = array_values(array_filter($parts, static fn($x) => $x !== ''));
            return array_values(array_unique($parts));
        };

        // --------- Load templates (prefer latest) ----------
        $templateSelect = ['id', 'metadata', 'created_at'];
        if ($hasTemplatePartNumberRef)
            $templateSelect[] = 'customer_part_number_ref';
        if ($hasTemplateWorkOrderRef)
            $templateSelect[] = 'wod_ref';
        if ($hasTemplateBatchNumber)
            $templateSelect[] = 'batch_number';

        $templatesQuery = TemplateRoute::query()->select($templateSelect);

        // IMPORTANT: if templateBatchNumber provided AND template_routes has batch_number, only consider that batch
        if ($templateBatchNumber !== null && $hasTemplateBatchNumber) {
            $templatesQuery->where('batch_number', $templateBatchNumber);
        }

        // newest first (so first match wins)
        $templates = $templatesQuery->orderByDesc('created_at')->get();

        if ($templates->isEmpty()) {
            $result = [
                'linked' => 0,
                'skipped' => 0,
                'eligible' => 0,
                'template_routes' => 0,
                'reference' => $referenceMode,
            ];
            if ($batchNumber !== null)
                $result['batch_number'] = $batchNumber;
            if ($templateBatchNumber !== null)
                $result['template_batch_number'] = $templateBatchNumber;
            return $result;
        }

        // Normalize template metadata once
        $templatesById = $templates->mapWithKeys(function (TemplateRoute $template): array {
            return [
                $template->id => [
                    'id' => $template->id,
                    'metadata' => $this->normalizeTemplateMetadata($template->metadata),
                ],
            ];
        });

        // Build indexes:
        // - partNumber => templateId (FIRST wins due to created_at desc)
        // - workOrderNo => templateId (fallback)
        $partNumberIndex = [];
        $workOrderIndex = [];

        if ($useCustomerPartNumber) {
            foreach ($templates as $tpl) {
                $refString = trim((string) $tpl->customer_part_number_ref);
                if ($refString === '')
                    continue;

                foreach ($normalizeRefs($refString) as $ref) {
                    if (!isset($partNumberIndex[$ref])) {
                        $partNumberIndex[$ref] = $tpl->id;
                    }
                }
            }
        }

        if ($allowWorkOrderFallback) {
            foreach ($templates as $tpl) {
                $refString = trim((string) $tpl->wod_ref);
                if ($refString === '')
                    continue;

                // only use wod_ref when customer_part_number_ref is empty (optional behavior)
                if ($useCustomerPartNumber && trim((string) $tpl->customer_part_number_ref) !== '') {
                    continue;
                }

                foreach ($normalizeRefs($refString) as $ref) {
                    if (!isset($workOrderIndex[$ref])) {
                        $workOrderIndex[$ref] = $tpl->id;
                    }
                }
            }
        }

        // --------- Eligible work orders ----------
        $hasIsReleased = Schema::hasColumn('work_orders', 'is_released');
        $hasStatus = Schema::hasColumn('work_orders', 'status');
        $hasProductionDateCompleted = Schema::hasColumn('work_orders', 'production_date_completed');
        $hasProductionQtyCompleted = Schema::hasColumn('work_orders', 'production_qty_completed');

        $orderSelect = ['id', 'work_order_no', 'metadata', 'template_route_id'];
        if ($hasWorkOrderPartNumber)
            $orderSelect[] = 'customer_part_number';
        if ($hasWorkOrderBatchNumber)
            $orderSelect[] = 'batch_number';
        if ($hasStatus)
            $orderSelect[] = 'status';
        if ($hasProductionDateCompleted)
            $orderSelect[] = 'production_date_completed';
        if ($hasProductionQtyCompleted)
            $orderSelect[] = 'production_qty_completed';

        $eligibleOrdersQuery = WorkOrder::query()
            ->select($orderSelect)
            ->where(function ($query) {
                $query
                    ->whereNull('metadata')
                    ->orWhere('metadata', '')
                    ->orWhere('metadata', '[]')
                    ->orWhere('metadata', '{}')
                    ->orWhereNull('template_route_id');
            });

        // If batchNumber provided and work_orders has batch_number, filter work orders too
        if ($batchNumber !== null && $hasWorkOrderBatchNumber) {
            $eligibleOrdersQuery->where('batch_number', $batchNumber);
        }

        $eligibleCount = (clone $eligibleOrdersQuery)->count();
        if ($eligibleCount === 0) {
            $result = [
                'linked' => 0,
                'skipped' => 0,
                'eligible' => 0,
                'template_routes' => $templates->count(),
                'reference' => $referenceMode,
            ];
            if ($batchNumber !== null)
                $result['batch_number'] = $batchNumber;
            if ($templateBatchNumber !== null)
                $result['template_batch_number'] = $templateBatchNumber;
            return $result;
        }

        $linked = 0;
        $skipped = 0;

        $processChunk = function ($query) use ($templatesById, $useCustomerPartNumber, $allowWorkOrderFallback, $normalizeRefs, $partNumberIndex, $workOrderIndex, $hasIsReleased, $hasStatus, $hasProductionDateCompleted, $hasProductionQtyCompleted, &$linked, &$skipped): void {
            $query->orderBy('id')
                ->chunkById(200, function ($orders) use ($templatesById, $useCustomerPartNumber, $allowWorkOrderFallback, $normalizeRefs, $partNumberIndex, $workOrderIndex, $hasIsReleased, $hasStatus, $hasProductionDateCompleted, $hasProductionQtyCompleted, &$linked, &$skipped) {
                    DB::transaction(function () use ($orders, $templatesById, $useCustomerPartNumber, $allowWorkOrderFallback, $normalizeRefs, $partNumberIndex, $workOrderIndex, $hasIsReleased, $hasStatus, $hasProductionDateCompleted, $hasProductionQtyCompleted, &$linked, &$skipped) {
                        foreach ($orders as $order) {
                            $templateId = $order->template_route_id;

                            // 1) Match by customer_part_number (supports multiple in one field)
                            if (empty($templateId) && $useCustomerPartNumber) {
                                $refs = $normalizeRefs($order->customer_part_number ?? '');
                                foreach ($refs as $ref) {
                                    if (isset($partNumberIndex[$ref])) {
                                        $templateId = $partNumberIndex[$ref];
                                        break;
                                    }
                                }
                            }

                            // 2) Fallback match by work_order_no via template.wod_ref
                            if (!$templateId && $allowWorkOrderFallback) {
                                $wo = strtoupper(trim((string) $order->work_order_no));
                                if ($wo !== '' && isset($workOrderIndex[$wo])) {
                                    $templateId = $workOrderIndex[$wo];
                                }
                            }

                            if (!$templateId) {
                                $skipped++;
                                continue;
                            }

                            $templateData = $templatesById->get($templateId);
                            if (!$templateData) {
                                $skipped++;
                                continue;
                            }

                            $existingMetadata = $this->normalizeMetadata($order->metadata);
                            $statusLabel = trim((string) Arr::get(
                                $existingMetadata,
                                'state.status',
                                $order->status ?? ''
                            ));
                            $normalizedStatus = strtolower($statusLabel);
                            $isCompleted = in_array($normalizedStatus, ['completed', 'complete', 'done'], true);

                            if ($statusLabel === '') {
                                if ($hasProductionDateCompleted && $hasProductionQtyCompleted) {
                                    $isCompleted = $this->isProductionCompleted(
                                        $order->production_date_completed ?? null,
                                        $order->production_qty_completed ?? null
                                    );
                                } else {
                                    $isCompleted = false;
                                }
                                $statusLabel = $isCompleted ? 'Completed' : 'In Progress';
                            }

                            $completedAt = $this->normalizeCompletionDate($order->production_date_completed ?? null);

                            $order->template_route_id = $templateData['id'];
                            $metadata = $this->prepareTemplateMetadataForWorkOrder($templateData['metadata'] ?? null);
                            if ($isCompleted) {
                                $order->metadata = $this->applyCompletionToMetadata($metadata, true, $completedAt);
                            } else {
                                if ($statusLabel !== '') {
                                    $metadata['state']['status'] = $statusLabel;
                                }
                                $order->metadata = $metadata;
                            }
                            if ($hasStatus && (empty($order->status) || trim((string) $order->status) === '')) {
                                $order->status = $statusLabel;
                            }
                            if ($hasIsReleased) {
                                $statusRaw = strtolower(trim((string) Arr::get($order->metadata, 'state.status', '')));
                                $order->is_released = $this->resolveIsReleased($order, $statusRaw);
                            }
                            $order->save();

                            $linked++;
                        }
                    });
                });
        };

        if ($batchNumber === null && $hasWorkOrderBatchNumber) {
            $batchKeys = (clone $eligibleOrdersQuery)
                ->select(['batch_number'])
                ->distinct()
                ->orderBy('batch_number')
                ->pluck('batch_number')
                ->all();

            foreach ($batchKeys as $batchKey) {
                $batchKey = is_string($batchKey) ? trim($batchKey) : $batchKey;
                $batchQuery = clone $eligibleOrdersQuery;
                if ($batchKey === null || $batchKey === '') {
                    $batchQuery->whereNull('batch_number')
                        ->orWhere('batch_number', '');
                } else {
                    $batchQuery->where('batch_number', $batchKey);
                }

                $processChunk($batchQuery);
            }
        } else {
            $processChunk($eligibleOrdersQuery);
        }

        $result = [
            'linked' => $linked,
            'skipped' => $skipped,
            'eligible' => $eligibleCount,
            'template_routes' => $templates->count(),
            'reference' => $referenceMode,
        ];
        if ($batchNumber !== null)
            $result['batch_number'] = $batchNumber;
        if ($templateBatchNumber !== null)
            $result['template_batch_number'] = $templateBatchNumber;

        return $result;
    }


    public function listByBatch(string $batchNumber, int $limit = 10, int $page = 1): array
    {
        $filters = [
            'batch_number' => $batchNumber,
        ];

        return WorkOrderResource::collection(
            $this->workOrderRepository->listing($filters, ['id', 'desc'], $limit, $page)
        )->response()->getData(true);
    }

    public function replaceBatch(string $batchNumber, array $workOrders): array
    {
        $deleted = $this->workOrderRepository->deleteByBatch($batchNumber);

        $normalizedOrders = array_map(function (array $order) use ($batchNumber): array {
            $order['batch_number'] = $batchNumber;

            return $order;
        }, $workOrders);

        $result = $this->createBatch($normalizedOrders);

        return array_merge($result, [
            'batch_number' => $batchNumber,
            'deleted' => $deleted,
        ]);
    }

    public function listByTemplateRouteBatch(string $batchNumber, int $limit = 10, int $page = 1): array
    {
        $filters = [
            'template_route_batch_number' => $batchNumber,
        ];

        return WorkOrderResource::collection(
            $this->workOrderRepository->listing($filters, ['id', 'desc'], $limit, $page)
        )->response()->getData(true);
    }

    public function countByTemplateRouteBatch(string $batchNumber): int
    {
        return $this->workOrderRepository->countByTemplateRouteBatch($batchNumber);
    }

    public function summary(array $options = []): array
    {
        $onTimeDays = max(1, (int) ($options['on_time_days'] ?? 30));
        $throughputDays = max(1, (int) ($options['throughput_days'] ?? 7));
        $dueSoonDays = max(1, (int) ($options['due_soon_days'] ?? 7));
        $rangeFromRaw = $options['range_from'] ?? null;
        $rangeToRaw = $options['range_to'] ?? null;

        $asOf = now()->startOfDay();
        $onTimeStart = (clone $asOf)->subDays($onTimeDays - 1);
        $throughputStart = (clone $asOf)->subDays($throughputDays - 1);
        $dueSoonEnd = (clone $asOf)->addDays($dueSoonDays);

        $query = WorkOrder::query()
            ->select([
                'id',
                'work_order_no',
                'production_due_date',
                'requested_delivery_date',
                'order_date',
                'production_date_completed',
                'quantity_to_produce',
                'quantity_produced',
                'production_qty_completed',
                'metadata',
                'created_at',
                'updated_at',
            ]);

        if ($rangeFromRaw || $rangeToRaw) {
            $rangeStart = $rangeFromRaw
                ? \Illuminate\Support\Carbon::parse($rangeFromRaw)->startOfDay()
                : null;
            $rangeEnd = $rangeToRaw
                ? \Illuminate\Support\Carbon::parse($rangeToRaw)->endOfDay()
                : null;
            $dateColumn = DB::raw("COALESCE(production_due_date, order_date, created_at)");
            if ($rangeStart) {
                $query->whereDate($dateColumn, '>=', $rangeStart->toDateString());
            }
            if ($rangeEnd) {
                $query->whereDate($dateColumn, '<=', $rangeEnd->toDateString());
            }
        }

        $orders = $query->get();

        $totals = [
            'total_orders' => 0,
            'open_orders' => 0,
            'completed_orders' => 0,
            'released_orders' => 0,
            'wip_orders' => 0,
            'overdue_orders' => 0,
            'due_soon_orders' => 0,
            'planned_units' => 0.0,
            'produced_units' => 0.0,
            'scrap_units' => 0.0,
            'wip_units' => 0.0,
        ];

        $statusCounts = [];
        $wipByCenter = [];
        $scrapReasons = [];
        $throughputBuckets = $this->buildDateBuckets($throughputStart, $throughputDays);

        $workOrderNos = $orders->pluck('work_order_no')->filter()->values();
        $packingSet = $workOrderNos->isEmpty()
            ? collect()
            : PackingChecklist::query()
                ->whereIn('work_order_no', $workOrderNos)
                ->pluck('work_order_no')
                ->flip();

        $onTimeEligible = 0;
        $onTimeHit = 0;
        $leadTimeSum = 0.0;
        $leadTimeCount = 0;

        foreach ($orders as $order) {
            $totals['total_orders']++;

            $metadata = $this->normalizeMetadata($order->metadata);
            $state = is_array($metadata['state'] ?? null) ? $metadata['state'] : [];
            $currentStep = $state['currentStep'] ?? null;

            $routes = $this->extractRoutes($metadata);
            $routesCompleted = $this->routesCompleted($routes);

            $completionDate = $this->resolveCompletionDate($order, $routes);
            $isCompleted = $completionDate !== null || $routesCompleted;

            $hasPacking = $order->work_order_no
                ? $packingSet->has($order->work_order_no)
                : false;
            $hasPackingSpecs = $order->customer_part_number
                ? $packingSpecSet->has($order->customer_part_number)
                : false;
            $status = $this->resolveWorkflowStatus($order, $metadata, $routes, $hasPacking, $hasPackingSpecs);

            $planned = $this->numericValue($order->quantity_to_produce);
            if ($planned <= 0) {
                $planned = $this->numericValue($state['qty'] ?? null);
            }

            $produced = $this->numericValue($order->quantity_produced);
            if ($produced <= 0) {
                $produced = $this->numericValue($order->production_qty_completed);
            }
            if ($produced <= 0) {
                $produced = $this->numericValue($state['quantityProduced'] ?? null);
            }

            $totals['planned_units'] += $planned;
            $totals['produced_units'] += $produced;

            $scrapTotal = $this->accumulateScrap($routes, $scrapReasons);
            $totals['scrap_units'] += $scrapTotal;

            if ($isCompleted) {
                $totals['completed_orders']++;
            } else {
                $totals['open_orders']++;
                $totals['wip_units'] += $planned;
            }

            if ($status === 'In Progress') {
                $totals['released_orders']++;
                $totals['wip_orders']++;
            }

            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

            $dueDate = $this->resolveDueDate($order);
            if (!$isCompleted && $dueDate) {
                if ($dueDate->lt($asOf)) {
                    $totals['overdue_orders']++;
                } elseif ($dueDate->lte($dueSoonEnd)) {
                    $totals['due_soon_orders']++;
                }
            }

            if (!$isCompleted) {
                $activeRoute = $this->resolveActiveRoute($routes, $currentStep);
                $centerLabel = $this->resolveWorkCenter($activeRoute);
                if ($centerLabel === '') {
                    $centerLabel = 'Unassigned';
                }
                $wipByCenter[$centerLabel] = ($wipByCenter[$centerLabel] ?? 0) + 1;
            }

            if ($completionDate) {
                $completionDay = $completionDate->copy()->startOfDay();

                if ($completionDay->gte($throughputStart) && $completionDay->lte($asOf)) {
                    $key = $completionDay->toDateString();
                    if (isset($throughputBuckets[$key])) {
                        $throughputBuckets[$key]['count'] += 1;
                        $throughputBuckets[$key]['units'] += $produced > 0 ? $produced : $planned;
                    }
                }

                if ($completionDay->gte($onTimeStart) && $completionDay->lte($asOf)) {
                    if ($dueDate) {
                        $onTimeEligible++;
                        if ($completionDay->lte($dueDate)) {
                            $onTimeHit++;
                        }
                    }

                    $orderDate = $this->resolveOrderDate($order);
                    if ($orderDate) {
                        $leadTimeSum += $orderDate->diffInDays($completionDay);
                        $leadTimeCount++;
                    }
                }
            }
        }

        $goodUnits = max($totals['planned_units'] - $totals['scrap_units'], 0);
        $yieldRate = $totals['planned_units'] > 0
            ? ($goodUnits / $totals['planned_units']) * 100
            : 0;

        $onTimeRate = $onTimeEligible > 0 ? ($onTimeHit / $onTimeEligible) * 100 : 0;
        $avgLeadTime = $leadTimeCount > 0 ? $leadTimeSum / $leadTimeCount : 0;

        $statusSeries = $this->buildStatusSeries($statusCounts);
        $wipSeries = $this->buildSortedSeries($wipByCenter, 'center', 8);
        $scrapSeries = $this->buildSortedSeries($scrapReasons, 'reason', 6);
        $throughputSeries = array_values($throughputBuckets);

        $futureOrders = max($totals['open_orders'] - $totals['overdue_orders'] - $totals['due_soon_orders'], 0);

        $throughputOrders = array_reduce($throughputSeries, function ($sum, $row) {
            return $sum + ($row['count'] ?? 0);
        }, 0);
        $throughputUnits = array_reduce($throughputSeries, function ($sum, $row) {
            return $sum + ($row['units'] ?? 0);
        }, 0.0);

        return [
            'summary' => [
                'total_orders' => $totals['total_orders'],
                'open_orders' => $totals['open_orders'],
                'completed_orders' => $totals['completed_orders'],
                'released_orders' => $totals['released_orders'],
                'wip_orders' => $totals['wip_orders'],
                'overdue_orders' => $totals['overdue_orders'],
                'due_soon_orders' => $totals['due_soon_orders'],
                'planned_units' => round($totals['planned_units'], 2),
                'produced_units' => round($totals['produced_units'], 2),
                'wip_units' => round($totals['wip_units'], 2),
                'good_units' => round($goodUnits, 2),
                'scrap_units' => round($totals['scrap_units'], 2),
                'yield_rate' => round($yieldRate, 1),
                'on_time_rate' => round($onTimeRate, 1),
                'avg_lead_time_days' => round($avgLeadTime, 1),
                'throughput_orders' => $throughputOrders,
                'throughput_units' => round($throughputUnits, 2),
                'as_of' => $asOf->toDateString(),
            ],
            'charts' => [
                'status' => $statusSeries,
                'wip_by_center' => $wipSeries,
                'throughput' => $throughputSeries,
                'scrap_reasons' => $scrapSeries,
                'due_risk' => [
                    ['bucket' => 'Overdue', 'count' => $totals['overdue_orders']],
                    ['bucket' => 'Due Soon', 'count' => $totals['due_soon_orders']],
                    ['bucket' => 'Future', 'count' => $futureOrders],
                ],
            ],
            'window' => [
                'on_time_days' => $onTimeDays,
                'throughput_days' => $throughputDays,
                'due_soon_days' => $dueSoonDays,
            ],
        ];
    }

    public function calendarSummary(array $options = []): array
    {
        $fromRaw = $options['from'] ?? null;
        $toRaw = $options['to'] ?? null;
        $upcomingDays = max(1, (int) ($options['upcoming_days'] ?? 5));
        $filters = is_array($options['filters'] ?? null) ? $options['filters'] : [];

        $start = $fromRaw ? \Illuminate\Support\Carbon::parse($fromRaw)->startOfDay() : now()->startOfMonth();
        $end = $toRaw ? \Illuminate\Support\Carbon::parse($toRaw)->startOfDay() : now()->endOfMonth()->startOfDay();
        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        $days = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $days[$key] = [
                'date' => $key,
                'total' => 0,
                'due_today' => 0,
                'upcoming_due' => 0,
                'status' => [
                    'backlog' => 0,
                    'in_progress' => 0,
                    'completed' => 0,
                ],
            ];
            $cursor->addDay();
        }

        $query = WorkOrder::query()->select([
            'id',
            'work_order_no',
            'order_date',
            'production_start_date',
            'production_due_date',
            'requested_delivery_date',
            'production_date_completed',
            'status',
            'priority',
            'is_starred',
            'metadata',
            'created_at',
        ]);

        if ($workOrderNo = Arr::get($filters, 'work_order_no')) {
            $query->where('work_order_no', 'LIKE', "%{$workOrderNo}%");
        }
        if ($customerCode = Arr::get($filters, 'customer_code')) {
            $query->where('customer_code', 'LIKE', "%{$customerCode}%");
        }
        if ($customerName = Arr::get($filters, 'customer_name')) {
            $query->where('customer_name', 'LIKE', "%{$customerName}%");
        }
        if ($customerPartNumber = Arr::get($filters, 'customer_part_number')) {
            $query->where('customer_part_number', 'LIKE', "%{$customerPartNumber}%");
        }
        $statusFilter = Arr::get($filters, 'status');
        $normalizedStatusFilter = $statusFilter !== null
            ? strtolower(trim((string) $statusFilter))
            : '';

        $scheduleFrom = $start->toDateString();
        $scheduleTo = $end->toDateString();
        $query->where(function ($range) use ($scheduleFrom, $scheduleTo) {
            $startField = DB::raw("COALESCE(production_start_date, production_due_date)");
            $range->whereDate($startField, '<=', $scheduleTo);
            $range->where(function ($overlap) use ($scheduleFrom, $startField) {
                $overlap->whereDate('production_due_date', '>=', $scheduleFrom)
                    ->orWhereDate('requested_delivery_date', '>=', $scheduleFrom)
                    ->orWhereDate($startField, '>=', $scheduleFrom);
            });
        });

        $orders = $query->get();
        $workOrderNos = $orders->pluck('work_order_no')->filter()->values();
        $packingSet = $workOrderNos->isEmpty()
            ? collect()
            : PackingChecklist::query()
                ->whereIn('work_order_no', $workOrderNos)
                ->pluck('work_order_no')
                ->flip();

        $dueCounts = array_fill_keys(array_keys($days), 0);

        foreach ($orders as $order) {
            $status = (string) ($order->status ?? '');
            if ($normalizedStatusFilter !== '' && strtolower($status) !== $normalizedStatusFilter) {
                continue;
            }
            $displayStatus = $this->resolveDisplayStatus($order);
            $bucket = $this->mapCalendarStatus($displayStatus);

            $orderDate = $this->resolveScheduleStartDate($order);
            $dueDate = $this->resolveDueDate($order);
            if (!$dueDate && $orderDate) {
                $dueDate = $orderDate->copy();
            }
            if (!$orderDate && $dueDate) {
                $orderDate = $dueDate->copy();
            }
            if (!$orderDate || !$dueDate) {
                continue;
            }

            $effectiveStart = $orderDate->gt($start) ? $orderDate->copy() : $start->copy();
            $effectiveEnd = $dueDate->lt($end) ? $dueDate->copy() : $end->copy();
            if ($effectiveEnd->lt($effectiveStart)) {
                continue;
            }

            if (isset($days[$dueDate->toDateString()])) {
                $dueCounts[$dueDate->toDateString()]++;
            }

            $walk = $effectiveStart->copy();
            while ($walk->lte($effectiveEnd)) {
                $key = $walk->toDateString();
                if (isset($days[$key])) {
                    $days[$key]['total'] += 1;
                    $days[$key]['status'][$bucket] =
                        ($days[$key]['status'][$bucket] ?? 0) + 1;
                }
                $walk->addDay();
            }
        }

        $keys = array_keys($days);
        $prefix = [0];
        foreach ($keys as $idx => $key) {
            $prefix[$idx + 1] = $prefix[$idx] + ($dueCounts[$key] ?? 0);
        }

        $maxDue = 0;
        $maxUpcoming = 0;
        $maxTotal = 0;
        foreach ($keys as $idx => $key) {
            $dueToday = $dueCounts[$key] ?? 0;
            $endIdx = min($idx + $upcomingDays, count($keys) - 1);
            $upcoming =
                $prefix[$endIdx + 1] - $prefix[$idx + 1];
            $days[$key]['due_today'] = $dueToday;
            $days[$key]['upcoming_due'] = $upcoming;

            $maxDue = max($maxDue, $dueToday);
            $maxUpcoming = max($maxUpcoming, $upcoming);
            $maxTotal = max($maxTotal, $days[$key]['total'] ?? 0);
        }

        return [
            'range' => [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'upcoming_days' => $upcomingDays,
            ],
            'max' => [
                'due_today' => $maxDue,
                'upcoming_due' => $maxUpcoming,
                'total' => $maxTotal,
            ],
            'days' => array_values($days),
        ];
    }

    public function calendarDayOrders(array $options = []): array
    {
        $dateRaw = $options['date'] ?? $options['day'] ?? null;
        $upcomingDays = max(1, (int) ($options['upcoming_days'] ?? 5));
        $filters = is_array($options['filters'] ?? null) ? $options['filters'] : [];
        $viewRaw = strtolower(trim((string) ($options['view'] ?? 'due')));
        $view = in_array($viewRaw, ['all', 'due', 'upcoming', 'normal'], true)
            ? $viewRaw
            : 'due';
        $sortBy = strtolower(trim((string) ($options['sort_by'] ?? $options['sort'] ?? '')));
        $sortDir = strtolower(trim((string) ($options['sort_dir'] ?? '')));
        $limit = (int) ($options['limit'] ?? 15);
        $limit = max(1, min($limit, 1000));
        $page = max(1, (int) ($options['page'] ?? 1));
        $rangeFromRaw = $options['range_from'] ?? null;
        $rangeToRaw = $options['range_to'] ?? null;

        $day = $dateRaw
            ? \Illuminate\Support\Carbon::parse($dateRaw)->startOfDay()
            : now()->startOfDay();
        $rangeStart = $rangeFromRaw
            ? \Illuminate\Support\Carbon::parse($rangeFromRaw)->startOfDay()
            : null;
        $rangeEnd = $rangeToRaw
            ? \Illuminate\Support\Carbon::parse($rangeToRaw)->startOfDay()
            : null;

        $query = WorkOrder::query()
            ->with(['templateRoute:id,template'])
            ->select([
                'id',
                'template_route_id',
                'work_order_no',
                'customer_code',
                'customer_name',
                'customer_part_number',
                'order_date',
                'production_start_date',
                'production_due_date',
                'requested_delivery_date',
                'production_date_completed',
                'status',
                'priority',
                'is_starred',
                'metadata',
            ]);

        if ($workOrderNo = Arr::get($filters, 'work_order_no')) {
            $query->where('work_order_no', 'LIKE', "%{$workOrderNo}%");
        }
        if ($customerCode = Arr::get($filters, 'customer_code')) {
            $query->where('customer_code', 'LIKE', "%{$customerCode}%");
        }
        if ($customerName = Arr::get($filters, 'customer_name')) {
            $query->where('customer_name', 'LIKE', "%{$customerName}%");
        }
        if ($customerPartNumber = Arr::get($filters, 'customer_part_number')) {
            $query->where('customer_part_number', 'LIKE', "%{$customerPartNumber}%");
        }
        if ($templateRouteId = Arr::get($filters, 'template_route_id')) {
            $query->where('template_route_id', $templateRouteId);
        }
        $dayKey = $day->toDateString();
        $query->where(function ($range) use ($dayKey) {
            $startField = DB::raw("COALESCE(production_start_date, production_due_date)");
            $range->whereDate($startField, '<=', $dayKey);
            $range->where(function ($overlap) use ($dayKey) {
                $overlap->whereDate('production_due_date', '>=', $dayKey)
                    ->orWhereDate('requested_delivery_date', '>=', $dayKey)
                    ->orWhereNull('production_due_date')
                    ->orWhereNull('requested_delivery_date');
            });
        });

        $orders = $query->orderBy('production_due_date')
            ->orderByRaw("COALESCE(production_start_date, production_due_date)")
            ->get();

        $workOrderNos = $orders->pluck('work_order_no')->filter()->values();
        $packingSet = $workOrderNos->isEmpty()
            ? collect()
            : PackingChecklist::query()
                ->whereIn('work_order_no', $workOrderNos)
                ->pluck('work_order_no')
                ->flip();
        $partNos = $orders->pluck('customer_part_number')->filter()->values();
        $packingSpecSet = $partNos->isEmpty()
            ? collect()
            : Packing::query()
                ->whereIn('wd_part_no', $partNos)
                ->pluck('wd_part_no')
                ->flip();

        $items = [];
        $counts = [
            'total' => 0,
            'due' => 0,
            'upcoming' => 0,
            'normal' => 0,
        ];

        foreach ($orders as $order) {
            $metadata = is_array($order->metadata) ? $order->metadata : [];
            $routes = $this->extractRoutes($metadata);
            $status = (string) ($order->status ?? '');
            $displayStatus = $this->resolveDisplayStatus($order);

            $orderDate = $this->resolveScheduleStartDate($order);
            $dueDate = $this->resolveDueDate($order);
            if (!$dueDate && $orderDate) {
                $dueDate = $orderDate->copy();
            }
            if (!$orderDate || !$dueDate) {
                continue;
            }

            if ($orderDate->gt($day) || $dueDate->lt($day)) {
                continue;
            }

            if ($rangeStart && $dueDate->lt($rangeStart)) {
                continue;
            }
            if ($rangeEnd && $orderDate->gt($rangeEnd)) {
                continue;
            }

            $bucket = 'normal';
            if ($dueDate->equalTo($day)) {
                $bucket = 'due';
            } elseif ($dueDate->gt($day) && $dueDate->lte($day->copy()->addDays($upcomingDays))) {
                $bucket = 'upcoming';
            }

            $counts['total'] += 1;
            $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;

            $routePreview = $this->buildRoutePreview($order->metadata);
            $hasRouteLink = !empty($order->template_route_id) || count($routePreview) > 0;

            $items[] = [
                'id' => $order->id,
                'work_order_no' => $order->work_order_no,
                'customer_code' => $order->customer_code,
                'customer_name' => $order->customer_name,
                'customer_part_number' => $order->customer_part_number,
                'template_route_id' => $order->template_route_id,
                'template' => $order->templateRoute?->template,
                'order_date' => $orderDate->toDateString(),
                'production_start_date' => $order->production_start_date,
                'production_due_date' => $dueDate->toDateString(),
                'requested_delivery_date' => $order->requested_delivery_date,
                'status' => $status,
                'display_status' => $displayStatus,
                'priority' => $order->priority,
                'is_starred' => (bool) ($order->is_starred ?? false),
                'bucket' => $bucket,
                'has_route_link' => $hasRouteLink,
                'route_link' => $hasRouteLink ? 1 : 0,
                'routes_total' => count($routePreview),
                'routes' => $routePreview,
            ];
        }

        if ($statusFilter = Arr::get($filters, 'status')) {
            $normalizedFilter = strtolower(trim((string) $statusFilter));
            $items = array_values(array_filter($items, static function ($item) use ($normalizedFilter): bool {
                $current = strtolower(trim((string) ($item['status'] ?? '')));
                return $current === $normalizedFilter;
            }));

            $counts = [
                'total' => 0,
                'due' => 0,
                'upcoming' => 0,
                'normal' => 0,
            ];
            foreach ($items as $item) {
                $bucket = $item['bucket'] ?? 'normal';
                $counts['total'] += 1;
                $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
            }
        }

        if ($view !== 'all') {
            $items = array_values(array_filter($items, static fn($item) => $item['bucket'] === $view));
        }

        $sortBy = in_array($sortBy, ['route_link', 'status'], true) ? $sortBy : '';
        if ($sortBy !== '') {
            if ($sortDir === '') {
                $sortDir = $sortBy === 'route_link' ? 'desc' : 'asc';
            }
            $sortDir = $sortDir === 'desc' ? 'desc' : 'asc';
            $statusOrder = [
                'Backlog' => 1,
                'In Progress' => 2,
                'Completed' => 3,
            ];
            usort($items, static function ($a, $b) use ($sortBy, $sortDir, $statusOrder) {
                if ($sortBy === 'route_link') {
                    $left = !empty($a['has_route_link']) ? 1 : 0;
                    $right = !empty($b['has_route_link']) ? 1 : 0;
                    if ($left !== $right) {
                        return $sortDir === 'desc' ? $right <=> $left : $left <=> $right;
                    }
                }
                if ($sortBy === 'status') {
                    $left = $statusOrder[$a['status'] ?? ''] ?? 99;
                    $right = $statusOrder[$b['status'] ?? ''] ?? 99;
                    if ($left !== $right) {
                        return $sortDir === 'desc' ? $right <=> $left : $left <=> $right;
                    }
                }
                $leftDate = $a['production_due_date'] ?? '';
                $rightDate = $b['production_due_date'] ?? '';
                return $leftDate <=> $rightDate;
            });
        }

        $customers = [];
        foreach ($items as $item) {
            $name = $item['customer_name'] ?? null;
            if (!$name) {
                continue;
            }
            $key = strtolower($item['customer_code'] ?? $name);
            if (!isset($customers[$key])) {
                $customers[$key] = [
                    'name' => $name,
                    'code' => $item['customer_code'] ?? null,
                ];
            }
        }
        $customerList = array_values($customers);

        $total = count($items);
        $lastPage = max(1, (int) ceil($total / $limit));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $limit;
        $items = array_slice($items, $offset, $limit);

        return [
            'date' => $day->toDateString(),
            'view' => $view,
            'upcoming_days' => $upcomingDays,
            'counts' => $counts,
            'data' => $items,
            'customers' => $customerList,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $limit,
                'total' => $total,
            ],
        ];
    }

    public function collectionReport(array $options = []): array
    {
        $asOfRaw = $options['as_of'] ?? null;
        $filters = is_array($options['filters'] ?? null) ? $options['filters'] : [];
        $upcomingDays = max(1, (int) ($options['upcoming_days'] ?? 5));
        $asOf = $asOfRaw
            ? \Illuminate\Support\Carbon::parse($asOfRaw)->startOfDay()
            : now()->startOfDay();

        $buckets = [
            ['key' => 'd1_10', 'label' => '1-10 Days', 'min' => 1, 'max' => 10],
            ['key' => 'd11_20', 'label' => '11-20 Days', 'min' => 11, 'max' => 20],
            ['key' => 'd21_30', 'label' => '21-30 Days', 'min' => 21, 'max' => 30],
            ['key' => 'd31_plus', 'label' => '30+ Days', 'min' => 31, 'max' => null],
        ];
        $types = [
            ['key' => 'normal', 'label' => 'Normal'],
            ['key' => 'due', 'label' => 'DueDate'],
            ['key' => 'upcoming', 'label' => 'Upcoming'],
        ];
        $bucketKeys = array_column($buckets, 'key');
        $typeKeys = array_column($types, 'key');

        $query = WorkOrder::query()->select([
            'id',
            'work_order_no',
            'customer_code',
            'customer_name',
            'order_date',
            'production_due_date',
            'requested_delivery_date',
            'production_date_completed',
            'status',
            'metadata',
        ]);

        if ($workOrderNo = Arr::get($filters, 'work_order_no')) {
            $query->where('work_order_no', 'LIKE', "%{$workOrderNo}%");
        }
        if ($customerCode = Arr::get($filters, 'customer_code')) {
            $query->where('customer_code', 'LIKE', "%{$customerCode}%");
        }
        if ($customerName = Arr::get($filters, 'customer_name')) {
            $query->where('customer_name', 'LIKE', "%{$customerName}%");
        }
        if ($customerPartNumber = Arr::get($filters, 'customer_part_number')) {
            $query->where('customer_part_number', 'LIKE', "%{$customerPartNumber}%");
        }
        if ($statusFilter = Arr::get($filters, 'status')) {
            $query->where('status', $statusFilter);
        }

        $query->whereNull('production_date_completed');

        $orders = $query->get();
        $rows = [];
        $totals = [
            'total' => 0,
            'buckets' => array_fill_keys($bucketKeys, array_fill_keys($typeKeys, 0)),
        ];

        foreach ($orders as $order) {
            $orderDate = $this->resolveOrderDate($order);
            if (!$orderDate) {
                continue;
            }
            $orderDate = $orderDate->copy()->startOfDay();
            $ageDays = $orderDate->diffInDays($asOf, false);
            if ($ageDays < 0) {
                continue;
            }
            $ageDays = max(1, $ageDays);

            $dueDate = $this->resolveDueDate($order) ?? $orderDate->copy();
            $dueDate = $dueDate->copy()->startOfDay();
            $daysToDue = $asOf->diffInDays($dueDate, false);

            $typeKey = 'normal';
            if ($daysToDue === 0) {
                $typeKey = 'due';
            } elseif ($daysToDue < 0 && abs($daysToDue) <= $upcomingDays) {
                $typeKey = 'upcoming';
            }

            $bucketKey = null;
            foreach ($buckets as $bucket) {
                $min = $bucket['min'];
                $max = $bucket['max'];
                if ($ageDays < $min) {
                    continue;
                }
                if ($max !== null && $ageDays > $max) {
                    continue;
                }
                $bucketKey = $bucket['key'];
                break;
            }
            if (!$bucketKey) {
                continue;
            }

            $customerCode = $order->customer_code ? trim((string) $order->customer_code) : null;
            $customerName = $order->customer_name ? trim((string) $order->customer_name) : null;
            $customerKey = strtolower(trim((string) ($customerCode ?: $customerName ?: 'unknown')));

            if (!isset($rows[$customerKey])) {
                $rows[$customerKey] = [
                    'customer_key' => $customerKey,
                    'customer_code' => $customerCode,
                    'customer_name' => $customerName ?: 'Unknown',
                    'total' => 0,
                    'buckets' => array_fill_keys($bucketKeys, array_fill_keys($typeKeys, 0)),
                ];
            }

            $rows[$customerKey]['total'] += 1;
            $rows[$customerKey]['buckets'][$bucketKey][$typeKey] += 1;
            $totals['total'] += 1;
            $totals['buckets'][$bucketKey][$typeKey] += 1;
        }

        $rows = array_values($rows);
        usort($rows, static function (array $a, array $b): int {
            return ($b['total'] ?? 0) <=> ($a['total'] ?? 0);
        });

        return [
            'as_of' => $asOf->toDateString(),
            'upcoming_days' => $upcomingDays,
            'buckets' => array_map(static fn($bucket) => [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'min' => $bucket['min'],
                'max' => $bucket['max'],
            ], $buckets),
            'types' => $types,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    protected function normalizeTemplateMetadata(mixed $metadata): mixed
    {
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $metadata;
    }

    protected function prepareTemplateMetadataForWorkOrder(
        mixed $metadata,
        ?string $defaultStatus = null
    ): array {
        $normalized = $this->normalizeTemplateMetadata($metadata);
        if (!is_array($normalized)) {
            $normalized = [];
        }

        if ($this->isListArray($normalized)) {
            $normalized = ['routes' => $normalized];
        }

        if (!isset($normalized['state']) || !is_array($normalized['state'])) {
            $normalized['state'] = [];
        }

        if (array_key_exists('historicaldata', $normalized)) {
            unset($normalized['historicaldata']);
        }

        if ($defaultStatus !== null) {
            $current = $normalized['state']['status'] ?? null;
            if (!is_string($current) || trim($current) === '') {
                $normalized['state']['status'] = $defaultStatus;
            }
        }

        return $normalized;
    }

    protected function isListArray(array $value): bool
    {
        $index = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $index) {
                return false;
            }
            $index++;
        }

        return true;
    }

    protected function syncTemplateMetadata(array &$data): void
    {
        if (!empty($data['template_route_id'])) {
            $templateRoute = TemplateRoute::findOrFail($data['template_route_id']);
            $isCompleted = $this->isProductionCompleted(
                $data['production_date_completed'] ?? null,
                $data['production_qty_completed'] ?? null
            );
            $completedAt = $this->normalizeCompletionDate($data['production_date_completed'] ?? null);
            // reuse template metadata so work orders stay in sync with the chosen template
            $metadata = $this->prepareTemplateMetadataForWorkOrder(
                $templateRoute->metadata,
                $isCompleted ? 'Completed' : 'In Progress'
            );
            $data['metadata'] = $this->applyCompletionToMetadata($metadata, $isCompleted, $completedAt);
        }
    }

    protected function syncReleaseFlag(array &$data): void
    {
        $isCompleted = $this->isProductionCompleted(
            $data['production_date_completed'] ?? null,
            $data['production_qty_completed'] ?? null
        );
        $shouldForceStatus = array_key_exists('production_date_completed', $data)
            || array_key_exists('production_qty_completed', $data);
        $completedAt = $this->normalizeCompletionDate($data['production_date_completed'] ?? null);

        if ($isCompleted) {
            $data['is_released'] = true;
            if (Schema::hasColumn('work_orders', 'completed_at')) {
                $data['completed_at'] = $completedAt;
            }
            if (Schema::hasColumn('work_orders', 'status')) {
                $data['status'] = 'Completed';
            }
            if (array_key_exists('metadata', $data)) {
                $metadata = $this->normalizeMetadata($data['metadata']);
                $data['metadata'] = $this->applyCompletionToMetadata($metadata, true, $completedAt);
            }
            return;
        }

        if ($shouldForceStatus && Schema::hasColumn('work_orders', 'status')) {
            $data['status'] = 'In Progress';
        }

        if (array_key_exists('is_released', $data)) {
            $data['is_released'] = (bool) $data['is_released'];
            return;
        }

        $metadata = $this->normalizeMetadata($data['metadata'] ?? null);
        $statusRaw = strtolower(trim((string) Arr::get($metadata, 'state.status', '')));
        if ($statusRaw === '') {
            return;
        }

        $normalized = str_replace(['-', '_'], ' ', $statusRaw);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        if (in_array($normalized, ['draft', 'backlog', 'new', 'planned', 'plan', 'hold', 'on hold', 'blocked', 'paused'], true)) {
            $data['is_released'] = false;
            return;
        }

        if (in_array($normalized, ['released', 'in progress', 'active', 'completed', 'complete', 'done'], true)) {
            $data['is_released'] = true;
        }
    }

    protected function applyProductionStartDate(int $id, array &$data): void
    {
        if (!Schema::hasColumn('work_orders', 'production_start_date')) {
            return;
        }

        $explicitStart = $data['production_start_date'] ?? null;
        if ($explicitStart) {
            return;
        }

        $statusRaw = strtolower(trim((string) ($data['status'] ?? '')));
        $metadata = $this->normalizeMetadata($data['metadata'] ?? null);
        $metaStatus = strtolower(trim((string) Arr::get($metadata, 'state.status', '')));
        $statusCandidate = $statusRaw !== '' ? $statusRaw : $metaStatus;
        $isReleaseStatus = $statusCandidate !== '' && str_contains($statusCandidate, 'release');
        $releaseFlag = array_key_exists('is_released', $data) ? (bool) $data['is_released'] : null;

        if (!$isReleaseStatus && $releaseFlag !== true) {
            return;
        }

        $order = $this->workOrderRepository->findById($id);
        if (!$order) {
            return;
        }
        if (!empty($order->production_start_date)) {
            return;
        }

        $wasReleased = (bool) ($order->is_released ?? false);
        $wasStatus = strtolower(trim((string) ($order->status ?? '')));
        $statusChangedToReleased = $isReleaseStatus && !str_contains($wasStatus, 'release');

        if ($releaseFlag === true && !$wasReleased) {
            $data['production_start_date'] = now()->toDateString();
            return;
        }

        if ($statusChangedToReleased) {
            $data['production_start_date'] = now()->toDateString();
        }
    }

    protected function shouldReleaseByCompletion(array $data): bool
    {
        return $this->isProductionCompleted(
            $data['production_date_completed'] ?? null,
            $data['production_qty_completed'] ?? null
        );
    }

    protected function isProductionCompleted(mixed $date, mixed $qty): bool
    {
        $completedAt = $this->normalizeCompletionDate($date);
        if ($completedAt === null) {
            return false;
        }

        return $this->numericValue($qty) > 0;
    }

    protected function normalizeCompletionDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $trimmed);
        if ($parsed && $parsed->format('Y-m-d') === $trimmed) {
            return $trimmed;
        }

        try {
            return (new \DateTimeImmutable($trimmed))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    protected function applyCompletionToMetadata(array $metadata, bool $isCompleted, ?string $completedAt): array
    {
        if ($this->isListArray($metadata)) {
            $metadata = ['routes' => $metadata];
        }

        if (!isset($metadata['state']) || !is_array($metadata['state'])) {
            $metadata['state'] = [];
        }

        $metadata['state']['status'] = $isCompleted ? 'Completed' : 'In Progress';

        if ($isCompleted) {
            $metadata = $this->markMetadataRoutesCompleted($metadata, $completedAt);
        }

        return $metadata;
    }

    protected function markMetadataRoutesCompleted(array $metadata, ?string $completedAt): array
    {
        $routes = $metadata['routes'] ?? null;
        if (!is_array($routes)) {
            return $metadata;
        }

        foreach ($routes as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (array_key_exists('routes', $entry) && is_array($entry['routes'])) {
                foreach ($entry['routes'] as $routeIndex => $route) {
                    if (!is_array($route)) {
                        continue;
                    }
                    $entry['routes'][$routeIndex] = $this->markRouteCompleted($route, $completedAt);
                }
                $routes[$index] = $entry;
                continue;
            }

            $routes[$index] = $this->markRouteCompleted($entry, $completedAt);
        }

        $metadata['routes'] = $routes;

        return $metadata;
    }

    protected function markRouteCompleted(array $route, ?string $completedAt): array
    {
        $route['status'] = 'completed';
        if ($completedAt !== null && $completedAt !== '') {
            $route['completed_at'] = $completedAt;
        }

        return $route;
    }


    protected function syncCustomerSnapshot(array &$data): void
    {
        if (!empty($data['customer_id']) && (empty($data['customer_code']) || empty($data['customer_name']))) {
            $customer = Customer::select('customer_code', 'customer_name')->findOrFail($data['customer_id']);
            $data['customer_code'] = $data['customer_code'] ?? $customer->customer_code;
            $data['customer_name'] = $data['customer_name'] ?? $customer->customer_name;
        }
    }

    protected function ensureBatchNumber(array &$data): void
    {
        if (array_key_exists('batch_number', $data) && !empty($data['batch_number'])) {
            return;
        }

        $data['batch_number'] = now()->format('dmy\THi');
    }

    protected function buildCompositeKey(array $workOrder): string
    {
        $parts = [
            strtolower(trim((string) Arr::get($workOrder, 'work_order_no', ''))),
            strtolower(trim((string) Arr::get($workOrder, 'customer_code', ''))),
            strtolower(trim((string) Arr::get($workOrder, 'customer_part_number', ''))),
        ];

        return implode('|', $parts);
    }

    protected function loadExistingCompositeKeys(array $compositeKeys): array
    {
        if (empty($compositeKeys)) {
            return [];
        }

        $expression = "LOWER(CONCAT_WS('|', TRIM(COALESCE(work_order_no, '')), TRIM(COALESCE(customer_code, '')), TRIM(COALESCE(customer_part_number, ''))))";

        $rows = WorkOrder::query()
            ->selectRaw("{$expression} AS composite_key, id, batch_number, sheet")
            ->whereIn(DB::raw($expression), array_values(array_unique($compositeKeys)))
            ->orderByDesc('id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string) ($row->composite_key ?? '')));
            if ($key === '') {
                continue;
            }
            $batchKey = strtolower(trim((string) ($row->batch_number ?? '')));
            $sheetDate = $this->extractSheetDate($row->sheet ?? null);
            $map[$key] ??= [];
            if (!isset($map[$key][$batchKey])) {
                $map[$key][$batchKey] = [
                    'id' => $row->id,
                    'sheet_date' => $sheetDate,
                ];
                continue;
            }
            $existingDate = $map[$key][$batchKey]['sheet_date'] ?? null;
            if ($existingDate === null && $sheetDate !== null) {
                $map[$key][$batchKey] = [
                    'id' => $row->id,
                    'sheet_date' => $sheetDate,
                ];
            } elseif ($existingDate !== null && $sheetDate !== null && $sheetDate > $existingDate) {
                $map[$key][$batchKey] = [
                    'id' => $row->id,
                    'sheet_date' => $sheetDate,
                ];
            }
        }

        return $map;
    }

    protected function extractSheetDate(?string $sheet): ?int
    {
        if (!$sheet) {
            return null;
        }

        if (!preg_match('/(\d{6})(?!.*\d)/', $sheet, $matches)) {
            return null;
        }

        $token = $matches[1] ?? '';
        if ($token === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('dmy', $token);
        if (!$date) {
            return null;
        }

        $errors = \DateTimeImmutable::getLastErrors();
        if (!empty($errors['warning_count']) || !empty($errors['error_count'])) {
            return null;
        }

        return $date->setTime(0, 0, 0)->getTimestamp();
    }

    protected function templateRouteAppearsActive(TemplateRoute $templateRoute): bool
    {
        $metadata = $templateRoute->metadata;
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $candidates = [
            $metadata['active'] ?? null,
            $metadata['is_active'] ?? null,
            $metadata['enabled'] ?? null,
            $metadata['is_enabled'] ?? null,
            $metadata['status'] ?? null,
            $metadata['state'] ?? null,
            Arr::get($metadata, 'state.status'),
            Arr::get($metadata, 'state.active'),
        ];

        foreach ($candidates as $flag) {
            if ($flag === null) {
                continue;
            }

            if (is_bool($flag)) {
                return $flag;
            }

            if (is_numeric($flag)) {
                return (int) $flag === 1;
            }

            if (is_string($flag)) {
                $value = strtolower(trim($flag));
                if ($value === '') {
                    continue;
                }

                if (in_array($value, ['inactive', 'disabled', 'archived', 'retired'], true)) {
                    return false;
                }

                return in_array($value, ['active', 'enabled', 'published', 'in_use', 'inuse', 'true', '1'], true);
            }
        }

        // Default to active if metadata does not specify; keeps backward compatibility.
        return true;
    }

    protected function metadataPresent(mixed $metadata): bool
    {
        if (is_array($metadata)) {
            return !empty($metadata);
        }

        if (is_string($metadata)) {
            $trimmed = trim($metadata);
            if ($trimmed === '' || $trimmed === '[]' || $trimmed === '{}' || strtolower($trimmed) === 'null') {
                return false;
            }

            $decoded = json_decode($metadata, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->metadataPresent($decoded);
            }

            return true;
        }

        return !is_null($metadata);
    }

    protected function normalizeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    protected function buildRoutePreview(mixed $metadata): array
    {
        $normalized = $this->normalizeMetadata($metadata);
        if (empty($normalized)) {
            return [];
        }

        $routes =
            Arr::get($normalized, 'assignments.routes') ??
            Arr::get($normalized, 'route_assignments') ??
            Arr::get($normalized, 'routeAssignments') ??
            Arr::get($normalized, 'routes') ??
            Arr::get($normalized, 'steps') ??
            Arr::get($normalized, 'data');

        if ($routes === null && array_is_list($normalized)) {
            $routes = $normalized;
        }

        if (is_string($routes)) {
            $decoded = json_decode($routes, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $routes = $decoded;
            }
        }

        if (is_array($routes) && Arr::has($routes, 'routes')) {
            $routes = Arr::get($routes, 'routes', []);
        }

        if (!is_array($routes)) {
            return [];
        }

        $preview = [];
        foreach (array_values($routes) as $index => $route) {
            if (!is_array($route)) {
                continue;
            }

            $orderSeq = Arr::get($route, 'order_seq')
                ?? Arr::get($route, 'orderSeq')
                ?? Arr::get($route, 'seq')
                ?? ($index + 1);
            $label =
                Arr::get($route, 'name')
                ?? Arr::get($route, 'route')
                ?? Arr::get($route, 'key')
                ?? Arr::get($route, 'label')
                ?? ('Route ' . $orderSeq);
            $machine =
                Arr::get($route, 'machine')
                ?? Arr::get($route, 'machine_name')
                ?? Arr::get($route, 'metadata.machine')
                ?? Arr::get($route, 'metadata.machine_name')
                ?? Arr::get($route, 'metadata.machine_code');
            $status =
                Arr::get($route, 'status')
                ?? Arr::get($route, 'state.status')
                ?? Arr::get($route, 'progress.status')
                ?? Arr::get($route, 'metadata.status');
            $operators = Arr::get($route, 'operators');
            $operatorCount = is_array($operators) ? count($operators) : 0;

            $preview[] = [
                'order_seq' => (int) $orderSeq,
                'label' => $label,
                'route' => Arr::get($route, 'route') ?? Arr::get($route, 'key') ?? $label,
                'name' => Arr::get($route, 'name') ?? $label,
                'machine' => $machine,
                'status' => $status,
                'operators' => $operatorCount,
            ];
        }

        return $preview;
    }

    protected function syncAssignmentsFromMetadata(int $workOrderId, mixed $metadata): void
    {
        $normalized = $this->normalizeMetadata($metadata);
        $routes = Arr::get($normalized, 'assignments.routes')
            ?? Arr::get($normalized, 'route_assignments')
            ?? Arr::get($normalized, 'routeAssignments');

        if (is_null($routes)) {
            return;
        }

        $routes = is_array($routes) ? $routes : [];
        $this->syncAssignments($workOrderId, $routes);
    }

    protected function buildAssignmentRows(int $workOrderId, array $routes): array
    {
        if (empty($routes)) {
            return [];
        }

        $rows = [];
        $seen = [];
        $now = now();
        $appendRow = function (mixed $userId, string $routeKey, mixed $routeCode, mixed $routeName, mixed $orderSeq, mixed $qty = null) use (&$rows, &$seen, $now, $workOrderId): void {
            if (!$userId) {
                return;
            }

            $unique = "{$workOrderId}|{$userId}|{$routeKey}";
            if (isset($seen[$unique])) {
                return;
            }
            $seen[$unique] = true;

            $rows[] = [
                'work_order_id' => $workOrderId,
                'user_id' => (int) $userId,
                'route_key' => $routeKey,
                'route_code' => $routeCode ? (string) $routeCode : null,
                'route_name' => $routeName ? (string) $routeName : null,
                'order_seq' => $orderSeq !== null ? (int) $orderSeq : null,
                'assigned_qty' => ($qty === '' || $qty === null) ? null : (string) $qty,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        };

        foreach ($routes as $idx => $route) {
            if (!is_array($route)) {
                continue;
            }

            $orderSeq = Arr::get($route, 'order_seq')
                ?? Arr::get($route, 'orderSeq')
                ?? ($idx + 1);
            $routeCode = Arr::get($route, 'route') ?? Arr::get($route, 'key');
            $routeName = Arr::get($route, 'name');
            $routeKey = trim((string) (
                Arr::get($route, 'route_key')
                ?? Arr::get($route, 'routeKey')
                ?? ''
            ));
            if ($routeKey === '') {
                $routeKey = $this->buildAssignmentRouteKey($routeCode, $orderSeq, $idx);
            }
            $operators = Arr::get($route, 'operators', []);
            $hasExplicitOperators = array_key_exists('operators', $route);

            if (is_array($operators)) {
                foreach ($operators as $operator) {
                    if (!is_array($operator)) {
                        continue;
                    }

                    $appendRow(
                        Arr::get($operator, 'id')
                        ?? Arr::get($operator, 'user_id')
                        ?? Arr::get($operator, 'userId'),
                        $routeKey,
                        $routeCode,
                        $routeName,
                        $orderSeq,
                        Arr::get($operator, 'qty')
                    );
                }
            }

            if (!$hasExplicitOperators) {
                $appendRow(
                    Arr::get($route, 'operator_id')
                    ?? Arr::get($route, 'operatorId')
                    ?? Arr::get($route, 'user_id')
                    ?? Arr::get($route, 'metadata.machineOperatorId')
                    ?? Arr::get($route, 'machineOperatorId'),
                    $routeKey,
                    $routeCode,
                    $routeName,
                    $orderSeq
                );
            }

            $additionalMachines = Arr::get($route, 'metadata.additionalMachines')
                ?? Arr::get($route, 'additionalMachines')
                ?? [];
            if (!is_array($additionalMachines)) {
                continue;
            }

            foreach ($additionalMachines as $machine) {
                if (!is_array($machine)) {
                    continue;
                }

                $appendRow(
                    Arr::get($machine, 'operatorId')
                    ?? Arr::get($machine, 'machineOperatorId')
                    ?? Arr::get($machine, 'operator_id')
                    ?? Arr::get($machine, 'user_id')
                    ?? Arr::get($machine, 'machineDetails.operatorId')
                    ?? Arr::get($machine, 'machineDetails.machineOperatorId')
                    ?? Arr::get($machine, 'machine.operatorId')
                    ?? Arr::get($machine, 'machine.machineOperatorId')
                    ?? Arr::get($machine, 'metadata.operatorId')
                    ?? Arr::get($machine, 'metadata.machineOperatorId'),
                    $routeKey,
                    $routeCode,
                    $routeName,
                    $orderSeq,
                    Arr::get($machine, 'plannedQty')
                    ?? Arr::get($machine, 'targetPrintedQty')
                    ?? Arr::get($machine, 'quantity')
                );
            }
        }

        return $rows;
    }

    protected function buildAssignmentRouteKey(?string $routeCode, mixed $orderSeq, int $idx): string
    {
        $cleanCode = $routeCode !== null ? trim((string) $routeCode) : '';
        $seq = $orderSeq !== null && $orderSeq !== '' ? trim((string) $orderSeq) : '';

        if ($cleanCode !== '' && $seq !== '') {
            return "{$cleanCode}-{$seq}";
        }

        if ($cleanCode !== '') {
            return $cleanCode;
        }

        return "route-" . ($idx + 1);
    }

    protected function resolveMetadataAssignedWorkOrderIds(string $operatorId): array
    {
        if ($operatorId === '') {
            return [];
        }

        return WorkOrder::query()
            ->select(['id', 'metadata'])
            ->get()
            ->filter(function (WorkOrder $workOrder) use ($operatorId): bool {
                return $this->workOrderMetadataHasOperatorAssignment($workOrder->metadata, $operatorId);
            })
            ->pluck('id')
            ->map(static fn($id) => (int) $id)
            ->values()
            ->all();
    }

    protected function workOrderMetadataHasOperatorAssignment(mixed $metadata, string $operatorId): bool
    {
        $normalized = $this->normalizeMetadata($metadata);
        if (empty($normalized)) {
            return false;
        }

        $routes = Arr::get($normalized, 'assignments.routes')
            ?? Arr::get($normalized, 'route_assignments')
            ?? Arr::get($normalized, 'routeAssignments')
            ?? Arr::get($normalized, 'routes')
            ?? [];

        foreach ($this->flattenAssignmentRoutes($routes) as $route) {
            if (!is_array($route)) {
                continue;
            }

            $operators = Arr::get($route, 'operators', []);
            $hasExplicitOperators = array_key_exists('operators', $route);
            if (is_array($operators)) {
                foreach ($operators as $operator) {
                    if ($this->operatorAssignmentMatches($operator, $operatorId)) {
                        return true;
                    }
                }
            }

            if (!$hasExplicitOperators) {
                $directOperatorId = Arr::get($route, 'operator_id')
                    ?? Arr::get($route, 'operatorId')
                    ?? Arr::get($route, 'user_id')
                    ?? Arr::get($route, 'metadata.machineOperatorId')
                    ?? Arr::get($route, 'machineOperatorId');
                if ($directOperatorId !== null && (string) $directOperatorId === $operatorId) {
                    return true;
                }
            }

            $additionalMachines = Arr::get($route, 'metadata.additionalMachines')
                ?? Arr::get($route, 'additionalMachines')
                ?? [];
            if (is_array($additionalMachines)) {
                foreach ($additionalMachines as $machineAssignment) {
                    if (!is_array($machineAssignment)) {
                        continue;
                    }

                    $machineOperatorId = Arr::get($machineAssignment, 'operatorId')
                        ?? Arr::get($machineAssignment, 'machineOperatorId')
                        ?? Arr::get($machineAssignment, 'operator_id')
                        ?? Arr::get($machineAssignment, 'user_id');
                    $machineOperatorId = $machineOperatorId
                        ?? Arr::get($machineAssignment, 'machineDetails.operatorId')
                        ?? Arr::get($machineAssignment, 'machineDetails.machineOperatorId')
                        ?? Arr::get($machineAssignment, 'machine.operatorId')
                        ?? Arr::get($machineAssignment, 'machine.machineOperatorId')
                        ?? Arr::get($machineAssignment, 'metadata.operatorId')
                        ?? Arr::get($machineAssignment, 'metadata.machineOperatorId');

                    if ($machineOperatorId !== null && (string) $machineOperatorId === $operatorId) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    protected function flattenAssignmentRoutes(mixed $routes): array
    {
        if (is_string($routes)) {
            $decoded = json_decode($routes, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $routes = $decoded;
            }
        }

        if (!is_array($routes)) {
            return [];
        }

        if (Arr::has($routes, 'routes')) {
            $routes = Arr::get($routes, 'routes', []);
        }

        $flattened = [];
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }

            if (isset($route['routes']) && is_array($route['routes'])) {
                foreach ($route['routes'] as $nestedRoute) {
                    if (is_array($nestedRoute)) {
                        $flattened[] = $nestedRoute;
                    }
                }
                continue;
            }

            $flattened[] = $route;
        }

        return $flattened;
    }

    protected function operatorAssignmentMatches(mixed $operator, string $operatorId): bool
    {
        if (is_array($operator)) {
            $candidate = Arr::get($operator, 'id')
                ?? Arr::get($operator, 'user_id')
                ?? Arr::get($operator, 'userId');

            return $candidate !== null && (string) $candidate === $operatorId;
        }

        return $operator !== null && (string) $operator === $operatorId;
    }

    protected function filterValidAssignmentRows(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $userIds = array_values(array_unique(array_map(
            static fn(array $row): int => (int) ($row['user_id'] ?? 0),
            $rows
        )));

        if (empty($userIds)) {
            return [];
        }

        $validIds = User::query()
            ->whereIn('id', $userIds)
            ->pluck('id')
            ->map(static fn($id) => (int) $id)
            ->all();

        if (empty($validIds)) {
            return [];
        }

        $validMap = array_flip($validIds);

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => isset($validMap[(int) ($row['user_id'] ?? 0)])
        ));
    }

    protected function extractRoutes(array $metadata): array
    {
        $routes = $metadata['routes'] ?? $metadata['data'] ?? $metadata['steps'] ?? [];
        if (!is_array($routes)) {
            return [];
        }

        $flattened = [];
        foreach ($routes as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (array_key_exists('routes', $entry) && is_array($entry['routes'])) {
                foreach ($entry['routes'] as $route) {
                    if (is_array($route)) {
                        $flattened[] = $route;
                    }
                }
                continue;
            }

            $flattened[] = $entry;
        }

        return $flattened;
    }

    protected function resolveRouteStats(array $metadata, array $routes): array
    {
        $total = count($routes);
        $completed = 0;

        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }

            $status = strtolower(trim((string) ($route['status'] ?? '')));
            if ($status === '') {
                $completedAt = $route['completed_at'] ?? $route['completedAt'] ?? null;
                if ($completedAt) {
                    $status = 'completed';
                }
            }

            if (in_array($status, ['completed', 'complete', 'done'], true)) {
                $completed++;
            }
        }

        if ($total === 0) {
            $steps = $metadata['steps'] ?? [];
            if (is_array($steps)) {
                $total = count($steps);
            }
        }

        return [
            'total' => $total,
            'completed' => $completed,
        ];
    }

    protected function resolveRouteCompletionStats(array $routes): array
    {
        $total = 0;
        $completed = 0;
        $rollingComplete = false;
        $packingRouteComplete = false;
        $sawRolling = false;
        $sawPacking = false;
        $hasAny = false;

        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }
            $label = $route['route'] ?? $route['name'] ?? $route['key'] ?? $route['label'] ?? null;
            $token = $this->normalizeRouteToken($label);
            if ($token) {
                $hasAny = true;
            }

            $status = strtolower(trim((string) (
                Arr::get($route, 'status')
                ?? Arr::get($route, 'state.status')
                ?? Arr::get($route, 'progress.status')
                ?? Arr::get($route, 'metadata.status')
                ?? ''
            )));
            if ($status === '') {
                $completedAt = $route['completed_at'] ?? $route['completedAt'] ?? null;
                if ($completedAt) {
                    $status = 'completed';
                }
            }
            $isCompleted = in_array($status, ['completed', 'complete', 'done'], true);

            if ($token === 'rolling prep') {
                $sawRolling = true;
                if ($isCompleted) {
                    $rollingComplete = true;
                }
                continue;
            }
            if ($token === 'packing checklist') {
                $sawPacking = true;
                if ($isCompleted) {
                    $packingRouteComplete = true;
                }
                continue;
            }

            if ($label !== null) {
                $total++;
                if ($isCompleted) {
                    $completed++;
                }
            }
        }

        if (!$sawRolling) {
            $rollingComplete = true;
        }
        if (!$sawPacking) {
            $packingRouteComplete = true;
        }

        return [
            'total' => $total,
            'completed' => $completed,
            'rolling_complete' => $rollingComplete,
            'packing_route_complete' => $packingRouteComplete,
            'has_any' => $hasAny,
        ];
    }

    protected function resolveWorkflowStatus(
        WorkOrder $order,
        array $metadata,
        array $routes,
        bool $hasPacking
    ): string {
        $state = is_array($metadata['state'] ?? null) ? $metadata['state'] : [];
        $statusRaw = strtolower(trim((string) ($state['status'] ?? $metadata['status'] ?? ($order->status ?? ''))));
        $explicitCompleted = in_array($statusRaw, ['completed', 'complete', 'done'], true)
            || !empty($order->production_date_completed);
        $isReleased = $this->resolveIsReleased($order, $statusRaw);

        $routeStats = $this->resolveRouteCompletionStats($routes);
        $hasRouteLink = !empty($order->template_route_id) || $routeStats['has_any'] || $routeStats['total'] > 0;
        $allRoutesCompleted = $routeStats['total'] > 0 && $routeStats['completed'] >= $routeStats['total'];
        $rollingComplete = $routeStats['rolling_complete'];
        $packingComplete = $routeStats['packing_route_complete'] || $hasPacking;

        if ($explicitCompleted) {
            return 'Completed';
        }

        if ($allRoutesCompleted && $rollingComplete && $packingComplete) {
            return 'Completed';
        }

        $backlogRaw = in_array($statusRaw, [
            'draft',
            'planned',
            'plan',
            'new',
            'backlog',
            'hold',
            'on hold',
            'blocked',
            'paused',
        ], true);

        if ($backlogRaw || !$hasRouteLink || !$isReleased) {
            return 'Backlog';
        }

        return 'In Progress';
    }

    protected function buildVirtualizationSummary($orders): array
    {
        $uptimeSeconds = 0;
        $downtimeSeconds = 0;
        $producedTotal = 0;
        $targetTotal = 0;
        $scrapTotal = 0;
        $performanceSum = 0;
        $performanceCount = 0;
        $activeWorkOrders = 0;
        $machinesRunning = [];
        $operatorsRunning = [];
        $totalOutputToday = 0;

        $nowTs = now()->getTimestamp();
        $today = now()->toDateString();

        foreach ($orders as $order) {
            $metadata = $this->normalizeMetadata($order->metadata);
            $statusRaw = strtolower(trim((string) Arr::get($metadata, 'state.status', '')));
            if (!in_array($statusRaw, ['completed', 'complete', 'done'], true)) {
                $activeWorkOrders++;
            }

            $routes = $this->extractRoutes($metadata);
            foreach ($routes as $route) {
                if (!is_array($route)) {
                    continue;
                }

                $timeTracker = $this->resolveRouteTimeTracker($route);
                $entries = $this->normalizeTimeTrackerEntries($timeTracker['entries'] ?? []);
                $durations = $this->computeTimeTrackerDurations($entries, $nowTs);
                $uptimeSeconds += $durations['uptime'];
                $downtimeSeconds += $durations['downtime'];

                $lastEntry = $this->resolveLastTimeEntry($entries);
                $status = $this->resolveTimeTrackerStatus($lastEntry);
                if ($status === 'running') {
                    $machineLabel = $this->resolveMachineLabelFromRoute($route);
                    if ($machineLabel) {
                        $machinesRunning[$machineLabel] = true;
                    }

                    $operatorId = $this->extractOperatorId($lastEntry);
                    if ($operatorId) {
                        $operatorsRunning[$operatorId] = true;
                    }
                }

                $produced = $this->resolvePrintedQty($entries);
                if ($produced !== null) {
                    $producedTotal += $produced;
                }

                $target = $this->resolveTargetPrintedQty($entries, $metadata);
                if ($target !== null) {
                    $targetTotal += $target;
                }

                $scrap = $this->resolveRouteScrap($route);
                $scrapTotal += $scrap;

                $performance = $this->resolvePerformanceRatio($produced, $target, $entries);
                if ($performance !== null) {
                    $performanceSum += $performance;
                    $performanceCount++;
                }

                $todayOutput = $this->resolveTodayOutput($entries, $today);
                $totalOutputToday += $todayOutput;
            }
        }

        $availability =
            ($uptimeSeconds + $downtimeSeconds) > 0
            ? $uptimeSeconds / ($uptimeSeconds + $downtimeSeconds)
            : 0.0;
        $performance = $performanceCount > 0
            ? $performanceSum / $performanceCount
            : ($targetTotal > 0 ? min(1, $producedTotal / $targetTotal) : 0.0);
        $quality = $producedTotal > 0
            ? max(0, min(1, ($producedTotal - $scrapTotal) / $producedTotal))
            : 0.0;
        $oee = $availability * $performance * $quality;

        $scrapRate = $producedTotal > 0 ? $scrapTotal / $producedTotal : 0.0;

        return [
            'availability' => [
                'value' => $availability,
                'uptime_seconds' => $uptimeSeconds,
                'downtime_seconds' => $downtimeSeconds,
            ],
            'performance' => [
                'value' => $performance,
            ],
            'quality' => [
                'value' => $quality,
            ],
            'oee' => [
                'value' => $oee,
            ],
            'cards' => [
                'total_active_work_orders' => $activeWorkOrders,
                'machines_running' => count($machinesRunning),
                'operators_active' => count($operatorsRunning),
                'total_output_today' => $totalOutputToday,
                'downtime_minutes' => (int) round($downtimeSeconds / 60),
                'scrap_rate' => $scrapRate,
                'average_cycle_efficiency' => $performance,
            ],
        ];
    }

    protected function resolveRouteTimeTracker(array $route): array
    {
        $metadata = is_array($route['metadata'] ?? null) ? $route['metadata'] : [];
        $timeTracker = $metadata['timeTracker'] ?? $metadata['time_tracker'] ?? [];
        if (is_array($timeTracker)) {
            return $timeTracker;
        }

        return [];
    }

    protected function withProgressPct(array $metadata): array
    {
        $progressPct = $this->computeWorkOrderProgressPct($metadata);
        if ($progressPct === null) {
            return $metadata;
        }

        $state = is_array($metadata['state'] ?? null) ? $metadata['state'] : [];
        $state['progressPct'] = $progressPct;
        $metadata['state'] = $state;

        return $metadata;
    }

    protected function computeWorkOrderProgressPct(array $metadata): ?float
    {
        $routes = $this->extractRoutes($metadata);
        if (empty($routes)) {
            return null;
        }

        $best = null;
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }

            $timeTracker = $this->resolveRouteTimeTracker($route);
            $entries = $this->normalizeTimeTrackerEntries($timeTracker['entries'] ?? []);

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $value = $entry['route_progress_pct']
                    ?? $entry['routeProgressPct']
                    ?? $entry['operator_progress_pct']
                    ?? $entry['operatorProgressPct']
                    ?? null;
                if ($value === null) {
                    continue;
                }
                $numeric = $this->numericValue($value);
                if ($best === null || $numeric > $best) {
                    $best = $numeric;
                }
            }

            if ($best === null) {
                $direct = $this->resolveRouteProgressFallback($route);
                if ($direct !== null) {
                    $best = $direct;
                }
            }

            if ($best === null && !empty($entries)) {
                $produced = $this->resolvePrintedQty($entries);
                $target = $this->resolveTargetPrintedQty($entries, $metadata);
                if ($produced !== null && $target !== null && $target > 0) {
                    $ratio = min(1, $produced / $target);
                    $candidate = max(0, $ratio * 100);
                    if ($best === null || $candidate > $best) {
                        $best = $candidate;
                    }
                }
            }
        }

        return $best;
    }

    protected function resolveRouteProgressFallback(array $route): ?float
    {
        $candidates = [
            $route['progress_pct'] ?? null,
            $route['progressPct'] ?? null,
            Arr::get($route, 'progress.pct'),
            Arr::get($route, 'progress.percent'),
            Arr::get($route, 'state.progress_pct'),
            Arr::get($route, 'state.progressPct'),
            Arr::get($route, 'metadata.progress_pct'),
            Arr::get($route, 'metadata.progressPct'),
        ];

        foreach ($candidates as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $numeric = $this->numericValue($value);
            return max(0, min(100, $numeric));
        }

        return null;
    }

    protected function normalizeTimeTrackerEntries(mixed $entries): array
    {
        if (!is_array($entries)) {
            return [];
        }

        return array_values(array_filter($entries, static fn($entry): bool => is_array($entry)));
    }

    protected function computeTimeTrackerDurations(array $entries, int $nowTs): array
    {
        $normalized = [];
        foreach ($entries as $entry) {
            $timestamp = $this->parseTimeTrackerTimestamp($entry['at'] ?? null);
            if ($timestamp === null) {
                continue;
            }
            $normalized[] = [
                'timestamp' => $timestamp,
                'action' => strtolower(trim((string) ($entry['action'] ?? ''))),
            ];
        }

        usort($normalized, static fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

        $uptime = 0;
        $downtime = 0;
        $state = null;
        $lastTs = null;

        foreach ($normalized as $entry) {
            $ts = $entry['timestamp'];
            if ($lastTs !== null && $state !== null) {
                $delta = max(0, $ts - $lastTs);
                if ($state === 'running') {
                    $uptime += $delta;
                } elseif ($state === 'paused') {
                    $downtime += $delta;
                }
            }

            $nextState = $this->timeTrackerActionToState($entry['action']);
            if ($nextState !== null) {
                $state = $nextState;
            }
            $lastTs = $ts;
        }

        if ($lastTs !== null && in_array($state, ['running', 'paused'], true)) {
            $delta = max(0, $nowTs - $lastTs);
            if ($state === 'running') {
                $uptime += $delta;
            } else {
                $downtime += $delta;
            }
        }

        return [
            'uptime' => $uptime,
            'downtime' => $downtime,
        ];
    }

    protected function parseTimeTrackerTimestamp(?string $value): ?int
    {
        if (!$value) {
            return null;
        }
        try {
            return (int) (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function resolveLastTimeEntry(array $entries): ?array
    {
        if (empty($entries)) {
            return null;
        }

        $last = null;
        $lastTs = null;
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $action = strtolower(trim((string) ($entry['action'] ?? '')));
            if ($action !== '' && !in_array($action, ['start', 'pause', 'stop'], true)) {
                continue;
            }
            $timestamp = $this->parseTimeTrackerTimestamp($entry['at'] ?? null);
            if ($timestamp === null) {
                $last = $entry;
                continue;
            }
            if ($lastTs === null || $timestamp >= $lastTs) {
                $lastTs = $timestamp;
                $last = $entry;
            }
        }

        if ($last) {
            return $last;
        }

        return $entries[count($entries) - 1] ?? null;
    }

    protected function resolveTimeTrackerStatus(?array $entry): string
    {
        if (!$entry) {
            return 'idle';
        }
        $action = strtolower(trim((string) ($entry['action'] ?? '')));
        if ($action === 'start') {
            return 'running';
        }
        if ($action === 'pause') {
            return 'paused';
        }
        if ($action === 'stop') {
            return 'stopped';
        }
        return 'idle';
    }

    protected function resolvePrintedQty(array $entries): ?float
    {
        $max = null;
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $value = $entry['total_printed_qty'] ?? $entry['totalPrintedQty'] ?? $entry['printed_qty'] ?? $entry['printedQty'] ?? null;
            if ($value === null) {
                continue;
            }
            $numeric = $this->numericValue($value);
            if ($max === null || $numeric > $max) {
                $max = $numeric;
            }
        }

        return $max;
    }

    protected function resolveTargetPrintedQty(array $entries, array $metadata): ?float
    {
        $max = null;
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $value = $entry['target_printed_qty'] ?? $entry['targetPrintedQty'] ?? null;
            if ($value === null) {
                continue;
            }
            $numeric = $this->numericValue($value);
            if ($max === null || $numeric > $max) {
                $max = $numeric;
            }
        }

        if ($max !== null) {
            return $max;
        }

        $qty = Arr::get($metadata, 'state.qty');
        if ($qty === null || $qty === '') {
            return null;
        }

        return $this->numericValue($qty);
    }

    protected function normalizeRouteFlowMetadata(array &$metadata): void
    {
        $routesKey = $this->resolveRoutesKey($metadata);
        $routes = $metadata[$routesKey] ?? [];
        if (!is_array($routes)) {
            return;
        }

        $sequence = 1;
        foreach ($routes as $entryIndex => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (isset($entry['routes']) && is_array($entry['routes'])) {
                $normalizedRoutes = [];
                foreach ($entry['routes'] as $route) {
                    if (!is_array($route)) {
                        continue;
                    }
                    $normalizedRoutes[] = $this->normalizeRouteFlowMetadataEntry($route, $sequence);
                    $sequence++;
                }
                $entry['routes'] = $normalizedRoutes;
                $routes[$entryIndex] = $entry;
                continue;
            }

            $routes[$entryIndex] = $this->normalizeRouteFlowMetadataEntry($entry, $sequence);
            $sequence++;
        }

        $metadata[$routesKey] = $routes;
    }

    protected function normalizeRouteFlowMetadataEntry(array $route, int $sequence): array
    {
        $routeKey = trim((string) (
            $route['route_key']
            ?? $route['routeKey']
            ?? $route['key']
            ?? $route['route']
            ?? $route['name']
            ?? ''
        ));

        if ($routeKey === '') {
            $token = $this->normalizeRouteToken(
                $route['route']
                ?? $route['key']
                ?? $route['name']
                ?? null
            );
            $routeKey = $token !== null ? str_replace(' ', '_', $token) : 'route_' . $sequence;
        }

        $route['route_key'] = $routeKey;

        $orderSeq = $route['order_seq'] ?? $route['orderSeq'] ?? $sequence;
        $route['order_seq'] = (int) $orderSeq > 0 ? (int) $orderSeq : $sequence;

        $routeMetadata = is_array($route['metadata'] ?? null) ? $route['metadata'] : [];
        $routeMetadata['route_key'] = $routeMetadata['route_key'] ?? $routeKey;
        $route['metadata'] = $routeMetadata;

        return $route;
    }

    protected function resolveRouteScrap(array $route): float
    {
        $scrap = $route['scrap'] ?? 0;
        return is_numeric($scrap) ? (float) $scrap : 0.0;
    }

    protected function resolvePerformanceRatio(?float $produced, ?float $target, array $entries): ?float
    {
        if ($produced !== null && $target !== null && $target > 0) {
            return min(1, $produced / $target);
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $value = $entry['route_progress_pct'] ?? $entry['routeProgressPct'] ?? null;
            if ($value === null) {
                continue;
            }
            $numeric = $this->numericValue($value);
            return max(0, min(1, $numeric / 100));
        }

        return null;
    }

    protected function resolveTodayOutput(array $entries, string $today): float
    {
        $max = null;
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $timestamp = $entry['at'] ?? null;
            if (!$timestamp) {
                continue;
            }
            try {
                $date = (new \DateTimeImmutable($timestamp))->format('Y-m-d');
            } catch (\Throwable) {
                continue;
            }
            if ($date !== $today) {
                continue;
            }
            $value = $entry['total_printed_qty'] ?? $entry['totalPrintedQty'] ?? $entry['printed_qty'] ?? $entry['printedQty'] ?? null;
            if ($value === null) {
                continue;
            }
            $numeric = $this->numericValue($value);
            if ($max === null || $numeric > $max) {
                $max = $numeric;
            }
        }

        return $max ?? 0.0;
    }

    protected function resolveMachineLabelFromRoute(array $route): ?string
    {
        $machine = $route['machine'] ?? Arr::get($route, 'metadata.machine') ?? null;
        if (is_array($machine)) {
            $label = $machine['machine_name']
                ?? $machine['name']
                ?? $machine['machine_type']
                ?? $machine['type']
                ?? $machine['label']
                ?? $machine['machine_code']
                ?? $machine['code']
                ?? null;
            return $label ? trim((string) $label) : null;
        }

        if (is_string($machine)) {
            $trimmed = trim($machine);
            return $trimmed !== '' ? $trimmed : null;
        }

        return null;
    }

    protected function extractOperatorId(?array $entry): ?string
    {
        if (!$entry) {
            return null;
        }
        $operator = $entry['operator_id'] ?? $entry['operatorId'] ?? $entry['operator'] ?? $entry['user_id'] ?? null;
        if ($operator === null || $operator === '') {
            return null;
        }
        return (string) $operator;
    }

    protected function timeTrackerActionToState(?string $action): ?string
    {
        if (!$action) {
            return null;
        }
        $normalized = strtolower(trim($action));
        return match ($normalized) {
            'start' => 'running',
            'pause' => 'paused',
            'stop' => 'stopped',
            default => null,
        };
    }

    protected function normalizeWipStatusKey(?string $value): ?string
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return null;
        }

        $raw = str_replace(['-', '_'], ' ', $raw);
        $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;

        $map = [
            'backlog' => 'backlog',
            'released' => 'progress',
            'process' => 'progress',
            'progress' => 'progress',
            'in progress' => 'progress',
            'inprogress' => 'progress',
            'completed' => 'completed',
            'complete' => 'completed',
            'packing' => 'completed',
        ];

        return $map[$raw] ?? null;
    }

    protected function resolveIsReleased(WorkOrder $order, string $statusRaw): bool
    {
        if ($statusRaw !== '') {
            $normalized = str_replace(['-', '_'], ' ', $statusRaw);
            $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

            if (in_array($normalized, ['draft', 'backlog', 'new', 'planned', 'plan', 'hold', 'on hold', 'blocked', 'paused'], true)) {
                return false;
            }

            if (in_array($normalized, ['released', 'in progress', 'active', 'completed', 'complete', 'done'], true)) {
                return true;
            }
        }

        return (bool) $order->is_released;
    }

    protected function resolveWipStatusKey(
        bool $isReleased,
        bool $explicitCompleted,
        int $completedRoutes,
        int $totalRoutes,
        bool $hasPacking
    ): string {
        if (!$isReleased) {
            return 'backlog';
        }

        $isCompleted = $explicitCompleted || ($totalRoutes > 0 && $completedRoutes >= $totalRoutes);
        if ($isCompleted) {
            return 'completed';
        }

        return 'progress';
    }

    protected function wipStatusLabel(string $statusKey): string
    {
        return match ($statusKey) {
            'backlog' => 'Backlog',
            'progress' => 'In Progress',
            'completed' => 'Complete',
            default => ucwords(str_replace('_', ' ', $statusKey)),
        };
    }

    protected function normalizeWipSortBy(?string $value): string
    {
        $raw = strtolower(trim((string) $value));

        return match ($raw) {
            'work_order_no' => 'work_order_no',
            'customer_part_number' => 'customer_part_number',
            'customer_name' => 'customer_name',
            'routes_total' => 'routes_total',
            'routes' => 'routes_total',
            'route_link' => 'route_link',
            'progress' => 'progress',
            default => 'updated_at',
        };
    }

    protected function normalizeSortDirection(?string $value): string
    {
        $raw = strtolower(trim((string) $value));

        return $raw === 'asc' ? 'asc' : 'desc';
    }

    protected function sortWipItems($items, string $sortBy, string $sortDir)
    {
        $rows = $items->values()->all();
        $desc = $sortDir === 'desc';

        usort($rows, function (array $a, array $b) use ($sortBy, $desc): int {
            $aVal = $this->resolveWipSortValue($a, $sortBy);
            $bVal = $this->resolveWipSortValue($b, $sortBy);

            if (is_string($aVal) || is_string($bVal)) {
                $cmp = strnatcasecmp((string) $aVal, (string) $bVal);
            } else {
                $cmp = $aVal <=> $bVal;
            }

            if ($cmp === 0) {
                $cmp = strnatcasecmp(
                    (string) ($a['work_order_no'] ?? ''),
                    (string) ($b['work_order_no'] ?? '')
                );
            }

            return $desc ? -$cmp : $cmp;
        });

        return collect($rows)->values();
    }

    protected function resolveWipSortValue(array $row, string $sortBy): mixed
    {
        return match ($sortBy) {
            'work_order_no' => $row['work_order_no'] ?? '',
            'customer_part_number' => $row['customer_part_number'] ?? '',
            'customer_name' => $row['customer_name'] ?? '',
            'routes_total' => (int) ($row['routes_total'] ?? 0),
            'route_link' => !empty($row['has_route_link']) ? 1 : 0,
            'progress' => $this->resolveWipProgressValue($row),
            default => strtotime((string) ($row['updated_at'] ?? '')) ?: 0,
        };
    }

    protected function resolveWipProgressValue(array $row): float
    {
        $total = (int) ($row['routes_total'] ?? 0);
        if ($total <= 0) {
            return 0.0;
        }

        $completed = (int) ($row['routes_completed'] ?? 0);

        return $completed / $total;
    }

    protected function routesCompleted(array $routes): bool
    {
        if (empty($routes)) {
            return false;
        }

        foreach ($routes as $route) {
            $status = strtolower(trim((string) ($route['status'] ?? '')));
            if ($status === '') {
                return false;
            }
            if (!in_array($status, ['completed', 'complete', 'done'], true)) {
                return false;
            }
        }

        return true;
    }

    protected function resolveCompletionDate(WorkOrder $order, array $routes): ?\Illuminate\Support\Carbon
    {
        $date = $order->production_date_completed;
        if ($date) {
            return $date instanceof \Illuminate\Support\Carbon ? $date->copy() : \Illuminate\Support\Carbon::parse($date);
        }

        $latest = null;
        foreach ($routes as $route) {
            $raw = $route['completed_at'] ?? $route['completedAt'] ?? null;
            if (!$raw) {
                continue;
            }

            try {
                $candidate = \Illuminate\Support\Carbon::parse($raw);
            } catch (Throwable $e) {
                continue;
            }

            if ($latest === null || $candidate->gt($latest)) {
                $latest = $candidate;
            }
        }

        return $latest ? $latest->copy() : null;
    }

    protected function resolveDueDate(WorkOrder $order): ?\Illuminate\Support\Carbon
    {
        $date = $order->production_due_date ?? $order->requested_delivery_date ?? null;
        if (!$date) {
            return null;
        }

        return $date instanceof \Illuminate\Support\Carbon ? $date->copy()->startOfDay() : \Illuminate\Support\Carbon::parse($date)->startOfDay();
    }

    protected function resolveScheduleStartDate(WorkOrder $order): ?\Illuminate\Support\Carbon
    {
        $date = $order->production_start_date ?? null;
        if ($date) {
            return $date instanceof \Illuminate\Support\Carbon
                ? $date->copy()->startOfDay()
                : \Illuminate\Support\Carbon::parse($date)->startOfDay();
        }

        $due = $this->resolveDueDate($order);
        if ($due) {
            return $due->copy()->startOfDay();
        }

        return null;
    }

    protected function resolveOrderDate(WorkOrder $order): ?\Illuminate\Support\Carbon
    {
        $date = $order->order_date ?? $order->created_at ?? null;
        if (!$date) {
            return null;
        }

        return $date instanceof \Illuminate\Support\Carbon ? $date->copy()->startOfDay() : \Illuminate\Support\Carbon::parse($date)->startOfDay();
    }

    protected function resolveDisplayStatus(WorkOrder $order): string
    {
        $status = trim((string) ($order->status ?? ''));
        $normalized = strtolower($status);
        $isTerminal = $normalized !== '' &&
            (str_contains($normalized, 'complete') || str_contains($normalized, 'cancel'));
        if ($isTerminal && $status !== '') {
            return $status;
        }

        $isReleased = (bool) ($order->is_released ?? false);
        return $isReleased ? 'In Progress' : 'Backlog';
    }

    protected function normalizeStatus(mixed $status, bool $isCompleted): string
    {
        if ($isCompleted) {
            return 'Completed';
        }

        $raw = strtolower(trim((string) $status));
        if ($raw === '') {
            return 'In Progress';
        }

        $map = [
            'draft' => 'Backlog',
            'planned' => 'Backlog',
            'plan' => 'Backlog',
            'new' => 'Backlog',
            'backlog' => 'Backlog',
            'released' => 'In Progress',
            'release' => 'In Progress',
            'ready' => 'In Progress',
            'in_progress' => 'In Progress',
            'in-progress' => 'In Progress',
            'in progress' => 'In Progress',
            'active' => 'In Progress',
            'hold' => 'Backlog',
            'on hold' => 'Backlog',
            'blocked' => 'Backlog',
            'paused' => 'Backlog',
        ];

        if (isset($map[$raw])) {
            return $map[$raw];
        }

        if (str_contains($raw, 'release')) {
            return 'In Progress';
        }
        if (str_contains($raw, 'hold') || str_contains($raw, 'block')) {
            return 'Backlog';
        }

        return ucwords($raw);
    }

    protected function mapCalendarStatus(string $status): string
    {
        $raw = strtolower(trim($status));
        if ($raw === '') {
            return 'backlog';
        }
        if (str_contains($raw, 'complete')) {
            return 'completed';
        }
        if (str_contains($raw, 'progress')) {
            return 'in_progress';
        }
        if (str_contains($raw, 'release')) {
            return 'in_progress';
        }
        if (str_contains($raw, 'draft') || str_contains($raw, 'plan')) {
            return 'backlog';
        }
        if (str_contains($raw, 'hold') || str_contains($raw, 'block')) {
            return 'backlog';
        }
        return 'backlog';
    }

    protected function numericValue(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    protected function accumulateScrap(array $routes, array &$scrapReasons): float
    {
        $total = 0.0;

        foreach ($routes as $route) {
            $scrap = $this->numericValue($route['scrap'] ?? $route['scrap_qty'] ?? null);
            if ($scrap <= 0) {
                continue;
            }

            $total += $scrap;
            $reason = trim((string) ($route['scrapReason'] ?? $route['scrap_reason'] ?? 'Unspecified'));
            if ($reason === '') {
                $reason = 'Unspecified';
            }
            $scrapReasons[$reason] = ($scrapReasons[$reason] ?? 0) + $scrap;
        }

        return $total;
    }

    protected function resolveActiveRoute(array $routes, mixed $currentStep): array
    {
        if (empty($routes)) {
            return [];
        }

        $index = null;
        if (is_numeric($currentStep)) {
            $index = (int) $currentStep;
        }

        if ($index !== null && isset($routes[$index])) {
            return $routes[$index];
        }

        foreach ($routes as $route) {
            $status = strtolower(trim((string) ($route['status'] ?? '')));
            if (!in_array($status, ['completed', 'complete', 'done'], true)) {
                return $route;
            }
        }

        return $routes[array_key_last($routes)] ?? [];
    }

    protected function resolveWorkCenter(array $route): string
    {
        if (empty($route)) {
            return '';
        }

        $machine =
            $route['machine'] ??
            Arr::get($route, 'metadata.machine') ??
            Arr::get($route, 'validation.machine') ??
            ($route['machine_name'] ?? null);

        if (is_array($machine)) {
            $name = trim((string) ($machine['name'] ?? $machine['label'] ?? $machine['machine_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
            $code = trim((string) ($machine['code'] ?? $machine['machine_code'] ?? ''));
            if ($code !== '') {
                return 'Machine ' . $code;
            }
        }

        if (is_string($machine)) {
            $label = trim($machine);
            if ($label !== '') {
                return $label;
            }
        }

        $fallback = trim((string) ($route['name'] ?? $route['step'] ?? $route['route'] ?? ''));
        if ($fallback !== '') {
            return $fallback;
        }

        return '';
    }

    protected function buildDateBuckets(\Illuminate\Support\Carbon $start, int $days): array
    {
        $buckets = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            $key = $day->toDateString();
            $buckets[$key] = [
                'date' => $key,
                'count' => 0,
                'units' => 0.0,
            ];
        }

        return $buckets;
    }

    protected function buildStatusSeries(array $statusCounts): array
    {
        $order = ['Backlog', 'In Progress', 'Completed'];
        $series = [];

        foreach ($order as $label) {
            if (!isset($statusCounts[$label])) {
                continue;
            }
            $series[] = [
                'status' => $label,
                'count' => $statusCounts[$label],
            ];
            unset($statusCounts[$label]);
        }

        foreach ($statusCounts as $label => $count) {
            $series[] = [
                'status' => $label,
                'count' => $count,
            ];
        }

        return $series;
    }

    protected function buildSortedSeries(array $source, string $labelKey, int $limit): array
    {
        if (empty($source)) {
            return [];
        }

        arsort($source);
        $series = [];
        foreach ($source as $label => $value) {
            $series[] = [
                $labelKey => $label,
                'count' => $value,
            ];
            if (count($series) >= $limit) {
                break;
            }
        }

        return $series;
    }

    protected function rebuildAssignmentSummary(array &$metadata): void
    {
        $routes = $metadata['routes'] ?? [];
        $flattenedRoutes = [];
        $explicitAssignments = data_get($metadata, 'assignments.routes', []);

        foreach ($routes as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (isset($entry['routes']) && is_array($entry['routes'])) {
                foreach ($entry['routes'] as $route) {
                    if (is_array($route)) {
                        $flattenedRoutes[] = $route;
                    }
                }
            } else {
                $flattenedRoutes[] = $entry;
            }
        }

        $explicitAssignments = is_array($explicitAssignments) ? $explicitAssignments : [];
        $assignmentRoutes = [];
        $allAssignees = [];
        $matchedExplicitIndexes = [];

        foreach ($flattenedRoutes as $route) {
            $operatorMap = [];
            $routeKey = trim((string) (
                data_get($route, 'route_key')
                ?? data_get($route, 'routeKey')
                ?? ''
            ));
            $routeToken = $this->normalizeRouteToken(
                data_get($route, 'route')
                ?? data_get($route, 'key')
                ?? null
            );
            $nameToken = $this->normalizeRouteToken(data_get($route, 'name'));
            $orderSeq = data_get($route, 'order_seq') ?? data_get($route, 'orderSeq');

            $explicitAssignment = null;
            foreach ($explicitAssignments as $index => $assignment) {
                if (!is_array($assignment)) {
                    continue;
                }

                $assignmentRouteKey = trim((string) (
                    data_get($assignment, 'route_key')
                    ?? data_get($assignment, 'routeKey')
                    ?? ''
                ));
                if ($routeKey !== '' && $assignmentRouteKey !== '' && $routeKey === $assignmentRouteKey) {
                    $explicitAssignment = $assignment;
                    $matchedExplicitIndexes[$index] = true;
                    break;
                }

                $assignmentRouteToken = $this->normalizeRouteToken(
                    data_get($assignment, 'route')
                    ?? data_get($assignment, 'key')
                    ?? null
                );
                if ($routeToken && $assignmentRouteToken && $routeToken === $assignmentRouteToken) {
                    $explicitAssignment = $assignment;
                    $matchedExplicitIndexes[$index] = true;
                    break;
                }

                $assignmentNameToken = $this->normalizeRouteToken(data_get($assignment, 'name'));
                if ($nameToken && $assignmentNameToken && $nameToken === $assignmentNameToken) {
                    $explicitAssignment = $assignment;
                    $matchedExplicitIndexes[$index] = true;
                    break;
                }

                $assignmentOrderSeq = data_get($assignment, 'order_seq') ?? data_get($assignment, 'orderSeq');
                if ($orderSeq !== null && $assignmentOrderSeq !== null && (string) $orderSeq === (string) $assignmentOrderSeq) {
                    $explicitAssignment = $assignment;
                    $matchedExplicitIndexes[$index] = true;
                    break;
                }
            }

            $existingOperators = data_get($explicitAssignment, 'operators', $route['operators'] ?? []);
            $hasExplicitOperators = is_array($explicitAssignment)
                && array_key_exists('operators', $explicitAssignment);
            if (is_array($existingOperators)) {
                foreach ($existingOperators as $operator) {
                    $id = data_get($operator, 'id')
                        ?? data_get($operator, 'user_id')
                        ?? data_get($operator, 'userId');

                    if ($id !== null && $id !== '') {
                        $operatorMap[(string) $id] = [
                            'id' => (string) $id,
                            'qty' => data_get($operator, 'qty'),
                        ];
                    }
                }
            }

            if (!$hasExplicitOperators) {
                $directOperatorId = data_get($route, 'operator_id')
                    ?? data_get($route, 'operatorId')
                    ?? data_get($route, 'user_id')
                    ?? data_get($route, 'metadata.machineOperatorId')
                    ?? data_get($route, 'machineOperatorId');

                if ($directOperatorId !== null && $directOperatorId !== '') {
                    $operatorMap[(string) $directOperatorId] = [
                        'id' => (string) $directOperatorId,
                        'qty' => $operatorMap[(string) $directOperatorId]['qty'] ?? null,
                    ];
                }

                $timeEntries = data_get($route, 'metadata.timeTracker.entries', []);
                if (is_array($timeEntries)) {
                    foreach ($timeEntries as $entry) {
                        $timeOperatorId = data_get($entry, 'operator_id')
                            ?? data_get($entry, 'operatorId')
                            ?? data_get($entry, 'user_id');

                        if ($timeOperatorId !== null && $timeOperatorId !== '') {
                            $operatorMap[(string) $timeOperatorId] = [
                                'id' => (string) $timeOperatorId,
                                'qty' => $operatorMap[(string) $timeOperatorId]['qty'] ?? null,
                            ];
                        }
                    }
                }
            }

            $additionalMachines = data_get($route, 'metadata.additionalMachines')
                ?? data_get($route, 'additionalMachines')
                ?? [];

            if (is_array($additionalMachines)) {
                foreach ($additionalMachines as $machine) {
                    $machineOperatorId = data_get($machine, 'operatorId')
                        ?? data_get($machine, 'machineOperatorId')
                        ?? data_get($machine, 'operator_id')
                        ?? data_get($machine, 'user_id')
                        ?? data_get($machine, 'machineDetails.operatorId')
                        ?? data_get($machine, 'machineDetails.machineOperatorId')
                        ?? data_get($machine, 'machine.operatorId')
                        ?? data_get($machine, 'machine.machineOperatorId')
                        ?? data_get($machine, 'metadata.operatorId')
                        ?? data_get($machine, 'metadata.machineOperatorId');

                    if ($machineOperatorId !== null && $machineOperatorId !== '') {
                        $operatorMap[(string) $machineOperatorId] = [
                            'id' => (string) $machineOperatorId,
                            'qty' => $operatorMap[(string) $machineOperatorId]['qty'] ?? null,
                        ];
                    }
                }
            }

            $operators = array_values($operatorMap);

            foreach ($operators as $operator) {
                $allAssignees[(string) $operator['id']] = (string) $operator['id'];
            }

            $assignmentRoutes[] = [
                'route_key' => $routeKey !== '' ? $routeKey : data_get($explicitAssignment, 'route_key'),
                'order_seq' => $route['order_seq'] ?? data_get($explicitAssignment, 'order_seq'),
                'route' => $route['route'] ?? data_get($explicitAssignment, 'route'),
                'name' => $route['name'] ?? data_get($explicitAssignment, 'name'),
                'operators' => $operators,
            ];
        }

        foreach ($explicitAssignments as $index => $assignment) {
            if (isset($matchedExplicitIndexes[$index]) || !is_array($assignment)) {
                continue;
            }

            $operators = is_array(data_get($assignment, 'operators'))
                ? array_values(array_filter(
                    data_get($assignment, 'operators'),
                    static fn ($operator) => is_array($operator)
                ))
                : [];

            foreach ($operators as $operator) {
                $id = data_get($operator, 'id')
                    ?? data_get($operator, 'user_id')
                    ?? data_get($operator, 'userId');

                if ($id !== null && $id !== '') {
                    $allAssignees[(string) $id] = (string) $id;
                }
            }

            $assignmentRoutes[] = [
                'route_key' => data_get($assignment, 'route_key') ?? data_get($assignment, 'routeKey'),
                'order_seq' => data_get($assignment, 'order_seq') ?? data_get($assignment, 'orderSeq'),
                'route' => data_get($assignment, 'route') ?? data_get($assignment, 'key'),
                'name' => data_get($assignment, 'name'),
                'operators' => $operators,
            ];
        }

        $metadata['assignments']['routes'] = $assignmentRoutes;
        $metadata['state']['assignees'] = array_values($allAssignees);
    }
}
