<?php

namespace App\Http\Controllers;

use App\Http\Requests\BatchLog\BatchLogStoreRequest;
use App\Http\Requests\BatchLog\BatchLogUpdateRequest;
use App\Services\Contracts\BatchLogServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

class BatchLogController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected BatchLogServiceInterface $batchLogService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        $order = Arr::get($request->all(), 'order', ['id', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 10);
        $page = (int) Arr::get($request->all(), 'page', 1);

        // allow shorthand query params (?batch_no=XYZ)
        foreach (['batch_no', 'operator', 'type'] as $key) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        try {
            $data = $this->batchLogService->getList($filters, $order, $limit, $page);

            return $this->successPagination('Batch logs retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load batch logs.', 500);
        }
    }

    public function store(BatchLogStoreRequest $request): JsonResponse
    {
        // try {
            $data = $request->validated();
            $data['user_id'] = $data['user_id'] ?? $request->user()->id;

            $payload = $this->batchLogService->create($data);

            return $this->success('Batch log created successfully!', $payload);
        // } catch (ValidationException $e) {
        //     return $this->validationError($e);
        // } catch (Throwable $e) {
        //     return $this->error('Failed to create batch log.', 500);
        // }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $batchLog = $this->batchLogService->detail($id);

            return $this->success('Batch log retrieved successfully!', $batchLog);
        } catch (Throwable $e) {
            return $this->error('Failed to load batch log.', 500);
        }
    }

    public function update(BatchLogUpdateRequest $request, int $id): JsonResponse
    {
        try {
            $updated = $this->batchLogService->update($id, $request->validated());

            return !empty($updated)
                ? $this->success('Batch log updated successfully!', $updated)
                : $this->error('Nothing to update.', 422);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to update batch log.', 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleteWorkOrdersInput = $request->get('delete_work_orders', false);
        $deleteWorkOrders = filter_var($deleteWorkOrdersInput, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($deleteWorkOrders === null) {
            $deleteWorkOrders = in_array($deleteWorkOrdersInput, ['1', 1, 'true', true], true);
        }

        try {
            $result = $this->batchLogService->delete($id, (bool) $deleteWorkOrders);

            if (! $result['deleted']) {
                return $this->error($result['message'] ?? 'Cannot delete batch log while work orders still reference it.', 422);
            }

            return $this->success('Batch log deleted successfully!', $result);
        } catch (Throwable $e) {
            return $this->error('Failed to delete batch log.', 500);
        }
    }
}
