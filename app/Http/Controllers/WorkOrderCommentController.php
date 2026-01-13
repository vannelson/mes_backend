<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkOrderComment\WorkOrderCommentStoreRequest;
use App\Http\Requests\WorkOrderComment\WorkOrderCommentUpdateRequest;
use App\Services\Contracts\WorkOrderCommentServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

class WorkOrderCommentController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected WorkOrderCommentServiceInterface $workOrderCommentService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        foreach (['work_order_id', 'thread_id', 'parent_id', 'type', 'visibility', 'status', 'user_id'] as $key) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }
        $order = Arr::get($request->all(), 'order', ['id', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 10);
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->workOrderCommentService->getList($filters, $order, $limit, $page);

            return $this->successPagination('Work order comments retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load work order comments.', 500);
        }
    }

    public function store(WorkOrderCommentStoreRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            if (empty($data['user_id']) && $request->user()) {
                $data['user_id'] = $request->user()->id;
            }

            $comment = $this->workOrderCommentService->create($data);

            return $this->success('Work order comment created successfully!', $comment, 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to create work order comment.', 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            if (!ctype_digit($id)) {
                return $this->error('Invalid work order comment id.', 422);
            }

            $comment = $this->workOrderCommentService->detail((int) $id);

            return $this->success('Work order comment retrieved successfully!', $comment);
        } catch (Throwable $e) {
            return $this->error('Failed to load work order comment.', 500);
        }
    }

    public function update(WorkOrderCommentUpdateRequest $request, string $id): JsonResponse
    {
        try {
            if (!ctype_digit($id)) {
                return $this->error('Invalid work order comment id.', 422);
            }

            $data = $request->validated();
            if (array_key_exists('user_id', $data) && $data['user_id'] === null && $request->user()) {
                $data['user_id'] = $request->user()->id;
            }

            $updated = $this->workOrderCommentService->update((int) $id, $data);

            return !empty($updated)
                ? $this->success('Work order comment updated successfully!', $updated)
                : $this->error('Nothing to update.', 422);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to update work order comment.', 500);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            if (!ctype_digit($id)) {
                return $this->error('Invalid work order comment id.', 422);
            }

            $deleted = $this->workOrderCommentService->delete((int) $id);

            return $deleted
                ? $this->success('Work order comment deleted successfully!')
                : $this->error('Failed to delete work order comment.', 500);
        } catch (Throwable $e) {
            return $this->error('Failed to delete work order comment.', 500);
        }
    }
}
