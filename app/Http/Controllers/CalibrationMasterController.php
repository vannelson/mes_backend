<?php

namespace App\Http\Controllers;

use App\Http\Requests\CalibrationMaster\CalibrationMasterStoreRequest;
use App\Http\Requests\CalibrationMaster\CalibrationMasterUpdateRequest;
use App\Services\Contracts\CalibrationMasterServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

class CalibrationMasterController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected CalibrationMasterServiceInterface $calibrationMasterService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = Arr::get($request->all(), 'filters', []);

        foreach (['q', 'sheet_name', 'owner_location', 'function', 'frequency_label', 'due_state'] as $key) {
            $value = $request->get($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        $order = Arr::get($request->all(), 'order', ['next_calibration_date', 'asc']);
        $limit = (int) Arr::get($request->all(), 'limit', 25);
        $page = (int) Arr::get($request->all(), 'page', 1);

        try {
            $data = $this->calibrationMasterService->getList($filters, $order, $limit, $page);

            return $this->success('Calibration master records retrieved successfully!', $data);
        } catch (Throwable $e) {
            return $this->error('Failed to load calibration master records.', 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $record = $this->calibrationMasterService->detail($id);

            return $this->success('Calibration master record retrieved successfully!', $record);
        } catch (Throwable $e) {
            return $this->error('Failed to load calibration master record.', 500);
        }
    }

    public function insights(Request $request): JsonResponse
    {
        $nearDays = (int) $request->get('near_days', 30);

        try {
            return $this->success(
                'Calibration insights retrieved successfully!',
                $this->calibrationMasterService->insights($nearDays)
            );
        } catch (Throwable $e) {
            return $this->error('Failed to load calibration insights.', 500);
        }
    }

    public function store(CalibrationMasterStoreRequest $request): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            $record = $this->calibrationMasterService->create($request->validated());

            return $this->success('Calibration master record created successfully!', $record);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to create calibration master record.', 500);
        }
    }

    public function update(CalibrationMasterUpdateRequest $request, int $id): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            $record = $this->calibrationMasterService->update($id, $request->validated());

            return $record
                ? $this->success('Calibration master record updated successfully!', $record)
                : $this->error('Nothing to update.', 422);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Failed to update calibration master record.', 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            $this->calibrationMasterService->delete($id);

            return $this->success('Calibration master record deleted successfully!');
        } catch (Throwable $e) {
            return $this->error('Failed to delete calibration master record.', 500);
        }
    }

    protected function canManage(Request $request): bool
    {
        $role = strtolower(trim((string) ($request->user()?->user_type ?? $request->user()?->role ?? '')));

        return in_array($role, ['supervisor', 'manager', 'admin', 'superadmin'], true);
    }
}
