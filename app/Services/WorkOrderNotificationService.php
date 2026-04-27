<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class WorkOrderNotificationService
{
    public function __construct(
        protected FirebaseRealtimeService $firebaseRealtimeService
    ) {
    }

    public function listForUser(User $user, array $filters = [], int $limit = 20, int $page = 1): LengthAwarePaginator
    {
        $query = WorkOrderNotification::query()
            ->with(['actor', 'workOrder'])
            ->where('recipient_id', $user->id);

        $status = strtolower(trim((string) Arr::get($filters, 'status', '')));
        if ($status === 'unread') {
            $query->whereNull('read_at');
        } elseif ($status === 'read') {
            $query->whereNotNull('read_at');
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function unreadCount(User $user): int
    {
        return WorkOrderNotification::query()
            ->where('recipient_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    public function markRead(User $user, array $ids = [], bool $all = false): int
    {
        $query = WorkOrderNotification::query()
            ->where('recipient_id', $user->id)
            ->whereNull('read_at');

        if (!$all) {
            $ids = array_values(array_filter($ids, static fn ($id) => is_numeric($id)));
            if (empty($ids)) {
                return 0;
            }
            $query->whereIn('id', $ids);
        }

        return $query->update(['read_at' => now()]);
    }

    public function markUnread(User $user, array $ids = []): int
    {
        $ids = array_values(array_filter($ids, static fn ($id) => is_numeric($id)));
        if (empty($ids)) {
            return 0;
        }

        return WorkOrderNotification::query()
            ->where('recipient_id', $user->id)
            ->whereIn('id', $ids)
            ->update(['read_at' => null]);
    }

    public function notifyWorkOrder(
        WorkOrder $workOrder,
        ?User $actor,
        ?string $context = null,
        array $meta = []
    ): void {
        $context = $this->normalizeContext($context);
        $actorName = $this->resolveActorName($actor);
        $workOrderLabel = $workOrder->work_order_no ?: (string) $workOrder->id;
        $stepLabel = $meta['step_name'] ?? $meta['step_label'] ?? $meta['step_key'] ?? null;
        $checklistType = $meta['checklist_type'] ?? null;

        [$type, $title, $message] = $this->buildMessage(
            $context,
            $actorName,
            $workOrderLabel,
            $stepLabel,
            $meta,
            $checklistType
        );

        $recipients = $this->resolveRecipients($workOrder, $actor);
        if ($recipients->isEmpty()) {
            return;
        }

        $actionUrl = $meta['action_url'] ?? "/operations/work-orders/{$workOrder->id}";
        $payloadData = array_filter([
            'context' => $context,
            'step_key' => $meta['step_key'] ?? null,
            'step_name' => $meta['step_name'] ?? null,
            'route_key' => $meta['route_key'] ?? null,
            'action' => $meta['action'] ?? null,
            'source' => $meta['source'] ?? null,
            'status' => $meta['status'] ?? null,
            'reason' => $meta['reason'] ?? null,
            'checklist_type' => $checklistType,
        ], static fn ($value) => $value !== null && $value !== '');
        $payloadData = !empty($payloadData) ? $payloadData : null;

        foreach ($recipients as $recipientId) {
            try {
                WorkOrderNotification::query()->create([
                    'recipient_id' => $recipientId,
                    'actor_id' => $actor?->id,
                    'work_order_id' => $workOrder->id,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'action_url' => $actionUrl,
                    'data' => $payloadData,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to create work order notification.', [
                    'recipient_id' => $recipientId,
                    'work_order_id' => $workOrder->id,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->firebaseRealtimeService->publishNotificationUpdate([
            'work_order_id' => $workOrder->id,
            'work_order_no' => $workOrder->work_order_no,
            'type' => $type,
            'context' => $context,
            'actor_id' => $actor?->id,
            'actor_name' => $actorName,
        ]);
    }

    protected function resolveRecipients(WorkOrder $workOrder, ?User $actor): Collection
    {
        $privilegedRoleIds = User::query()
            ->whereIn('user_type', ['manager', 'supervisor'])
            ->pluck('id');

        $assignedOperatorIds = $this->resolveAssignedOperatorIds($workOrder);

        return $privilegedRoleIds
            ->merge($assignedOperatorIds)
            ->filter(static fn ($id) => !is_null($id) && $id !== '')
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    protected function resolveAssignedOperatorIds(WorkOrder $workOrder): Collection
    {
        $workOrder->loadMissing('userAssignments');

        $ids = collect($workOrder->userAssignments)
            ->pluck('user_id')
            ->filter();

        foreach ($this->extractRoutes($workOrder->metadata) as $route) {
            if (!is_array($route)) {
                continue;
            }

            $operators = Arr::get($route, 'operators', []);
            if (is_array($operators)) {
                foreach ($operators as $operator) {
                    $candidate = Arr::get($operator, 'id')
                        ?? Arr::get($operator, 'user_id')
                        ?? Arr::get($operator, 'userId');
                    if ($candidate !== null && $candidate !== '') {
                        $ids->push($candidate);
                    }
                }
            }

            $directOperatorId = Arr::get($route, 'operator_id')
                ?? Arr::get($route, 'operatorId')
                ?? Arr::get($route, 'user_id')
                ?? Arr::get($route, 'metadata.machineOperatorId')
                ?? Arr::get($route, 'machineOperatorId');
            if ($directOperatorId !== null && $directOperatorId !== '') {
                $ids->push($directOperatorId);
            }

            $additionalMachines = Arr::get($route, 'metadata.additionalMachines')
                ?? Arr::get($route, 'additionalMachines')
                ?? [];
            if (!is_array($additionalMachines)) {
                continue;
            }

            foreach ($additionalMachines as $machine) {
                if (!is_array($machine)) {
                    continue;
                }

                $machineOperatorId = Arr::get($machine, 'operatorId')
                    ?? Arr::get($machine, 'machineOperatorId')
                    ?? Arr::get($machine, 'operator_id')
                    ?? Arr::get($machine, 'user_id')
                    ?? Arr::get($machine, 'machineDetails.operatorId')
                    ?? Arr::get($machine, 'machineDetails.machineOperatorId')
                    ?? Arr::get($machine, 'machine.operatorId')
                    ?? Arr::get($machine, 'machine.machineOperatorId')
                    ?? Arr::get($machine, 'metadata.operatorId')
                    ?? Arr::get($machine, 'metadata.machineOperatorId');
                if ($machineOperatorId !== null && $machineOperatorId !== '') {
                    $ids->push($machineOperatorId);
                }
            }
        }

        return $ids
            ->map(static fn ($id) => (int) $id)
            ->filter(static fn ($id) => $id > 0)
            ->unique()
            ->values();
    }

    protected function normalizeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    protected function extractRoutes(mixed $metadata): array
    {
        $normalized = $this->normalizeMetadata($metadata);
        $routes =
            Arr::get($normalized, 'assignments.routes') ??
            Arr::get($normalized, 'route_assignments') ??
            Arr::get($normalized, 'routeAssignments') ??
            Arr::get($normalized, 'routes') ??
            Arr::get($normalized, 'steps') ??
            Arr::get($normalized, 'data') ??
            [];

        if (is_array($routes) && Arr::has($routes, 'routes')) {
            $routes = Arr::get($routes, 'routes', []);
        }

        $flattened = [];
        foreach ((array) $routes as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (isset($entry['routes']) && is_array($entry['routes'])) {
                foreach ($entry['routes'] as $route) {
                    if (is_array($route)) {
                        $flattened[] = $route;
                    }
                }
                continue;
            }
            $flattened[] = $entry;
        }

        return $flattened;
    }

    protected function normalizeContext(?string $context): string
    {
        $context = strtolower(trim((string) $context));
        if ($context === '') {
            return 'edit';
        }
        return $context;
    }

    protected function resolveActorName(?User $actor): string
    {
        if (!$actor) {
            return 'System';
        }
        $name = trim(implode(' ', array_filter([
            $actor->firstname ?? null,
            $actor->middlename ?? null,
            $actor->lastname ?? null,
        ])));
        return $name !== '' ? $name : ($actor->email ?? 'User');
    }

    protected function buildMessage(
        string $context,
        string $actorName,
        string $workOrderLabel,
        ?string $stepLabel,
        array $meta,
        ?string $checklistType
    ): array {
        $stepSuffix = $stepLabel ? " ({$stepLabel})" : '';
        $type = 'work_order_update';
        $title = 'Work order updated';
        $message = "{$actorName} updated work order {$workOrderLabel}.";

        switch ($context) {
            case 'checklist':
                $type = 'work_order_checklist';
                $title = $checklistType === 'packing' ? 'Packing checklist updated' : 'Checklist updated';
                $message = "{$actorName} updated checklist for WO {$workOrderLabel}{$stepSuffix}.";
                break;
            case 'validation':
                $type = 'work_order_validation';
                $title = 'Step validated';
                $message = "{$actorName} validated WO {$workOrderLabel}{$stepSuffix}.";
                break;
            case 'progress':
                $type = 'work_order_progress';
                $title = 'Progress updated';
                $action = $meta['action'] ?? null;
                $actionLabel = $action ? " ({$action})" : '';
                $message = "{$actorName} updated progress on WO {$workOrderLabel}{$actionLabel}{$stepSuffix}.";
                break;
            case 'rework':
                $type = 'work_order_rework';
                $title = 'Rework initiated';
                $message = "{$actorName} sent WO {$workOrderLabel}{$stepSuffix} to rework.";
                break;
            case 'release':
                $type = 'work_order_release';
                $title = 'Work order released';
                $message = "{$actorName} released WO {$workOrderLabel}.";
                break;
            default:
                break;
        }

        return [$type, $title, $message];
    }
}
