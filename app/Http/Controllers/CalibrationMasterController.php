<?php

namespace App\Http\Controllers;

use App\Http\Requests\CalibrationMaster\CalibrationHistoryStoreRequest;
use App\Http\Requests\CalibrationMaster\CalibrationImageUploadRequest;
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
    ) {}

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
        $page  = (int) Arr::get($request->all(), 'page', 1);

        try {
            return $this->success(
                'Calibration master records retrieved successfully!',
                $this->calibrationMasterService->getList($filters, $order, $limit, $page)
            );
        } catch (Throwable) {
            return $this->error('Failed to load calibration master records.', 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            return $this->success(
                'Calibration master record retrieved successfully!',
                $this->calibrationMasterService->detail($id)
            );
        } catch (Throwable) {
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
        } catch (Throwable) {
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

            return $this->success('Calibration master record created successfully!', $record, 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable) {
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
        } catch (Throwable) {
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
        } catch (Throwable) {
            return $this->error('Failed to delete calibration master record.', 500);
        }
    }

    // ── Status patch ─────────────────────────────────────────────────────────

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->error('Forbidden.', 403);
        }

        // cal_status may be null (to clear) or one of the valid values
        $calStatus = $request->input('cal_status');
        $allowed   = ['out_for_calibration', 'cert_received', 'spare', null];

        if (! in_array($calStatus, $allowed, true)) {
            return $this->error('Invalid cal_status value.', 422);
        }

        try {
            $record = $this->calibrationMasterService->updateStatus($id, $calStatus);

            return $this->success('Status updated successfully!', $record);
        } catch (Throwable) {
            return $this->error('Failed to update status.', 500);
        }
    }

    // ── History ──────────────────────────────────────────────────────────────

    public function history(int $id): JsonResponse
    {
        try {
            return $this->success(
                'Calibration history retrieved successfully!',
                $this->calibrationMasterService->getHistory($id)
            );
        } catch (Throwable) {
            return $this->error('Failed to load calibration history.', 500);
        }
    }

    public function recordHistory(CalibrationHistoryStoreRequest $request, int $id): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            $entry = $this->calibrationMasterService->addHistory(
                $id,
                $request->validated(),
                $request->file('cert_file'),
                $request->user()?->id
            );

            return $this->success('Calibration event recorded successfully!', $entry, 201);
        } catch (Throwable) {
            return $this->error('Failed to record calibration event.', 500);
        }
    }

    // ── Images ───────────────────────────────────────────────────────────────

    public function uploadImage(CalibrationImageUploadRequest $request, int $id): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            $image = $this->calibrationMasterService->uploadImage($id, $request->file('image'));

            return $this->success('Image uploaded successfully!', $image, 201);
        } catch (Throwable) {
            return $this->error('Failed to upload image.', 500);
        }
    }

    public function deleteImage(Request $request, int $id, int $imageId): JsonResponse
    {
        if (! $this->canManage($request)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            $this->calibrationMasterService->deleteImage($id, $imageId);

            return $this->success('Image deleted successfully!');
        } catch (Throwable) {
            return $this->error('Failed to delete image.', 500);
        }
    }

    // ── Guard ────────────────────────────────────────────────────────────────

    protected function canManage(Request $request): bool
    {
        $role = strtolower(trim((string) ($request->user()?->user_type ?? $request->user()?->role ?? '')));

        return in_array($role, ['supervisor', 'manager', 'admin', 'superadmin'], true);
    }
}
