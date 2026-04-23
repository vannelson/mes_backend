<?php

namespace App\Http\Controllers;

use App\Models\WorkOrderSetupInspectionChecklistRecord;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

class WorkOrderSetupInspectionChecklistController extends Controller
{
    use ResponseTrait;

    private const SAVE_LIMIT = 5;

    private function isPrivileged(?object $user): bool
    {
        $role = strtolower((string) ($user->user_type ?? ''));
        return in_array($role, ['manager', 'supervisor', 'admin', 'superadmin'], true);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'work_order_no' => ['required', 'string'],
            'route_key' => ['required', 'string'],
            'machine_key' => ['required', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = WorkOrderSetupInspectionChecklistRecord::query()
            ->where('work_order_no', $data['work_order_no'])
            ->where('route_key', $data['route_key'])
            ->where('machine_key', $data['machine_key'])
            ->orderBy('record_date')
            ->orderBy('slot');

        if (!empty($data['from'])) {
            $query->whereDate('record_date', '>=', $data['from']);
        }
        if (!empty($data['to'])) {
            $query->whereDate('record_date', '<=', $data['to']);
        }

        $rows = $query->get()->map(function ($row) use ($request) {
            return [
                'id' => $row->id,
                'workOrderNo' => $row->work_order_no,
                'routeKey' => $row->route_key,
                'routeName' => $row->route_name,
                'machineId' => $row->machine_id,
                'machineKey' => $row->machine_key,
                'machineType' => $row->machine_type,
                'machineNo' => $row->machine_no,
                'machineLabel' => $row->machine_label,
                'date' => $row->record_date?->format('Y-m-d'),
                'slot' => (int) $row->slot,
                'entries' => $row->entries ?? [],
                'saveCount' => (int) $row->save_count,
                'isLocked' => (bool) $row->is_locked,
                'lockedReason' => $row->locked_reason,
                'lockedBy' => $row->locked_by,
                'lockedAt' => optional($row->locked_at)->toISOString(),
                'unlockedBy' => $row->unlocked_by,
                'unlockedAt' => optional($row->unlocked_at)->toISOString(),
                'approvalStatus' => $row->approval_status,
                'approvedBy' => $row->approved_by,
                'approvedAt' => optional($row->approved_at)->toISOString(),
                'canUnlock' => $this->isPrivileged($request->user()),
                'canApprove' => $this->isPrivileged($request->user()),
            ];
        });

        return $this->success('Setup inspection checklist records retrieved successfully!', $rows);
    }

    public function upsert(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'work_order_no' => ['required', 'string'],
            'route_key' => ['required', 'string'],
            'route_name' => ['nullable', 'string'],
            'machine_id' => ['nullable', 'integer'],
            'machine_key' => ['required', 'string'],
            'machine_type' => ['required', 'string'],
            'machine_no' => ['nullable', 'string'],
            'machine_label' => ['nullable', 'string'],
            'date' => ['required', 'date'],
            'slot' => ['nullable', 'integer', 'min:1', 'max:50'],
            'entries' => ['nullable', 'array'],
        ]);

        $actor = $request->user();
        $privileged = $this->isPrivileged($actor);

        $slot = (int) ($payload['slot'] ?? 1);
        $date = Carbon::parse($payload['date'])->format('Y-m-d');

        $record = WorkOrderSetupInspectionChecklistRecord::query()->where([
            'work_order_no' => $payload['work_order_no'],
            'route_key' => $payload['route_key'],
            'machine_key' => $payload['machine_key'],
            'record_date' => $date,
            'slot' => $slot,
        ])->first();

        if ($record && $record->is_locked && !$privileged) {
            return $this->error('Checklist is locked and requires supervisor unlock.', 403);
        }

        $nextSaveCount = (int) (($record?->save_count ?? 0) + 1);
        $willLock = $nextSaveCount >= self::SAVE_LIMIT;

