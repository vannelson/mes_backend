<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
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

        $recipients = $this->resolveRecipients($actor);
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

    protected function resolveRecipients(?User $actor)
    {
        return User::query()->select('id')->pluck('id');
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
