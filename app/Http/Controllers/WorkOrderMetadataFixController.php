<?php

namespace App\Http\Controllers;

use App\Services\WorkOrderMetadataRepairService;
use App\Traits\ResponseTrait;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class WorkOrderMetadataFixController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected WorkOrderMetadataRepairService $repairService
    ) {
    }

    public function examine(Request $request): JsonResponse
    {
        if (! $this->isPrivileged($request)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            $validated = $request->validate([
                'work_order_id' => ['nullable', 'integer', 'min:1', 'required_without:work_order_no'],
                'work_order_no' => ['nullable', 'string', 'max:120', 'required_without:work_order_id'],
            ]);

            $data = $this->repairService->examine($validated);

            return $this->success('Work order metadata examined successfully!', $data);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (ModelNotFoundException) {
            return $this->error('Work order not found.', 404);
        } catch (Throwable) {
            return $this->error('Failed to examine work order metadata.', 500);
        }
    }

    public function apply(Request $request): JsonResponse
    {
        if (! $this->isPrivileged($request)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            $validated = $request->validate([
                'work_order_id' => ['required', 'integer', 'min:1'],
                'payload' => ['required', 'array'],
                'payload.status' => ['nullable', 'string', 'max:40'],
                'payload.production_date_completed' => ['nullable', 'date'],
                'payload.production_qty_completed' => ['nullable', 'string', 'max:100'],
                'payload.is_released' => ['nullable', 'boolean'],
                'payload.completed_at' => ['nullable', 'date'],
                'payload.metadata' => ['required', 'array'],
            ]);

            $data = $this->repairService->apply(
                (int) $validated['work_order_id'],
                $validated['payload'],
                $request->user()
            );

            return $this->success('Work order metadata repair applied successfully!', $data);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (ModelNotFoundException) {
            return $this->error('Work order not found.', 404);
        } catch (Throwable) {
            return $this->error('Failed to apply work order metadata repair.', 500);
        }
    }

    protected function isPrivileged(Request $request): bool
    {
        $role = strtolower(trim((string) ($request->user()?->user_type ?? $request->user()?->role ?? '')));

        return in_array($role, ['supervisor', 'manager', 'admin', 'superadmin'], true);
    }
}
