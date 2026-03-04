<?php

namespace App\Http\Controllers;

use App\Http\Requests\OperationTrigger\OperationTriggerExecuteRequest;
use App\Http\Requests\OperationTrigger\OperationTriggerApiToolPreviewRequest;
use App\Http\Requests\OperationTrigger\OperationTriggerSimulateRequest;
use App\Http\Requests\OperationTrigger\OperationTriggerStoreRequest;
use App\Http\Requests\OperationTrigger\OperationTriggerUpdateRequest;
use App\Services\Contracts\OperationTriggerServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

class OperationTriggerController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected OperationTriggerServiceInterface $triggerService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);
        $filters['status'] = $request->get('status', $filters['status'] ?? null);
        $filters['q'] = $request->get('q', $filters['q'] ?? null);
        $order = Arr::get($request->all(), 'order', ['updated_at', 'desc']);
        $limit = (int) Arr::get($request->all(), 'limit', 20);
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->triggerService->getList($filters, $order, $limit, $page);

            return $this->successPagination('Operation triggers retrieved successfully.', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load operation triggers.', 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $data = $this->triggerService->detail($id);

            return $this->success('Operation trigger retrieved successfully.', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load operation trigger.', 500);
        }
    }

    public function store(OperationTriggerStoreRequest $request): JsonResponse
    {
        try {
            $actorId = $request->user()?->id;
            $data = $this->triggerService->create($request->validated(), $actorId);

            return $this->success('Operation trigger created successfully.', $data, 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to create operation trigger.', 500);
        }
    }

    public function update(OperationTriggerUpdateRequest $request, int $id): JsonResponse
    {
        try {
            $actorId = $request->user()?->id;
            $data = $this->triggerService->update($id, $request->validated(), $actorId);

            return $this->success('Operation trigger updated successfully.', $data);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to update operation trigger.', 500);
        }
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        try {
            $actorId = $request->user()?->id;
            $data = $this->triggerService->publish($id, $actorId);

            return $this->success('Operation trigger published successfully.', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to publish operation trigger.', 500);
        }
    }

    public function disable(Request $request, int $id): JsonResponse
    {
        try {
            $actorId = $request->user()?->id;
            $data = $this->triggerService->disable($id, $actorId);

            return $this->success('Operation trigger disabled successfully.', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to disable operation trigger.', 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $actorId = $request->user()?->id;
            $data = $this->triggerService->delete($id, $actorId);

            return $this->success('Operation trigger deleted successfully.', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to delete operation trigger.', 500);
        }
    }

    public function simulate(OperationTriggerSimulateRequest $request, int $id): JsonResponse
    {
        try {
            $payload = $request->validated();
            $payload['authorization'] = $request->header('Authorization');
            $data = $this->triggerService->simulate($id, $payload);

            return $this->success('Operation trigger simulation complete.', $data);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to simulate operation trigger.', 500);
        }
    }

    public function execute(OperationTriggerExecuteRequest $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $executeKey = config('services.operation_triggers.execute_key');
        $headerKey = $request->header('X-Trigger-Key');

        if (! $actor) {
            if (! $executeKey || $headerKey !== $executeKey) {
                return $this->error('Unauthorized trigger execution.', 401);
            }
        }

        try {
            $payload = $request->validated();
            $payload['authorization'] = $request->header('Authorization');
            $data = $this->triggerService->execute($id, $payload, $actor?->id);

            return $this->success('Operation trigger executed.', $data);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to execute operation trigger.', 500);
        }
    }

    public function previewApiTool(
        OperationTriggerApiToolPreviewRequest $request,
        int $id
    ): JsonResponse {
        try {
            $payload = $request->validated();
            $payload['authorization'] = $request->header('Authorization');
            $data = $this->triggerService->previewApiTool($id, $payload);

            return $this->success('API tool preview complete.', $data);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to preview API tool.', 500);
        }
    }
}
