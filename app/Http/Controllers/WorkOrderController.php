<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkOrder\WorkOrderBatchStoreRequest;
use App\Http\Requests\WorkOrder\WorkOrderDetailRequest;
use App\Http\Requests\WorkOrder\WorkOrderImportRequest;
use App\Http\Requests\WorkOrder\WorkOrderStoreRequest;
use App\Http\Requests\WorkOrder\WorkOrderUpdateRequest;
use App\Services\Contracts\WorkOrderServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $order = Arr::get($request->all(), 'order', ['id', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 10);
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->workOrderService->getList($filters, $order, $limit, $page);

            return $this->successPagination('Work orders retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load work orders.', 500);
        }
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

    public function store(WorkOrderStoreRequest $request): JsonResponse
    {
        try {
            $workOrder = $this->workOrderService->create($request->validated());

            return $this->success('Work order created successfully!', $workOrder);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to create work order.', 500);
        }
    }

    public function batchStore(WorkOrderBatchStoreRequest $request): JsonResponse
    {
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
            sleep(5);
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

    public function update(WorkOrderUpdateRequest $request, int $id): JsonResponse
    {
        try {
            $updated = $this->workOrderService->update($id, $request->validated());

            return $updated
                ? $this->success('Work order updated successfully!')
                : $this->error('Nothing to update.', 422);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to update work order.', 500);
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
}
