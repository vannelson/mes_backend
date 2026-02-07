<?php

namespace App\Services;

use App\Http\Resources\WorkOrder\WorkOrderResource;
use App\Models\Customer;
use App\Models\PackingChecklist;
use App\Models\TemplateRoute;
use App\Models\User;
use App\Models\UserWorkOrder;
use App\Models\WorkOrder;
use App\Repositories\Contracts\WorkOrderRepositoryInterface;
use App\Services\Contracts\WorkOrderServiceInterface;
use App\Services\WorkOrderImportService;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class WorkOrderService implements WorkOrderServiceInterface
{
    public function __construct(
        protected WorkOrderRepositoryInterface $workOrderRepository,
        protected WorkOrderImportService $workOrderImportService
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
            $routeStats = $this->resolveRouteStats($metadata, $routes);
            $statusRaw = strtolower(trim((string) Arr::get($metadata, 'state.status', '')));
            $explicitCompleted = in_array($statusRaw, ['completed', 'complete', 'done'], true);
            $isReleased = $this->resolveIsReleased($order, $statusRaw);
            $hasPacking = $order->work_order_no
                ? $packingSet->has($order->work_order_no)
                : false;
            $statusKey = $this->resolveWipStatusKey(
                $isReleased,
                $explicitCompleted,
                $routeStats['completed'],
                $routeStats['total'],
                $hasPacking
            );

            return [
                'id' => $order->id,
                'work_order_no' => $order->work_order_no,
                'customer_part_number' => $order->customer_part_number,
                'customer_code' => $order->customer_code,
                'customer_name' => $order->customer_name,
                'is_released' => $isReleased,
                'has_route_link' => !empty($order->template_route_id),
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
        $workOrder = $this->workOrderRepository->findById($id)->load(['customer', 'templateRoute']);

        return (new WorkOrderResource($workOrder))->response()->getData(true);
    }

    public function detailBy(string $column, mixed $value): array
    {
        $workOrder = $this->workOrderRepository->findByColumn($column, $value)->load(['customer', 'templateRoute']);

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
            $workOrder = $this->workOrderRepository->create($data)->load(['customer', 'templateRoute']);
        } catch (Throwable $e) {
            $this->deleteEvidenceImages($storedImages);
            throw $e;
        }

        if (array_key_exists('metadata', $data)) {
            $this->syncAssignmentsFromMetadata($workOrder->id, $data['metadata']);
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

    public function update(int $id, array $data, array $evidenceImages = []): bool
    {
        $this->syncCustomerSnapshot($data);
        $this->syncTemplateMetadata($data);
        $this->syncReleaseFlag($data);

        $storedImages = [];
        if (!empty($evidenceImages)) {
            $workOrder = $this->workOrderRepository->findById($id);
            $existingImages = is_array($workOrder->evidence_images) ? $workOrder->evidence_images : [];
            $storedImages = $this->storeEvidenceImages($evidenceImages);
            $data['evidence_images'] = array_values(array_merge($existingImages, $storedImages));
        }

        $updated = (bool) $this->workOrderRepository->update($id, $data);

        if (!$updated && !empty($storedImages)) {
            $this->deleteEvidenceImages($storedImages);
        }

        if ($updated && array_key_exists('metadata', $data)) {
            $this->syncAssignmentsFromMetadata($id, $data['metadata']);
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
        $timeTracker = is_array($routeMetadata['timeTracker'] ?? null) ? $routeMetadata['timeTracker'] : [];
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

        $entries[] = $entry;
        $timeTracker['entries'] = $entries;
        $timeTracker['updated_at'] = $timestamp;
        $routeMetadata['timeTracker'] = $timeTracker;
        $route['metadata'] = $routeMetadata;

        $this->workOrderRepository->update($id, ['metadata' => $metadata]);

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
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entryOperator = $entry['operator_id'] ?? $entry['operatorId'] ?? $entry['operator'] ?? null;
            if ((string) $entryOperator !== (string) $operatorId) {
                continue;
            }
            $last = strtolower((string) ($entry['action'] ?? ''));
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

    public function linkTemplateRoutesByReference(
        ?string $reference = null,
        ?string $batchNumber = null,
        ?string $templateBatchNumber = null
    ): array
    {
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
        $orderSelect = ['id', 'work_order_no', 'metadata', 'template_route_id'];
        if ($hasWorkOrderPartNumber)
            $orderSelect[] = 'customer_part_number';
        if ($hasWorkOrderBatchNumber)
            $orderSelect[] = 'batch_number';

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

        $eligibleOrders = $eligibleOrdersQuery->get();

        if ($eligibleOrders->isEmpty()) {
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
        $hasIsReleased = Schema::hasColumn('work_orders', 'is_released');

        DB::transaction(function () use ($eligibleOrders, $templatesById, $useCustomerPartNumber, $allowWorkOrderFallback, $normalizeRefs, $partNumberIndex, $workOrderIndex, $hasIsReleased, &$linked, &$skipped) {
            foreach ($eligibleOrders as $order) {
                $templateId = $order->template_route_id;
                $matchedByPartNumber = false;

                // 1) Match by customer_part_number (supports multiple in one field)
                if (empty($templateId) && $useCustomerPartNumber) {
                    $refs = $normalizeRefs($order->customer_part_number ?? '');
                    foreach ($refs as $ref) {
                        if (isset($partNumberIndex[$ref])) {
                            $templateId = $partNumberIndex[$ref];
                            $matchedByPartNumber = true;
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

                $order->template_route_id = $templateData['id'];
                $order->metadata = $templateData['metadata'];
                if ($matchedByPartNumber && $hasIsReleased) {
                    $order->is_released = true;
                }
                $order->save();

                $linked++;
            }
        });

        $result = [
            'linked' => $linked,
            'skipped' => $skipped,
            'eligible' => $eligibleOrders->count(),
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

        $asOf = now()->startOfDay();
        $onTimeStart = (clone $asOf)->subDays($onTimeDays - 1);
        $throughputStart = (clone $asOf)->subDays($throughputDays - 1);
        $dueSoonEnd = (clone $asOf)->addDays($dueSoonDays);

        $orders = WorkOrder::query()
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
            ])
            ->get();

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

        $onTimeEligible = 0;
        $onTimeHit = 0;
        $leadTimeSum = 0.0;
        $leadTimeCount = 0;

        foreach ($orders as $order) {
            $totals['total_orders']++;

            $metadata = $this->normalizeMetadata($order->metadata);
            $state = is_array($metadata['state'] ?? null) ? $metadata['state'] : [];
            $statusRaw = $state['status'] ?? $metadata['status'] ?? null;
            $currentStep = $state['currentStep'] ?? null;

            $routes = $this->extractRoutes($metadata);
            $routesCompleted = $this->routesCompleted($routes);

            $completionDate = $this->resolveCompletionDate($order, $routes);
            $isCompleted = $completionDate !== null || $routesCompleted;

            $status = $this->normalizeStatus($statusRaw, $isCompleted);

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

            if ($status === 'Released') {
                $totals['released_orders']++;
            }

            if (!$isCompleted && $status !== 'Draft') {
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

    protected function syncTemplateMetadata(array &$data): void
    {
        if (!empty($data['template_route_id'])) {
            $templateRoute = TemplateRoute::findOrFail($data['template_route_id']);
            // reuse template metadata so work orders stay in sync with the chosen template
            $data['metadata'] = $templateRoute->metadata;
        }
    }

    protected function syncReleaseFlag(array &$data): void
    {
        if ($this->shouldReleaseByCompletion($data)) {
            $data['is_released'] = true;
            if (Schema::hasColumn('work_orders', 'completed_at')) {
                $data['completed_at'] = $data['production_date_completed'] ?? null;
            }
            if (Schema::hasColumn('work_orders', 'status')) {
                $data['status'] = 'completed';
            }
            return;
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

        if (in_array($normalized, ['draft', 'backlog', 'new', 'planned'], true)) {
            $data['is_released'] = false;
            return;
        }

        if (in_array($normalized, ['released', 'in progress', 'active', 'completed', 'complete', 'done'], true)) {
            $data['is_released'] = true;
        }
    }

    protected function shouldReleaseByCompletion(array $data): bool
    {
        $dateRaw = $data['production_date_completed'] ?? null;
        $qtyRaw = $data['production_qty_completed'] ?? null;

        if (!is_string($dateRaw)) {
            return false;
        }

        $date = trim($dateRaw);
        if ($date === '') {
            return false;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            return false;
        }

        if (!is_numeric($qtyRaw)) {
            return false;
        }

        return (float) $qtyRaw > 0;
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

        foreach ($routes as $idx => $route) {
            if (!is_array($route)) {
                continue;
            }

            $orderSeq = Arr::get($route, 'order_seq')
                ?? Arr::get($route, 'orderSeq')
                ?? ($idx + 1);
            $routeCode = Arr::get($route, 'route') ?? Arr::get($route, 'key');
            $routeName = Arr::get($route, 'name');
            $routeKey = $this->buildAssignmentRouteKey($routeCode, $orderSeq, $idx);
            $operators = Arr::get($route, 'operators', []);

            if (!is_array($operators)) {
                continue;
            }

            foreach ($operators as $operator) {
                if (!is_array($operator)) {
                    continue;
                }

                $userId = Arr::get($operator, 'id')
                    ?? Arr::get($operator, 'user_id')
                    ?? Arr::get($operator, 'userId');
                if (!$userId) {
                    continue;
                }

                $unique = "{$workOrderId}|{$userId}|{$routeKey}";
                if (isset($seen[$unique])) {
                    continue;
                }
                $seen[$unique] = true;

                $qty = Arr::get($operator, 'qty');
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
            'packing' => 'packing',
        ];

        return $map[$raw] ?? null;
    }

    protected function resolveIsReleased(WorkOrder $order, string $statusRaw): bool
    {
        if ($statusRaw !== '') {
            $normalized = str_replace(['-', '_'], ' ', $statusRaw);
            $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

            if (in_array($normalized, ['draft', 'backlog', 'new', 'planned'], true)) {
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
        if ($isCompleted && $hasPacking) {
            return 'packing';
        }

        if ($isCompleted) {
            return 'completed';
        }

        return 'progress';
    }

    protected function wipStatusLabel(string $statusKey): string
    {
        return match ($statusKey) {
            'backlog' => 'Backlog',
            'progress' => 'Progress',
            'completed' => 'Complete',
            'packing' => 'Packing',
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

    protected function resolveOrderDate(WorkOrder $order): ?\Illuminate\Support\Carbon
    {
        $date = $order->order_date ?? $order->created_at ?? null;
        if (!$date) {
            return null;
        }

        return $date instanceof \Illuminate\Support\Carbon ? $date->copy()->startOfDay() : \Illuminate\Support\Carbon::parse($date)->startOfDay();
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
            'draft' => 'Draft',
            'planned' => 'Draft',
            'new' => 'Draft',
            'released' => 'Released',
            'release' => 'Released',
            'ready' => 'Released',
            'in_progress' => 'In Progress',
            'in-progress' => 'In Progress',
            'in progress' => 'In Progress',
            'active' => 'In Progress',
            'hold' => 'On Hold',
            'on hold' => 'On Hold',
            'blocked' => 'On Hold',
            'paused' => 'On Hold',
        ];

        if (isset($map[$raw])) {
            return $map[$raw];
        }

        if (str_contains($raw, 'release')) {
            return 'Released';
        }
        if (str_contains($raw, 'hold') || str_contains($raw, 'block')) {
            return 'On Hold';
        }

        return ucwords($raw);
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
        $order = ['Completed', 'Released', 'In Progress', 'Draft', 'On Hold'];
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
}
