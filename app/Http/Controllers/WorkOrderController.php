<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkOrder\WorkOrderBatchReplaceRequest;
use App\Http\Requests\WorkOrder\WorkOrderBatchStoreRequest;
use App\Http\Requests\WorkOrder\WorkOrderBulkUpdateRequest;
use App\Http\Requests\WorkOrder\WorkOrderDetailRequest;
use App\Http\Requests\WorkOrder\WorkOrderImportRequest;
use App\Http\Requests\WorkOrder\WorkOrderAssignmentsRequest;
use App\Http\Requests\WorkOrder\WorkOrderStoreRequest;
use App\Http\Requests\WorkOrder\WorkOrderTimeTrackerRequest;
use App\Http\Requests\WorkOrder\WorkOrderUpdateRequest;
use App\Services\Contracts\WorkOrderServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class WorkOrderController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected WorkOrderServiceInterface $workOrderService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        $customerPartNumber = $request->get('customer_part_number');
        if ($customerPartNumber !== null && $customerPartNumber !== '') {
            $filters['customer_part_number'] = $customerPartNumber;
        }
        $operatorId = $request->get('operator_id');
        if ($operatorId !== null && $operatorId !== '') {
            $filters['operator_id'] = $operatorId;
        }
        $order = Arr::get($request->all(), 'order', ['id', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 10);
        $limit = max(1, min($limit, 100));
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->workOrderService->getList($filters, $order, $limit, $page);

            return $this->successPagination('Work orders retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load work orders.', 500);
        }
    }

    public function wip(Request $request): JsonResponse
    {
        $filters = $request->only([
            'work_order_no',
            'customer_part_number',
            'customer_name',
            'customer_code',
            'wip_status',
        ]);
        $limit = (int) $request->get('limit', 20);
        $page = (int) $request->get('page', 1);
        $sortBy = $request->get('sort_by');
        $sortDir = $request->get('sort_dir');

        // try {
            $data = $this->workOrderService->listWip($filters, $limit, $page, $sortBy, $sortDir);

            return $this->successPagination('WIP list retrieved successfully!', $data);
        // } catch (Throwable $e) {
        //     return $this->error('Failed to load WIP list.', 500);
        // }
    }

    public function options(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        $order = Arr::get($request->all(), 'order', ['id', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 10);
        $page = (int) Arr::get($request->all(), 'page', 1);

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $filters['search'] = $search;
        }

        $customerId = $request->get('customer_id');
        if (!is_null($customerId)) {
            $filters['customer_id'] = $customerId;
        }

        try {
            $data = $this->workOrderService->getOptions($filters, $order, $limit, $page);

            return $this->successPagination('Work order options retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load work order options.', 500);
        }
    }

    public function withActiveTemplateRoutes(): JsonResponse
    {
        try {
            $data = $this->workOrderService->listWithActiveTemplateRoutes();

            return $this->success('Work orders with active template routes retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load work orders with template routes.', 500);
        }
    }

    public function virtualization(Request $request): JsonResponse
    {
        $templateRouteId = $request->get('template_route_id');
        if (is_string($templateRouteId)) {
            $templateRouteId = trim($templateRouteId);
            $templateRouteId = $templateRouteId === '' ? null : (int) $templateRouteId;
        }
        $filters = Arr::get($request->all(), 'filters', []);
        $order = Arr::get($request->all(), 'order', ['id', 'desc']);

        try {
            $data = $this->workOrderService->virtualizationSnapshot($templateRouteId, $filters, $order);

            return $this->success('Work order virtualization snapshot retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load virtualization snapshot.', 500);
        }
    }

    public function summary(Request $request): JsonResponse
    {
        $options = $request->only(['on_time_days', 'throughput_days', 'due_soon_days']);

        try {
            $data = $this->workOrderService->summary($options);

            return $this->success('Work order summary retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load work order summary.', 500);
        }
    }

    public function calendarSummary(Request $request): JsonResponse
    {
        $options = [
            'from' => $request->get('from'),
            'to' => $request->get('to'),
            'upcoming_days' => $request->get('upcoming_days'),
            'filters' => Arr::get($request->all(), 'filters', []),
        ];

        try {
            $data = $this->workOrderService->calendarSummary($options);

            return $this->success('Work order calendar summary retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load calendar summary.', 500);
        }
    }

    public function calendarDay(Request $request): JsonResponse
    {
        $options = [
            'date' => $request->get('date') ?? $request->get('day'),
            'view' => $request->get('view'),
            'upcoming_days' => $request->get('upcoming_days'),
            'range_from' => $request->get('range_from'),
            'range_to' => $request->get('range_to'),
            'filters' => Arr::get($request->all(), 'filters', []),
            'limit' => $request->get('limit'),
            'page' => $request->get('page'),
        ];

        try {
            $data = $this->workOrderService->calendarDayOrders($options);

            return $this->success('Work order calendar day retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load calendar day orders.', 500);
        }
    }

    public function store(WorkOrderStoreRequest $request): JsonResponse
    {
        $evidenceImages = $request->file('evidence_images', []);
        if ($evidenceImages instanceof UploadedFile) {
            $evidenceImages = [$evidenceImages];
        }

        try {
            $workOrder = $this->workOrderService->create($request->validated(), $evidenceImages);

            return $this->success('Work order created successfully!', $workOrder);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to create work order.', 500);
        }
    }

    public function batchStore(WorkOrderBatchStoreRequest $request): JsonResponse
    {
        set_time_limit(120);
        // try {
        $payload = $request->validated();
        $result = $this->workOrderService->createBatch($payload['work_orders']);

        return $this->success('Work orders created successfully!', $result);
        // } catch (ValidationException $e) {
        //     return $this->validationError($e);
        // } catch (Throwable $e) {
        //     return $this->error('Failed to create work orders.', 500);
        // }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $workOrder = $this->workOrderService->detail($id);

            return $this->success('Work order retrieved successfully!', $workOrder);
        } catch (Throwable $e) {
            return $this->error('Failed to load work order.', 500);
        }
    }

    public function detailBy(WorkOrderDetailRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $workOrder = $this->workOrderService->detailBy($validated['column'], $validated['value']);

            return $this->success('Work order retrieved successfully!', $workOrder);
        } catch (Throwable $e) {
            return $this->error('Failed to load work order.', 500);
        }
    }

    public function import(WorkOrderImportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // try {
        $result = $this->workOrderService->importFromSpreadsheet(
            $request->file('file'),
            $validated['sheet']
        );

        return $this->success('Work order data extracted successfully!', $result);
        // } catch (ValidationException $e) {
        //     return $this->validationError($e);
        // } catch (RuntimeException $e) {
        //     return $this->error($e->getMessage(), 422);
        // } catch (Throwable $e) {
        //     return $this->error('Failed to import work orders.', 500);
        // }
    }

    public function linkTemplateRoutes(Request $request): JsonResponse
    {
        try {
            $reference = $request->get('reference')
                ?? $request->get('reference_column')
                ?? null;

            if (is_string($reference)) {
                $reference = trim($reference);
                if ($reference === '') {
                    $reference = null;
                }
            }

            $batchNumber = $request->get('batch_number');
            if (is_string($batchNumber)) {
                $batchNumber = trim($batchNumber);
                if ($batchNumber === '') {
                    $batchNumber = null;
                }
            }

            $templateBatchNumber = $request->get('template_batch_number');
            if (is_string($templateBatchNumber)) {
                $templateBatchNumber = trim($templateBatchNumber);
                if ($templateBatchNumber === '') {
                    $templateBatchNumber = null;
                }
            }

            $result = $this->workOrderService->linkTemplateRoutesByReference(
                $reference,
                $batchNumber,
                $templateBatchNumber
            );

            return $this->success('Template routes linked to work orders.', $result);
        } catch (Throwable $e) {
            return $this->error('Failed to link template routes.', 500);
        }
    }


    public function update(WorkOrderUpdateRequest $request, int $id): JsonResponse
    {
        $evidenceImages = $request->file('evidence_images', []);
        if ($evidenceImages instanceof UploadedFile) {
            $evidenceImages = [$evidenceImages];
        }

        try {
            $updated = $this->workOrderService->update($id, $request->validated(), $evidenceImages);

            if (!$updated) {
                return $this->error('Nothing to update.', 422);
            }

            $workOrder = (is_array($updated) || is_object($updated))
                ? $updated
                : $this->workOrderService->detail($id);

            return $this->success('Work order updated successfully!', $workOrder);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to update work order.', 500);
        }
    }

    public function bulkUpdateByCustomer(WorkOrderBulkUpdateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $customerCode = trim((string) $validated['customer_code']);
        $customerPartNumber = trim((string) $validated['customer_part_number']);

        try {
            $result = $this->workOrderService->bulkUpdateByCustomer(
                $customerCode,
                $customerPartNumber,
                $validated['changes']
            );

            return $this->success('Work orders updated successfully!', $result);
        } catch (Throwable $e) {
            return $this->error('Failed to update work orders.', 500);
        }
    }

    public function syncAssignments(WorkOrderAssignmentsRequest $request, int $id): JsonResponse
    {
        try {
            $payload = $request->validated();
            $routes = $payload['routes'] ?? [];
            $result = $this->workOrderService->syncAssignments($id, $routes);

            return $this->success('Work order assignments updated successfully!', $result);
        } catch (Throwable $e) {
            return $this->error('Failed to update work order assignments.', 500);
        }
    }

    public function recordTimeTracker(WorkOrderTimeTrackerRequest $request, int $id): JsonResponse
    {
        try {
            $payload = $request->validated();
            $actor = $request->user();
            $result = $this->workOrderService->recordTimeTracker($id, $payload, $actor);

            return $this->success('Time tracker updated successfully!', $result);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to update time tracker.', 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->workOrderService->delete($id);

            return $this->success('Work order deleted successfully!');
        } catch (Throwable $e) {
            return $this->error('Failed to delete work order.', 500);
        }
    }

    public function listByBatch(Request $request): JsonResponse
    {
        $batchNumber = trim((string) $request->get('batch_number', ''));
        $limit = (int) $request->get('limit', 10);
        $page = (int) $request->get('page', 1);

        if ($batchNumber === '') {
            return $this->error('Batch number is required.', 422);
        }

        try {
            $data = $this->workOrderService->listByBatch($batchNumber, $limit, $page);

            return $this->successPagination('Work orders retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load work orders.', 500);
        }
    }

    public function listByTemplateRouteBatch(Request $request): JsonResponse
    {
        $batchNumber = trim((string) $request->get('batch_number', ''));
        $limit = (int) $request->get('limit', 10);
        $page = (int) $request->get('page', 1);

        if ($batchNumber === '') {
            return $this->error('Batch number is required.', 422);
        }

        try {
            $data = $this->workOrderService->listByTemplateRouteBatch($batchNumber, $limit, $page);

            return $this->successPagination('Work orders retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load work orders.', 500);
        }
    }

    public function countByTemplateRouteBatch(Request $request): JsonResponse
    {
        $batchNumber = trim((string) $request->get('batch_number', ''));

        if ($batchNumber === '') {
            return $this->error('Batch number is required.', 422);
        }

        try {
            $count = $this->workOrderService->countByTemplateRouteBatch($batchNumber);

            return $this->success('Work order count retrieved successfully!', [
                'batch_number' => $batchNumber,
                'count' => $count,
            ]);
        } catch (Throwable $e) {
            return $this->error('Failed to count work orders.', 500);
        }
    }

    public function replaceBatch(WorkOrderBatchReplaceRequest $request): JsonResponse
    {
        $payload = $request->validated();

        try {
            set_time_limit(120);
            $result = $this->workOrderService->replaceBatch($payload['batch_number'], $payload['work_orders']);

            return $this->success('Batch work orders replaced successfully!', $result);
        } catch (Throwable $e) {
            return $this->error('Failed to replace batch work orders.', 500);
        }
    }
}
