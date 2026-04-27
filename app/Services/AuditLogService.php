<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class AuditLogService
{
    public function list(array $filters = [], ?User $viewer = null, int $limit = 25, int $page = 1): array
    {
        $limit = max(1, min($limit, 100));
        $page = max(1, $page);

        $query = AuditLog::query();

        if ($this->shouldRestrictToActor($viewer) && $viewer?->id) {
            $query->where('user_id', $viewer->id);
        }

        $this->applyFilters($query, $filters);

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query
            ->orderByDesc('created_at')
            ->paginate($limit, ['*'], 'page', $page);

        return [
            'data' => $paginator->getCollection()
                ->map(fn (AuditLog $log) => $this->transformLog($log))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function record(array $payload): void
    {
        $details = $payload['details'] ?? null;
        if (is_string($details)) {
            $decoded = json_decode($details, true);
            $details = is_array($decoded) ? $decoded : ['value' => $details];
        }

        AuditLog::query()->create([
            'user_id' => $payload['user_id'] ?? null,
            'work_order_id' => $payload['work_order_id'] ?? null,
            'work_order_no' => $payload['work_order_no'] ?? null,
            'route_key' => $payload['route_key'] ?? null,
            'action' => (string) ($payload['action'] ?? 'work_order_update'),
            'context' => $payload['context'] ?? null,
            'entity_type' => $payload['entity_type'] ?? 'work_order',
            'entity_id' => isset($payload['entity_id']) ? (string) $payload['entity_id'] : null,
            'actor_name' => $payload['actor_name'] ?? null,
            'actor_role' => $payload['actor_role'] ?? null,
            'summary' => $payload['summary'] ?? null,
            'details' => is_array($details) ? $details : null,
        ]);
    }

    public function logWorkOrderUpdate(
        WorkOrder $beforeOrder,
        WorkOrder $afterOrder,
        array $changedFields,
        ?User $actor = null,
        ?string $context = null,
        array $meta = [],
        array $beforeMetadata = [],
        array $afterMetadata = []
    ): void {
        $focusRouteKey = $this->resolveRouteKey($meta);
        $changedRoutes = $this->collectRouteChanges($beforeMetadata, $afterMetadata, $focusRouteKey);
        $action = $this->normalizeWorkOrderAction($context);

        $details = [
            'changed_fields' => array_values($changedFields),
            'step_key' => $meta['step_key'] ?? $meta['stepKey'] ?? null,
            'step_name' => $meta['step_name'] ?? $meta['stepName'] ?? null,
            'status' => $meta['status'] ?? null,
            'override' => (bool) ($meta['override'] ?? false),
            'changed_routes' => $changedRoutes,
        ];

        if (!empty($meta)) {
            $details['meta'] = $meta;
        }

        $this->record([
            'user_id' => $actor?->id,
            'work_order_id' => $afterOrder->id,
            'work_order_no' => $afterOrder->work_order_no,
            'route_key' => $focusRouteKey,
            'action' => $action,
            'context' => $context,
            'entity_type' => 'work_order',
            'entity_id' => $afterOrder->id,
            'actor_name' => $this->resolveActorName($actor),
            'actor_role' => $actor?->user_type,
            'summary' => $this->buildWorkOrderSummary($afterOrder, $action, $meta, $changedRoutes),
            'details' => $details,
        ]);
    }

    public function logTimeTracker(WorkOrder $workOrder, array $route, array $entry, User $actor, array $payload = []): void
    {
        $action = strtolower((string) ($entry['action'] ?? 'update'));
        $routeKey = $payload['route_key'] ?? $payload['routeKey'] ?? ($route['route'] ?? $route['key'] ?? null);
        $routeName = $route['name'] ?? null;

        $this->record([
            'user_id' => $actor->id,
            'work_order_id' => $workOrder->id,
            'work_order_no' => $workOrder->work_order_no,
            'route_key' => $routeKey,
            'action' => sprintf('route_progress_%s', $action ?: 'update'),
            'context' => 'work_progress',
            'entity_type' => 'work_order_route',
            'entity_id' => $entry['id'] ?? $routeKey,
            'actor_name' => $this->resolveActorName($actor),
            'actor_role' => $actor->user_type,
            'summary' => trim(sprintf(
                '%s route %s on work order %s',
                ucfirst($action ?: 'updated'),
                $routeName ?: (string) $routeKey,
                $workOrder->work_order_no
            )),
            'details' => [
                'route_name' => $routeName,
                'operator_id' => $entry['operator_id'] ?? null,
                'operator_name' => $entry['operator_name'] ?? null,
                'source' => $entry['source'] ?? null,
                'printed_qty' => $entry['printed_qty'] ?? null,
                'operator_progress_pct' => $entry['operator_progress_pct'] ?? null,
                'route_progress_pct' => $entry['route_progress_pct'] ?? null,
                'total_printed_qty' => $entry['total_printed_qty'] ?? null,
                'target_printed_qty' => $entry['target_printed_qty'] ?? null,
                'pause_reason' => $entry['pause_reason'] ?? null,
                'pause_reason_key' => $entry['pause_reason_key'] ?? null,
                'pause_note' => $entry['pause_note'] ?? null,
                'override' => (bool) ($entry['override'] ?? false),
                'recorded_by' => $entry['recorded_by'] ?? null,
            ],
        ]);
    }

    public function logChecklistAction(
        string $action,
        array $context,
        ?User $actor = null,
        array $details = []
    ): void {
        $summary = $context['summary'] ?? null;
        unset($context['summary']);

        $this->record([
            'user_id' => $actor?->id,
            'work_order_id' => $context['work_order_id'] ?? null,
            'work_order_no' => $context['work_order_no'] ?? null,
            'route_key' => $context['route_key'] ?? null,
            'action' => $action,
            'context' => $context['context'] ?? 'checklist',
            'entity_type' => $context['entity_type'] ?? 'checklist',
            'entity_id' => isset($context['entity_id']) ? (string) $context['entity_id'] : null,
            'actor_name' => $this->resolveActorName($actor),
            'actor_role' => $actor?->user_type,
            'summary' => $summary,
            'details' => array_merge($context, $details),
        ]);
    }

    protected function applyFilters(Builder $query, array $filters): void
    {
        $action = trim((string) ($filters['action'] ?? ''));
        if ($action !== '') {
            $query->where('action', $action);
        }

        $context = trim((string) ($filters['context'] ?? ''));
        if ($context !== '') {
            $query->where('context', $context);
        }

        $workOrderNo = trim((string) ($filters['work_order_no'] ?? ''));
        if ($workOrderNo !== '') {
            $query->where('work_order_no', 'like', '%' . $workOrderNo . '%');
        }

        $routeKey = trim((string) ($filters['route_key'] ?? ''));
        if ($routeKey !== '') {
            $query->where('route_key', 'like', '%' . $routeKey . '%');
        }

        $userId = (int) ($filters['user_id'] ?? 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        $from = trim((string) ($filters['from'] ?? ''));
        if ($from !== '') {
            $query->whereDate('created_at', '>=', $from);
        }

        $to = trim((string) ($filters['to'] ?? ''));
        if ($to !== '') {
            $query->whereDate('created_at', '<=', $to);
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(function (Builder $inner) use ($q): void {
                $like = '%' . $q . '%';
                $inner
                    ->where('work_order_no', 'like', $like)
                    ->orWhere('route_key', 'like', $like)
                    ->orWhere('action', 'like', $like)
                    ->orWhere('context', 'like', $like)
                    ->orWhere('actor_name', 'like', $like)
                    ->orWhere('actor_role', 'like', $like)
                    ->orWhere('summary', 'like', $like);
            });
        }
    }

    protected function transformLog(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'context' => $log->context,
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'work_order_id' => $log->work_order_id,
            'work_order_no' => $log->work_order_no,
            'route_key' => $log->route_key,
            'actor_name' => $log->actor_name,
            'actor_role' => $log->actor_role,
            'summary' => $log->summary,
            'details' => $log->details ?? [],
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    protected function isPrivileged(?User $user): bool
    {
        $role = strtolower((string) ($user->user_type ?? ''));
        return in_array($role, ['manager', 'supervisor', 'admin', 'superadmin'], true);
    }

    protected function shouldRestrictToActor(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower((string) ($user->user_type ?? ''));
        return $role === 'operator';
    }

    protected function resolveActorName(?User $actor): ?string
    {
        if (!$actor) {
            return null;
        }

        return $actor->name
            ?: $actor->full_name
            ?: $actor->username
            ?: $actor->email;
    }

    protected function normalizeWorkOrderAction(?string $context): string
    {
        $value = trim((string) $context);
        return $value !== '' ? $value : 'work_order_update';
    }

    protected function resolveRouteKey(array $meta): ?string
    {
        $value = $meta['route_key']
            ?? $meta['routeKey']
            ?? $meta['step_key']
            ?? $meta['stepKey']
            ?? null;

        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed !== '' ? $trimmed : null;
    }

    protected function buildWorkOrderSummary(WorkOrder $order, string $action, array $meta, array $changedRoutes): string
    {
        $routeLabel = $meta['step_name'] ?? $meta['step_key'] ?? $meta['route_key'] ?? $meta['routeKey'] ?? null;
        $base = match ($action) {
            'validation' => 'Completed validation',
            'validation_update' => 'Updated validation',
            'machine_assignment' => 'Changed route machine',
            'machine_additional_add' => 'Added machine assignment',
            'machine_additional_update' => 'Updated machine assignment',
            'machine_additional_remove' => 'Removed machine assignment',
            'remark_add' => 'Added work packet remark',
            'rework' => 'Sent route to rework',
            'unlock' => 'Unlocked route',
            'supervisor_override_pending' => 'Supervisor marked route pending',
            'validation_signoff' => 'Captured validation sign-off',
            'press_plan_update' => 'Updated press plan',
            'scrap_update' => 'Updated scrap details',
            default => 'Updated work packet',
        };

        if ($routeLabel) {
            return sprintf('%s for %s on work order %s', $base, $routeLabel, $order->work_order_no);
        }

        if (!empty($changedRoutes)) {
            $firstRoute = Arr::get($changedRoutes, '0.route_name') ?: Arr::get($changedRoutes, '0.route_key');
            if ($firstRoute) {
                return sprintf('%s for %s on work order %s', $base, $firstRoute, $order->work_order_no);
            }
        }

        return sprintf('%s on work order %s', $base, $order->work_order_no);
    }

    protected function collectRouteChanges(array $beforeMetadata, array $afterMetadata, ?string $focusRouteKey = null): array
    {
        $beforeRoutes = $this->indexRoutesByKey($beforeMetadata);
        $afterRoutes = $this->indexRoutesByKey($afterMetadata);

        if ($focusRouteKey) {
            return array_values(array_filter([
                $this->summarizeRouteChange(
                    $focusRouteKey,
                    $beforeRoutes[$focusRouteKey] ?? null,
                    $afterRoutes[$focusRouteKey] ?? null
                ),
            ]));
        }

        $changed = [];
        $keys = array_unique(array_merge(array_keys($beforeRoutes), array_keys($afterRoutes)));
        foreach ($keys as $key) {
            $summary = $this->summarizeRouteChange($key, $beforeRoutes[$key] ?? null, $afterRoutes[$key] ?? null);
            if ($summary) {
                $changed[] = $summary;
            }
            if (count($changed) >= 10) {
                break;
            }
        }

        return $changed;
    }

    protected function indexRoutesByKey(array $metadata): array
    {
        $routes = $metadata['routes'] ?? $metadata['data'] ?? $metadata['steps'] ?? [];
        if (!is_array($routes)) {
            return [];
        }

        $indexed = [];
        foreach ($routes as $index => $route) {
            if (!is_array($route)) {
                continue;
            }

            if (isset($route['routes']) && is_array($route['routes'])) {
                foreach ($route['routes'] as $nestedIndex => $nestedRoute) {
                    if (!is_array($nestedRoute)) {
                        continue;
                    }
                    $key = $this->routeIdentifier($nestedRoute, sprintf('%s.%s', $index, $nestedIndex));
                    $indexed[$key] = $nestedRoute;
                }
                continue;
            }

            $key = $this->routeIdentifier($route, (string) $index);
            $indexed[$key] = $route;
        }

        return $indexed;
    }

    protected function routeIdentifier(array $route, string $fallback): string
    {
        $value = $route['route']
            ?? $route['key']
            ?? $route['route_key']
            ?? $route['routeKey']
            ?? $route['name']
            ?? null;

        $value = is_string($value) ? trim($value) : '';
        return $value !== '' ? $value : $fallback;
    }

    protected function summarizeRouteChange(string $routeKey, ?array $beforeRoute, ?array $afterRoute): ?array
    {
        if ($beforeRoute === null && $afterRoute === null) {
            return null;
        }

        if ($beforeRoute !== null && $afterRoute !== null && json_encode($beforeRoute) === json_encode($afterRoute)) {
            return null;
        }

        $machineBefore = $this->machineLabel($beforeRoute);
        $machineAfter = $this->machineLabel($afterRoute);
        $statusBefore = $beforeRoute['status'] ?? null;
        $statusAfter = $afterRoute['status'] ?? null;
        $scrapBefore = $beforeRoute['scrap'] ?? null;
        $scrapAfter = $afterRoute['scrap'] ?? null;

        return [
            'route_key' => $routeKey,
            'route_name' => $afterRoute['name'] ?? $beforeRoute['name'] ?? null,
            'machine_before' => $machineBefore,
            'machine_after' => $machineAfter,
            'status_before' => $statusBefore,
            'status_after' => $statusAfter,
            'scrap_before' => $scrapBefore,
            'scrap_after' => $scrapAfter,
            'flags' => [
                'machine_changed' => $machineBefore !== $machineAfter,
                'validation_changed' => ($beforeRoute['validation'] ?? null) != ($afterRoute['validation'] ?? null),
                'checklist_changed' => ($beforeRoute['checks'] ?? null) != ($afterRoute['checks'] ?? null),
                'notes_changed' => ($beforeRoute['notes'] ?? null) != ($afterRoute['notes'] ?? null),
                'remarks_changed' => ($beforeRoute['remarks'] ?? null) != ($afterRoute['remarks'] ?? null),
                'operator_changed' => ($beforeRoute['operator'] ?? null) != ($afterRoute['operator'] ?? null),
                'scrap_changed' => $scrapBefore != $scrapAfter,
                'time_tracker_changed' => Arr::get($beforeRoute, 'metadata.timeTracker') != Arr::get($afterRoute, 'metadata.timeTracker'),
                'override_changed' => ($beforeRoute['override'] ?? null) != ($afterRoute['override'] ?? null),
            ],
        ];
    }

    protected function machineLabel(?array $route): ?string
    {
        if (!is_array($route)) {
            return null;
        }

        $machine = $route['machine'] ?? Arr::get($route, 'metadata.machine');
        if (is_string($machine)) {
            return trim($machine) ?: null;
        }
        if (!is_array($machine)) {
            return null;
        }

        $name = trim((string) (
            $machine['machine_name']
            ?? $machine['name']
            ?? $machine['machine_type']
            ?? $machine['label']
            ?? 'Machine'
        ));
        $number = trim((string) (
            $machine['machine_no']
            ?? $machine['number']
            ?? $machine['no']
            ?? $machine['machine_code']
            ?? $machine['code']
            ?? ''
        ));

        if ($name !== '' && $number !== '') {
            return sprintf('%s #%s', $name, $number);
        }

        if ($name !== '') {
            return $name;
        }

        return $number !== '' ? 'Machine #' . $number : null;
    }
}
