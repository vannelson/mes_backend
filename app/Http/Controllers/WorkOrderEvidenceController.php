<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkOrderEvidence\WorkOrderEvidenceStoreRequest;
use App\Services\Contracts\WorkOrderEvidenceServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

class WorkOrderEvidenceController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected WorkOrderEvidenceServiceInterface $workOrderEvidenceService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        foreach (['work_order_no', 'route_name'] as $key) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }
        $order = Arr::get($request->all(), 'order', ['id', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 20);
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->workOrderEvidenceService->getList($filters, $order, $limit, $page);

            return $this->successPagination('Work order evidence retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load work order evidence.', 500);
        }
    }

    public function store(WorkOrderEvidenceStoreRequest $request): JsonResponse
    {
        $images = $request->file('images', []);
        if ($images instanceof UploadedFile) {
            $images = [$images];
        }
        $single = $request->file('image');
        if ($single instanceof UploadedFile) {
            $images[] = $single;
        }

        if (empty($images)) {
            return $this->error('No evidence images provided.', 422);
        }

        // try {
            $data = $request->validated();
            unset($data['images'], $data['image']);

            $created = $this->workOrderEvidenceService->create($data, $images);

            return $this->success('Work order evidence saved successfully!', $created, 201);
        // } catch (ValidationException $e) {
            return $this->validationError($e);
        // } catch (Throwable $e) {
        //     return $this->error('Failed to save work order evidence.', 500);
        // }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            if (!ctype_digit($id)) {
                return $this->error('Invalid evidence id.', 422);
            }

            $deleted = $this->workOrderEvidenceService->delete((int) $id);

            return $deleted
                ? $this->success('Work order evidence deleted successfully!')
                : $this->error('Failed to delete work order evidence.', 500);
        } catch (Throwable $e) {
            return $this->error('Failed to delete work order evidence.', 500);
        }
    }
}
