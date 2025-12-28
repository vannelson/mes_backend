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
use Illuminate\Support\Facades\DB;
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

        foreach ($workOrders as $workOrder) {
            try {
                $this->syncCustomerSnapshot($workOrder);
                $this->syncTemplateMetadata($workOrder);
                $this->ensureBatchNumber($workOrder);

                $created[] = $this->workOrderRepository
                    ->create($workOrder)
                    ->load(['customer', 'templateRoute']);
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

    public function linkTemplateRoutesByReference(): array
    {
        $templates = TemplateRoute::query()
            ->select(['id', 'wod_ref', 'metadata'])
            ->whereNotNull('wod_ref')
            ->whereRaw("TRIM(wod_ref) <> ''")
            ->get();

        $referenceMap = $templates->flatMap(function (TemplateRoute $template): array {
            $refs = collect(preg_split('/[\s,]+/', (string) $template->wod_ref))
                ->filter()
                ->map(fn (string $ref) => strtoupper(trim($ref)))
                ->filter();

            $payload = $template->metadata;
            if (is_string($payload)) {
                $decoded = json_decode($payload, true);
                $payload = $decoded ?? $payload;
            }

            return $refs->mapWithKeys(fn (string $ref) => [
                $ref => [
                    'template_route_id' => $template->id,
                    'metadata' => $payload,
                ],
            ])->all();
        });

        if ($referenceMap->isEmpty()) {
            return [
                'linked' => 0,
                'skipped' => 0,
                'eligible' => 0,
                'template_routes' => $templates->count(),
            ];
        }

        $eligibleOrders = WorkOrder::query()
            ->select(['id', 'work_order_no', 'metadata', 'template_route_id'])
            ->where(function ($query) {
                $query
                    ->whereNull('metadata')
                    ->orWhere('metadata', '')
                    ->orWhere('metadata', '[]')
                    ->orWhere('metadata', '{}');
            })
            ->get();

        $linked = 0;
        $skipped = 0;

        DB::transaction(function () use ($eligibleOrders, $referenceMap, &$linked, &$skipped) {
            foreach ($eligibleOrders as $order) {
                $reference = strtoupper(trim((string) $order->work_order_no));
                $matched = $reference !== '' ? $referenceMap->get($reference) : null;

                if (! $matched) {
                    $skipped++;

                    continue;
                }

                $order->template_route_id = $matched['template_route_id'];
                $order->metadata = $matched['metadata'];
                $order->save();
                $linked++;
            }
        });

        return [
            'linked' => $linked,
            'skipped' => $skipped,
            'eligible' => $eligibleOrders->count(),
            'template_routes' => $templates->count(),
        ];
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
}
