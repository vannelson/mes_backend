<?php

namespace App\Services;

use App\Http\Resources\WorkOrder\WorkOrderResource;
use App\Models\Customer;
use App\Models\TemplateRoute;
use App\Models\WorkOrder;
use App\Repositories\Contracts\WorkOrderRepositoryInterface;
use App\Services\Contracts\WorkOrderServiceInterface;
use App\Services\WorkOrderImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function create(array $data): array
    {
        $this->syncCustomerSnapshot($data);
        $this->syncTemplateMetadata($data);
        $this->ensureBatchNumber($data);
        $workOrder = $this->workOrderRepository->create($data)->load(['customer', 'templateRoute']);

        return (new WorkOrderResource($workOrder))->response()->getData(true);
    }

    public function createBatch(array $workOrders): array
    {
        $created = [];
        $failed = [];
        $compositeKeys = [];

        foreach ($workOrders as $workOrder) {
            $key = $this->buildCompositeKey($workOrder);
            if ($key !== '||') {
                $compositeKeys[] = $key;
            }
        }

        $existingKeyMap = $this->loadExistingCompositeKeys($compositeKeys);
        $seenInPayload = [];

        foreach ($workOrders as $workOrder) {
            $compositeKey = $this->buildCompositeKey($workOrder);

            if ($compositeKey === '||') {
                $failed[] = [
                    'work_order_no' => $workOrder['work_order_no'] ?? null,
                    'message' => 'Missing identifiers to evaluate duplicates (Work Order No. + Customer Code + Customer Part No.).',
                ];

                continue;
            }

            if (isset($seenInPayload[$compositeKey])) {
                $failed[] = [
                    'work_order_no' => $workOrder['work_order_no'] ?? null,
                    'message' => 'Duplicate in request skipped (Work Order No. + Customer Code + Customer Part No.).',
                ];

                continue;
            }

            if (isset($existingKeyMap[$compositeKey])) {
                $failed[] = [
                    'work_order_no' => $workOrder['work_order_no'] ?? null,
                    'message' => 'Duplicate of an existing work order skipped (Work Order No. + Customer Code + Customer Part No.).',
                ];

                continue;
            }

            try {
                $this->syncCustomerSnapshot($workOrder);
                $this->syncTemplateMetadata($workOrder);
                $this->ensureBatchNumber($workOrder);

                $created[] = $this->workOrderRepository
                    ->create($workOrder)
                    ->load(['customer', 'templateRoute']);
                $seenInPayload[$compositeKey] = true;
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
            'failed' => count($failed),
            'errors' => $failed,
        ];
    }

    public function update(int $id, array $data): bool
    {
        $this->syncCustomerSnapshot($data);
        $this->syncTemplateMetadata($data);

        return (bool) $this->workOrderRepository->update($id, $data);
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
            if (! $template) {
                return false;
            }

            if (! $this->metadataPresent($order->metadata)) {
                return false;
            }

            return $this->templateRouteAppearsActive($template);
        })->values();

        return [
            'data' => WorkOrderResource::collection($filtered)->resolve(),
            'count' => $filtered->count(),
        ];
    }

    public function linkTemplateRoutesByReference(?string $reference = null, ?string $batchNumber = null): array
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

        $hasTemplatePartNumberRef = Schema::hasColumn('template_routes', 'customer_part_number_ref');
        $hasTemplateWorkOrderRef = Schema::hasColumn('template_routes', 'wod_ref');
        $hasWorkOrderPartNumber = Schema::hasColumn('work_orders', 'customer_part_number');
        $hasWorkOrderBatchNumber = Schema::hasColumn('work_orders', 'batch_number');

        if ($referenceMode === 'customer_part_number' && (! $hasTemplatePartNumberRef || ! $hasWorkOrderPartNumber)) {
            $result = [
                'linked' => 0,
                'skipped' => 0,
                'eligible' => 0,
                'template_routes' => 0,
                'reference' => $referenceMode,
                'warning' => 'Customer part number reference is unavailable. Run the migration to add customer_part_number_ref.',
            ];
            if ($batchNumber !== null) {
                $result['batch_number'] = $batchNumber;
            }

            return $result;
        }

        $useCustomerPartNumber = $referenceMode !== 'work_order_no'
            && $hasTemplatePartNumberRef
            && $hasWorkOrderPartNumber;
        $allowWorkOrderFallback = $referenceMode !== 'customer_part_number' && $hasTemplateWorkOrderRef;

        $templateSelect = ['id', 'metadata'];
        if ($hasTemplatePartNumberRef) {
            $templateSelect[] = 'customer_part_number_ref';
        }
        if ($hasTemplateWorkOrderRef) {
            $templateSelect[] = 'wod_ref';
        }

        $templates = TemplateRoute::query()
            ->select($templateSelect)
            ->get();

        if ($templates->isEmpty()) {
            $result = [
                'linked' => 0,
                'skipped' => 0,
                'eligible' => 0,
                'template_routes' => 0,
                'reference' => $referenceMode,
            ];
            if ($batchNumber !== null) {
                $result['batch_number'] = $batchNumber;
            }

            return $result;
        }

        $templatesById = $templates->mapWithKeys(function (TemplateRoute $template): array {
            return [
                $template->id => [
                    'id' => $template->id,
                    'metadata' => $this->normalizeTemplateMetadata($template->metadata),
                ],
            ];
        });

        $templatesWithPartNumberRefs = $useCustomerPartNumber
            ? $templates->filter(
                fn (TemplateRoute $template) => trim((string) $template->customer_part_number_ref) !== ''
            )
            : collect();

        $templatesWithWorkOrderRefs = $allowWorkOrderFallback
            ? $templates->filter(
                function (TemplateRoute $template) use ($useCustomerPartNumber): bool {
                    $hasWorkOrderRef = trim((string) $template->wod_ref) !== '';
                    if (! $hasWorkOrderRef) {
                        return false;
                    }

                    if (! $useCustomerPartNumber) {
                        return true;
                    }

                    return trim((string) $template->customer_part_number_ref) === '';
                }
            )
            : collect();

        $partNumberReferenceMap = $useCustomerPartNumber
            ? $templatesWithPartNumberRefs->flatMap(
                function (TemplateRoute $template): array {
                    $refs = collect(preg_split('/[\s,]+/', (string) $template->customer_part_number_ref))
                        ->filter()
                        ->map(fn (string $ref) => strtoupper(trim($ref)))
                        ->filter();

                    return $refs->mapWithKeys(fn (string $ref) => [
                        $ref => $template->id,
                    ])->all();
                }
            )
            : collect();

        $workOrderReferenceMap = $allowWorkOrderFallback
            ? $templatesWithWorkOrderRefs->flatMap(
                function (TemplateRoute $template): array {
                    $refs = collect(preg_split('/[\s,]+/', (string) $template->wod_ref))
                        ->filter()
                        ->map(fn (string $ref) => strtoupper(trim($ref)))
                        ->filter();

                    return $refs->mapWithKeys(fn (string $ref) => [
                        $ref => $template->id,
                    ])->all();
                }
            )
            : collect();

        $orderSelect = [
            'id',
            'work_order_no',
            'metadata',
            'template_route_id',
        ];
        if ($hasWorkOrderPartNumber) {
            $orderSelect[] = 'customer_part_number';
        }

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

        if ($batchNumber !== null && $hasWorkOrderBatchNumber) {
            $eligibleOrdersQuery->where('batch_number', $batchNumber);
        }

        $eligibleOrders = $eligibleOrdersQuery->get();

        if ($eligibleOrders->isEmpty()) {
            $result = [
                'linked' => 0,
                'skipped' => 0,
                'eligible' => 0,
                'template_routes' => $templatesWithPartNumberRefs->count()
                    + $templatesWithWorkOrderRefs->count(),
                'reference' => $referenceMode,
            ];
            if ($batchNumber !== null) {
                $result['batch_number'] = $batchNumber;
            }

            return $result;
        }

        $linked = 0;
        $skipped = 0;

        DB::transaction(function () use (
            $eligibleOrders,
            $partNumberReferenceMap,
            $workOrderReferenceMap,
            $templatesById,
            $useCustomerPartNumber,
            $allowWorkOrderFallback,
            &$linked,
            &$skipped
        ) {
            foreach ($eligibleOrders as $order) {
                $templateId = $order->template_route_id;

                if (empty($templateId) && $useCustomerPartNumber) {
                    $reference = strtoupper(trim((string) $order->customer_part_number));
                    $templateId = $reference !== '' ? $partNumberReferenceMap->get($reference) : null;
                }

                if (! $templateId && $allowWorkOrderFallback) {
                    $legacyReference = strtoupper(trim((string) $order->work_order_no));
                    $templateId = $legacyReference !== ''
                        ? $workOrderReferenceMap->get($legacyReference)
                        : null;
                }

                if (! $templateId) {
                    $skipped++;

                    continue;
                }

                $templateData = $templatesById->get($templateId);
                if (! $templateData) {
                    $skipped++;

                    continue;
                }

                $order->template_route_id = $templateData['id'];
                $order->metadata = $templateData['metadata'];
                $order->save();
                $linked++;
            }
        });

        $result = [
            'linked' => $linked,
            'skipped' => $skipped,
            'eligible' => $eligibleOrders->count(),
            'template_routes' => $templatesWithPartNumberRefs->count()
                + $templatesWithWorkOrderRefs->count(),
            'reference' => $referenceMode,
        ];
        if ($batchNumber !== null) {
            $result['batch_number'] = $batchNumber;
        }

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

        return WorkOrder::query()
            ->selectRaw("{$expression} AS composite_key")
            ->whereIn(DB::raw($expression), array_values(array_unique($compositeKeys)))
            ->pluck('composite_key')
            ->mapWithKeys(fn ($key) => [$key => true])
            ->all();
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
            return ! empty($metadata);
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

        return ! is_null($metadata);
    }
}