        $values = [
            'work_order_no' => $payload['work_order_no'],
            'route_key' => $payload['route_key'],
            'route_name' => $payload['route_name'] ?? null,
            'machine_id' => $payload['machine_id'] ?? null,
            'machine_key' => $payload['machine_key'],
            'machine_type' => $payload['machine_type'],
            'machine_no' => $payload['machine_no'] ?? null,
            'machine_label' => $payload['machine_label'] ?? null,
            'record_date' => $date,
            'slot' => $slot,
            'entries' => $payload['entries'] ?? [],
            'save_count' => min($nextSaveCount, 255),
            'is_locked' => $willLock ? true : ($record?->is_locked ?? false),
            'locked_reason' => $willLock ? 'Save limit reached' : ($record?->locked_reason ?? null),
            'locked_by' => $willLock ? ($record?->locked_by ?? $actor?->id) : ($record?->locked_by ?? null),
            'locked_at' => $willLock ? ($record?->locked_at ?? now()) : ($record?->locked_at ?? null),
        ];

        $model = WorkOrderSetupInspectionChecklistRecord::updateOrCreate([
            'work_order_no' => $payload['work_order_no'],
            'route_key' => $payload['route_key'],
            'machine_key' => $payload['machine_key'],
            'record_date' => $date,
            'slot' => $slot,
        ], $values);

        return $this->success('Checklist saved successfully!', [
            'id' => $model->id,
            'date' => $model->record_date?->format('Y-m-d'),
            'slot' => (int) $model->slot,
            'entries' => $model->entries ?? [],
            'saveCount' => (int) $model->save_count,
            'isLocked' => (bool) $model->is_locked,
            'lockedReason' => $model->locked_reason,
        ], 201);
    }

    public function approve(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->isPrivileged($actor)) {
            return $this->error('Forbidden.', 403);
        }

        $data = $request->validate([
            'work_order_no' => ['required', 'string'],
            'route_key' => ['required', 'string'],
            'machine_key' => ['required', 'string'],
            'date' => ['required', 'date'],
            'slot' => ['nullable', 'integer', 'min:1', 'max:50'],
            'status' => ['required', 'in:approved,rejected,pending'],
        ]);

        $slot = (int) ($data['slot'] ?? 1);
        $date = Carbon::parse($data['date'])->format('Y-m-d');

        $model = WorkOrderSetupInspectionChecklistRecord::query()->where([
            'work_order_no' => $data['work_order_no'],
            'route_key' => $data['route_key'],
            'machine_key' => $data['machine_key'],
            'record_date' => $date,
            'slot' => $slot,
        ])->first();

        if (!$model) {
            return $this->error('Checklist record not found.', 404);
        }

        $model->approval_status = $data['status'];
        $model->approved_by = $data['status'] === 'pending' ? null : $actor?->id;
        $model->approved_at = $data['status'] === 'pending' ? null : now();
        $model->save();

        return $this->success('Checklist review updated successfully!', [
            'approvalStatus' => $model->approval_status,
            'approvedBy' => $model->approved_by,
            'approvedAt' => optional($model->approved_at)->toISOString(),
        ]);
    }

    public function unlock(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->isPrivileged($actor)) {
            return $this->error('Forbidden.', 403);
        }

        $data = $request->validate([
            'work_order_no' => ['required', 'string'],
            'route_key' => ['required', 'string'],
            'machine_key' => ['required', 'string'],
            'date' => ['required', 'date'],
            'slot' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $slot = (int) ($data['slot'] ?? 1);
        $date = Carbon::parse($data['date'])->format('Y-m-d');

        $model = WorkOrderSetupInspectionChecklistRecord::query()->where([
            'work_order_no' => $data['work_order_no'],
            'route_key' => $data['route_key'],
            'machine_key' => $data['machine_key'],
            'record_date' => $date,
            'slot' => $slot,
        ])->first();

        if (!$model) {
            return $this->error('Checklist record not found.', 404);
        }

        $model->is_locked = false;
        $model->locked_reason = null;
        $model->unlocked_by = $actor?->id;
        $model->unlocked_at = now();
        $model->save();

        return $this->success('Checklist unlocked successfully!', [
            'isLocked' => (bool) $model->is_locked,
            'unlockedBy' => $model->unlocked_by,
            'unlockedAt' => optional($model->unlocked_at)->toISOString(),
        ]);
    }
}

