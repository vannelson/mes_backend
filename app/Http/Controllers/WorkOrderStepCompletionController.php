<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkOrder\WorkOrderStepCompleteRequest;
use App\Http\Requests\WorkOrder\WorkOrderStepReworkRequest;
use App\Models\WorkOrder;
use App\Models\WorkOrderStepCompletion;
use App\Services\WorkOrderNotificationService;
use App\Traits\ResponseTrait;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Throwable;

class WorkOrderStepCompletionController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected WorkOrderNotificationService $notificationService
    ) {
    }

    public function complete(WorkOrderStepCompleteRequest $request, int $id, string $stepKey): JsonResponse
    {
        $stepKey = trim($stepKey);
        if ($stepKey === '' || strlen($stepKey) > 150) {
            return $this->error('Invalid step key.', 422);
        }

        try {
            $workOrder = WorkOrder::query()->with('stepCompletions')->findOrFail($id);
            $validated = $request->validated();
            $resolvedStepKey = $workOrder->resolveStepKeyForCompletion($stepKey);
            if ($resolvedStepKey === null) {
                return $this->error('Step not found for the provided key.', 422);
            }
            if (strlen($resolvedStepKey) > 150) {
                return $this->error('Resolved step key exceeds length limit.', 422);
            }

            $completedAt = isset($validated['completed_at']) && $validated['completed_at']
                ? Carbon::parse($validated['completed_at'])
                : now();
            $completedBy = $validated['completed_by'] ?? $request->user()?->id;

            $payload = $validated['payload'] ?? [];
            if (! is_array($payload)) {
                $payload = [];
            }

            $payloadStatus = $payload['status'] ?? $validated['status'] ?? 'completed';
            $payload['status'] = $payloadStatus;
            if (! isset($payload['completed_at'])) {
                $payload['completed_at'] = $completedAt->toIso8601String();
            }
            if ($completedBy && ! isset($payload['completed_by'])) {
                $payload['completed_by'] = $completedBy;
            }

            $completion = WorkOrderStepCompletion::updateOrCreate(
                ['work_order_id' => $workOrder->id, 'step_key' => $resolvedStepKey],
                [
                    'status' => $payloadStatus,
                    'payload' => $payload,
                    'completed_at' => $completedAt,
                    'completed_by' => $completedBy,
                ]
            );

            $workOrder->touch();
            $workOrder->unsetRelation('stepCompletions');
            $workOrder->load('stepCompletions');
            $metadata = $workOrder->resolvedMetadata();
            $statusInfo = $workOrder->deriveStatusFromMetadata($metadata);
            $workOrder->status = $statusInfo['status'];
            $workOrder->completed_at = $statusInfo['status'] === 'completed'
                ? ($workOrder->completed_at ?? now())
                : null;
            $workOrder->save();

            try {
                $this->notificationService->notifyWorkOrder($workOrder, $request->user(), 'validation', [
                    'step_key' => $completion->step_key,
                    'status' => $completion->status,
                ]);
            } catch (Throwable) {
                // Notification failures should not block completion.
            }

            return $this->success('Work order step marked as completed!', [
                'work_order_id' => $workOrder->id,
                'step_key' => $completion->step_key,
                'status' => $completion->status,
                'completed_at' => $completion->completed_at?->toIso8601String(),
                'completed_by' => $completion->completed_by,
                'metadata' => $metadata,
            ]);
        } catch (ModelNotFoundException $e) {
            return $this->error('Work order not found.', 404);
        } catch (Throwable $e) {
            return $this->error('Failed to complete work order step.', 500);
        }
    }

    public function rework(WorkOrderStepReworkRequest $request, int $id, string $stepKey): JsonResponse
    {
        $stepKey = trim($stepKey);
        if ($stepKey === '' || strlen($stepKey) > 150) {
            return $this->error('Invalid step key.', 422);
        }

        try {
            $workOrder = WorkOrder::query()->with('stepCompletions')->findOrFail($id);
            $resolution = $workOrder->resolveStepListForKey($stepKey);
            if ($resolution === null) {
                return $this->error('Step not found for the provided key.', 422);
            }

            $steps = $resolution['steps'];
            $stepIndex = $resolution['index'];
            if (! isset($steps[$stepIndex]) || ! is_array($steps[$stepIndex])) {
                return $this->error('Step not found for the provided key.', 422);
            }

            $validated = $request->validated();
            $reworkAt = isset($validated['rework_at']) && $validated['rework_at']
                ? Carbon::parse($validated['rework_at'])
                : now();
            $reworkBy = $validated['rework_by'] ?? $request->user()?->id;
            $reason = trim((string) ($validated['reason'] ?? ''));

            $completionMap = $workOrder->stepCompletions->keyBy(
                static fn (WorkOrderStepCompletion $completion) => strtolower(trim($completion->step_key))
            );

            foreach ($steps as $index => $step) {
                if (! is_array($step)) {
                    continue;
                }

                $resolvedKey = $workOrder->canonicalStepKeyForStep($step, $index);
                if ($resolvedKey === null) {
                    continue;
                }
                if (strlen($resolvedKey) > 150) {
                    return $this->error('Resolved step key exceeds length limit.', 422);
                }

                $normalizedKey = strtolower(trim($resolvedKey));
                $existing = $completionMap->get($normalizedKey);
                $payload = $existing?->payload ?? [];
                if (! is_array($payload)) {
                    $payload = [];
                }

                if ($index < $stepIndex) {
                    $existingStatus = strtolower(trim((string) ($existing?->status ?? $payload['status'] ?? $step['status'] ?? '')));
                    if (! in_array($existingStatus, ['completed', 'complete', 'done'], true)) {
                        $payload['status'] = 'completed';
                        if (! isset($payload['completed_at']) || $payload['completed_at'] === null) {
                            $payload['completed_at'] = $existing?->completed_at?->toIso8601String()
                                ?? ($step['completed_at'] ?? $step['completedAt'] ?? null);
                        }

                        WorkOrderStepCompletion::updateOrCreate(
                            ['work_order_id' => $workOrder->id, 'step_key' => $resolvedKey],
                            [
                                'status' => 'completed',
                                'payload' => $payload,
                                'completed_at' => $existing?->completed_at,
                                'completed_by' => $existing?->completed_by,
                            ]
                        );
                    }

                    continue;
                }

                if ($index === $stepIndex) {
                    $payload['status'] = 'in_progress';
                    $payload['completed_at'] = null;
                    $payload['completed_by'] = null;

                    $history = $payload['rework_history'] ?? [];
                    if (! is_array($history)) {
                        $history = [];
                    }
                    $history[] = array_filter([
                        'reason' => $reason !== '' ? $reason : null,
                        'rework_by' => $reworkBy,
                        'rework_at' => $reworkAt->toIso8601String(),
                    ], static fn ($value) => $value !== null);
                    $payload['rework_history'] = $history;

                    WorkOrderStepCompletion::updateOrCreate(
                        ['work_order_id' => $workOrder->id, 'step_key' => $resolvedKey],
                        [
                            'status' => 'in_progress',
                            'payload' => $payload,
                            'completed_at' => null,
                            'completed_by' => null,
                        ]
                    );
                } else {
                    $payload['status'] = 'pending';
                    $payload['completed_at'] = null;
                    $payload['completed_by'] = null;

                    WorkOrderStepCompletion::updateOrCreate(
                        ['work_order_id' => $workOrder->id, 'step_key' => $resolvedKey],
                        [
                            'status' => 'pending',
                            'payload' => $payload,
                            'completed_at' => null,
                            'completed_by' => null,
                        ]
                    );
                }
            }

            $workOrder->touch();
            $workOrder->unsetRelation('stepCompletions');
            $workOrder->load('stepCompletions');
            $metadata = $workOrder->resolvedMetadata();
            $statusInfo = $workOrder->deriveStatusFromMetadata($metadata);
            $workOrder->status = 'in_progress';
            $workOrder->completed_at = null;
            if ($statusInfo['status'] === 'completed') {
                $workOrder->status = 'in_progress';
            }
            $workOrder->save();

            try {
                $this->notificationService->notifyWorkOrder($workOrder, $request->user(), 'rework', [
                    'step_key' => $workOrder->canonicalStepKeyForStep($steps[$stepIndex], $stepIndex),
                    'reason' => $reason,
                ]);
            } catch (Throwable) {
                // Notification failures should not block rework.
            }

            return $this->success('Work order step rework initialized!', [
                'work_order_id' => $workOrder->id,
                'step_key' => $workOrder->canonicalStepKeyForStep($steps[$stepIndex], $stepIndex),
                'status' => 'in_progress',
                'rework_at' => $reworkAt->toIso8601String(),
                'rework_by' => $reworkBy,
                'metadata' => $metadata,
            ]);
        } catch (ModelNotFoundException $e) {
            return $this->error('Work order not found.', 404);
        } catch (Throwable $e) {
            return $this->error('Failed to rework work order step.', 500);
        }
    }
}
