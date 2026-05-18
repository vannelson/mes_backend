<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Contracts\WorkOrderServiceInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class WorkOrderMetadataRepairService
{
    public function __construct(
        protected WorkOrderServiceInterface $workOrderService
    ) {
    }

    public function examine(array $criteria): array
    {
        [$workOrder, $matches] = $this->resolveWorkOrderForExamine($criteria);

        if (! $workOrder) {
            return [
                'selection_required' => true,
                'matches' => $matches,
                'searched' => [
                    'work_order_id' => $criteria['work_order_id'] ?? null,
                    'work_order_no' => $criteria['work_order_no'] ?? null,
                ],
                'issues' => [],
                'changed_paths' => [],
                'can_apply' => false,
            ];
        }

        $sourcePayload = $this->buildSourcePayload($workOrder);
        $issues = [];
        $suggestedPayload = $this->buildSuggestedPayload($workOrder, $issues);
        $changedPaths = $this->diffPaths($sourcePayload, $suggestedPayload);

        return [
            'work_order' => [
                'id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'customer_part_number' => $workOrder->customer_part_number,
                'template_route_id' => $workOrder->template_route_id,
                'status' => $workOrder->status,
            ],
            'source_payload' => $sourcePayload,
            'suggested_payload' => $suggestedPayload,
            'issues' => $issues,
            'changed_paths' => $changedPaths,
            'can_apply' => true,
            'selection_required' => false,
            'matches' => [],
        ];
    }

    public function apply(int $workOrderId, array $payload, ?User $actor = null): array
    {
        $normalizedPayload = $this->normalizeApplyPayload($payload);
        $issues = [];

        $workOrder = WorkOrder::query()
            ->with(['userAssignments'])
            ->find($workOrderId);

        if (! $workOrder) {
            throw new ModelNotFoundException('Work order not found.');
        }

        $sourcePayload = $this->buildSourcePayload($workOrder);
        $suggestedPayload = $this->buildSuggestedPayload($workOrder, $issues);
        $finalPayload = $this->mergeSuggestedPayload($suggestedPayload, $normalizedPayload);
        $changedPaths = $this->diffPaths($sourcePayload, $finalPayload);

        if (empty($changedPaths)) {
            return [
                'work_order' => $this->workOrderService->detail($workOrderId),
                'issues' => $issues,
                'changed_paths' => [],
                'applied' => false,
            ];
        }

        $updateData = $finalPayload + [
            'notification_context' => 'metadata_repair',
            'notification_meta' => [
                'repair' => true,
                'changed_paths' => $changedPaths,
                'issues' => array_map(
                    static fn (array $issue) => Arr::only($issue, ['code', 'message', 'path']),
                    $issues
                ),
            ],
        ];

        $this->workOrderService->update($workOrderId, $updateData, [], $actor);

        $refreshed = WorkOrder::query()->findOrFail($workOrderId);

        return [
            'work_order' => $this->workOrderService->detail($workOrderId),
            'issues' => $issues,
            'changed_paths' => $changedPaths,
            'applied' => true,
            'completed_at' => $refreshed->completed_at?->toIso8601String(),
        ];
    }

    protected function buildSourcePayload(WorkOrder $workOrder): array
    {
        return [
            'status' => (string) ($workOrder->status ?? ''),
            'production_date_completed' => $workOrder->production_date_completed?->format('Y-m-d'),
            'production_qty_completed' => $workOrder->production_qty_completed,
            'is_released' => (bool) ($workOrder->is_released ?? false),
            'completed_at' => $workOrder->completed_at?->toIso8601String(),
            'metadata' => $this->normalizeMetadata($workOrder->metadata),
        ];
    }

    protected function resolveWorkOrderForExamine(array $criteria): array
    {
        $workOrderId = isset($criteria['work_order_id']) ? (int) $criteria['work_order_id'] : null;
        if ($workOrderId) {
            $workOrder = WorkOrder::query()
                ->with(['userAssignments'])
                ->find($workOrderId);

            if (! $workOrder) {
                throw new ModelNotFoundException('Work order not found.');
            }

            return [$workOrder, []];
        }

        $workOrderNo = trim((string) ($criteria['work_order_no'] ?? ''));
        if ($workOrderNo === '') {
            throw new ModelNotFoundException('Work order not found.');
        }

        $matches = WorkOrder::query()
            ->select([
                'id',
                'work_order_no',
                'customer_part_number',
                'template_route_id',
                'status',
                'batch_number',
                'updated_at',
            ])
            ->where('work_order_no', $workOrderNo)
            ->orderByDesc('id')
            ->get();

        if ($matches->isEmpty()) {
            throw new ModelNotFoundException('Work order not found.');
        }

        if ($matches->count() > 1) {
            return [
                null,
                $matches->map(static function (WorkOrder $workOrder): array {
                    return [
                        'id' => $workOrder->id,
                        'work_order_no' => $workOrder->work_order_no,
                        'customer_part_number' => $workOrder->customer_part_number,
                        'template_route_id' => $workOrder->template_route_id,
                        'status' => $workOrder->status,
                        'batch_number' => $workOrder->batch_number,
                        'updated_at' => $workOrder->updated_at?->toIso8601String(),
                    ];
                })->values()->all(),
            ];
        }

        $workOrder = WorkOrder::query()
            ->with(['userAssignments'])
            ->find($matches->first()->id);

        if (! $workOrder) {
            throw new ModelNotFoundException('Work order not found.');
        }

        return [$workOrder, []];
    }

    protected function buildSuggestedPayload(WorkOrder $workOrder, array &$issues): array
    {
        $payload = $this->buildSourcePayload($workOrder);
        $metadata = $payload['metadata'];
        $routes = is_array($metadata['routes'] ?? null) ? array_values($metadata['routes']) : [];

        if (empty($routes)) {
            $issues[] = $this->issue('missing_routes', 'No route metadata was found.', 'metadata.routes');
            $payload['metadata'] = $metadata;
            return $payload;
        }

        foreach ($routes as $index => $route) {
            if (! is_array($route)) {
                continue;
            }

            $sequence = $index + 1;
            $originalRouteKey = trim((string) ($route['route_key'] ?? $route['metadata']['route_key'] ?? ''));
            $canonicalRouteKey = $this->canonicalRouteKey($route, $sequence);
            $canonicalRouteCode = $this->canonicalRouteCode($route, $canonicalRouteKey);

            if (($route['order_seq'] ?? null) !== $sequence) {
                $issues[] = $this->issue(
                    'route_order_seq_normalized',
                    sprintf('Normalized route order sequence for %s to %d.', $route['name'] ?? ('route ' . $sequence), $sequence),
                    'metadata.routes.' . $index . '.order_seq'
                );
            }

            if ($canonicalRouteKey !== $originalRouteKey) {
                $issues[] = $this->issue(
                    'route_key_normalized',
                    sprintf('Normalized route key for %s from %s to %s.', $route['name'] ?? ('route ' . $sequence), $originalRouteKey !== '' ? $originalRouteKey : '(empty)', $canonicalRouteKey),
                    'metadata.routes.' . $index . '.route_key'
                );
            }

            $route['order_seq'] = $sequence;
            $route['route_key'] = $canonicalRouteKey;
            $route['route'] = $canonicalRouteCode;

            $routeMetadata = is_array($route['metadata'] ?? null) ? $route['metadata'] : [];
            $routeMetadata['route_key'] = $canonicalRouteKey;
            $routeMetadata['order_seq'] = $sequence;
            $route['metadata'] = $routeMetadata;

            $rebuiltParams = $this->rebuildParamsFromParameters($route);
            if ($rebuiltParams !== null) {
                if (($route['params'] ?? null) !== $rebuiltParams) {
                    $issues[] = $this->issue(
                        'route_params_rebuilt',
                        sprintf('Rebuilt params from parameter definitions for %s.', $route['name'] ?? ('route ' . $sequence)),
                        'metadata.routes.' . $index . '.params'
                    );
                }
                $route['params'] = $rebuiltParams;
            }

            if ($this->isCompletedRoute($route)) {
                $route['status'] = 'completed';
            }

            $completionLog = $this->latestValidationLogForRoute($workOrder->id, $route);
            if (! $this->isCompletedRoute($route) && $completionLog) {
                $route['status'] = 'completed';
                $route['completed_at'] = $completionLog->created_at?->toIso8601String();
                $issues[] = $this->issue(
                    'route_completed_from_audit',
                    sprintf('Marked %s as completed from validation audit trail.', $route['name'] ?? ('route ' . $sequence)),
                    'metadata.routes.' . $index . '.status'
                );
            }

            $routes[$index] = $route;
        }

        $metadata['routes'] = $routes;
        $metadata['assignments']['routes'] = $this->buildAssignmentsFromRoutes($routes, $metadata, $issues);
        $metadata['state']['assignees'] = $this->extractAssigneeIds($metadata['assignments']['routes']);

        $firstIncompleteIndex = $this->firstIncompleteRouteIndex($routes);
        $allRoutesCompleted = $firstIncompleteIndex === null;
        $nextStepIndex = $allRoutesCompleted ? max(0, count($routes) - 1) : $firstIncompleteIndex;
        $state = is_array($metadata['state'] ?? null) ? $metadata['state'] : [];

        $targetStatus = $allRoutesCompleted ? 'Completed' : 'In Progress';
        if (($state['status'] ?? null) !== $targetStatus) {
            $issues[] = $this->issue(
                'state_status_normalized',
                sprintf('Normalized workflow state status to %s.', $targetStatus),
                'metadata.state.status'
            );
        }
        $state['status'] = $targetStatus;

        if (($state['currentStep'] ?? null) !== $nextStepIndex) {
            $issues[] = $this->issue(
                'state_current_step_normalized',
                sprintf('Normalized current step to %d.', $nextStepIndex),
                'metadata.state.currentStep'
            );
        }
        $state['currentStep'] = $nextStepIndex;
        $metadata['state'] = $state;

        if ($allRoutesCompleted) {
            $lastCompletedAt = $this->latestRouteCompletionTimestamp($routes);
            $payload['status'] = 'Completed';
            $payload['production_date_completed'] = $lastCompletedAt?->format('Y-m-d');
            $payload['production_qty_completed'] = $workOrder->quantity_to_produce ?: $workOrder->production_qty_completed;
            $payload['completed_at'] = $lastCompletedAt?->toIso8601String();
        } else {
            if (! empty($payload['production_date_completed']) || ! empty($payload['production_qty_completed']) || ! empty($payload['completed_at']) || strtolower(trim((string) $payload['status'])) === 'completed') {
                $issues[] = $this->issue(
                    'top_level_completion_cleared',
                    'Cleared top-level completion fields because downstream routes are still pending.',
                    'status'
                );
            }

            $payload['status'] = 'In Progress';
            $payload['production_date_completed'] = null;
            $payload['production_qty_completed'] = null;
            $payload['completed_at'] = null;
        }

        $payload['is_released'] = true;
        $payload['metadata'] = $metadata;

        return $payload;
    }

    protected function normalizeApplyPayload(array $payload): array
    {
        $metadata = $this->normalizeMetadata($payload['metadata'] ?? null);

        return [
            'status' => isset($payload['status']) ? (string) $payload['status'] : null,
            'production_date_completed' => array_key_exists('production_date_completed', $payload)
                ? $payload['production_date_completed']
                : null,
            'production_qty_completed' => array_key_exists('production_qty_completed', $payload)
                ? $payload['production_qty_completed']
                : null,
            'is_released' => array_key_exists('is_released', $payload)
                ? (bool) $payload['is_released']
                : true,
            'completed_at' => $payload['completed_at'] ?? null,
            'metadata' => $metadata,
        ];
    }

    protected function mergeSuggestedPayload(array $suggestedPayload, array $overridePayload): array
    {
        $merged = $suggestedPayload;

        foreach (['status', 'production_date_completed', 'production_qty_completed', 'is_released', 'completed_at'] as $field) {
            if (array_key_exists($field, $overridePayload)) {
                $merged[$field] = $overridePayload[$field];
            }
        }

        if (! empty($overridePayload['metadata'])) {
            $merged['metadata'] = $overridePayload['metadata'];
        }

        return $merged;
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

    protected function canonicalRouteKey(array $route, int $sequence): string
    {
        $nameToken = $this->normalizeToken($route['name'] ?? null);
        $routeToken = $this->normalizeToken($route['route'] ?? null);
        $keyToken = $this->normalizeToken($route['route_key'] ?? $route['metadata']['route_key'] ?? null);

        if (in_array('aoi', [$nameToken, $routeToken, $keyToken], true)) {
            return 'aoi';
        }

        $raw = trim((string) ($route['route_key'] ?? $route['metadata']['route_key'] ?? $route['name'] ?? $route['route'] ?? ''));
        if ($raw !== '') {
            return $raw;
        }

        return 'route_' . $sequence;
    }

    protected function canonicalRouteCode(array $route, string $canonicalRouteKey): string
    {
        if ($canonicalRouteKey === 'aoi') {
            return 'aoi';
        }

        $raw = trim((string) ($route['route'] ?? $route['name'] ?? $canonicalRouteKey));
        return $raw !== '' ? $raw : $canonicalRouteKey;
    }

    protected function normalizeToken(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        return preg_replace('/\s+/', ' ', $normalized) ?: $normalized;
    }

    protected function rebuildParamsFromParameters(array $route): ?array
    {
        $parameters = $route['parameters'] ?? null;
        if (! is_array($parameters) || $parameters === []) {
            return null;
        }

        $params = [];
        foreach ($parameters as $parameter) {
            if (! is_array($parameter)) {
                continue;
            }

            $name = trim((string) ($parameter['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            if (array_key_exists('current_value', $parameter)) {
                $params[$name] = $parameter['current_value'];
                continue;
            }

            $params[$name] = $parameter['default_value'] ?? null;
        }

        return $params === [] ? null : $params;
    }

    protected function isCompletedRoute(array $route): bool
    {
        $status = strtolower(trim((string) ($route['status'] ?? '')));
        if (in_array($status, ['completed', 'complete', 'done'], true)) {
            return true;
        }

        return ! empty($route['completed_at']) || ! empty($route['completedAt']);
    }

    protected function latestValidationLogForRoute(int $workOrderId, array $route): ?AuditLog
    {
        $logs = AuditLog::query()
            ->where('work_order_id', $workOrderId)
            ->whereIn('action', ['validation', 'validation_update', 'validation_signoff'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $routeName = $this->normalizeToken($route['name'] ?? null);
        $routeKey = $this->normalizeToken($route['route_key'] ?? null);
        $routeCode = $this->normalizeToken($route['route'] ?? null);

        foreach ($logs as $log) {
            $details = is_array($log->details) ? $log->details : [];
            $meta = is_array($details['meta'] ?? null) ? $details['meta'] : [];
            $stepName = $this->normalizeToken($meta['step_name'] ?? $details['step_name'] ?? null);
            $stepKey = $this->normalizeToken($meta['step_key'] ?? $details['step_key'] ?? null);
            $logRouteKey = $this->normalizeToken($log->route_key);

            if ($routeName && $stepName && $routeName === $stepName) {
                return $log;
            }

            if ($routeKey && $stepKey && $routeKey === $stepKey) {
                return $log;
            }

            if ($routeKey && $logRouteKey && $routeKey === $logRouteKey) {
                return $log;
            }

            if ($routeCode && $stepKey && $routeCode === $stepKey) {
                return $log;
            }
        }

        return null;
    }

    protected function buildAssignmentsFromRoutes(array $routes, array $metadata, array &$issues): array
    {
        $sourceAssignments = is_array(data_get($metadata, 'assignments.routes'))
            ? data_get($metadata, 'assignments.routes')
            : [];

        $normalizedAssignments = [];
        foreach ($routes as $routeIndex => $route) {
            if (! is_array($route)) {
                continue;
            }

            $operators = [];
            foreach ($sourceAssignments as $assignment) {
                if (! is_array($assignment) || ! $this->assignmentMatchesRoute($assignment, $route)) {
                    continue;
                }

                foreach (($assignment['operators'] ?? []) as $operator) {
                    if (! is_array($operator)) {
                        continue;
                    }

                    $id = $operator['id'] ?? $operator['user_id'] ?? $operator['userId'] ?? null;
                    if ($id === null || $id === '') {
                        continue;
                    }

                    $operators[(string) $id] = [
                        'id' => (string) $id,
                        'qty' => $operator['qty'] ?? null,
                    ];
                }
            }

            $directOperatorId = $route['operator_id']
                ?? $route['operatorId']
                ?? $route['user_id']
                ?? data_get($route, 'metadata.machineOperatorId')
                ?? null;
            if ($directOperatorId !== null && $directOperatorId !== '') {
                $operators[(string) $directOperatorId] = [
                    'id' => (string) $directOperatorId,
                    'qty' => $operators[(string) $directOperatorId]['qty'] ?? null,
                ];
            }

            foreach ((array) data_get($route, 'metadata.timeTracker.entries', []) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $timeOperatorId = $entry['operator_id'] ?? $entry['operatorId'] ?? $entry['user_id'] ?? null;
                if ($timeOperatorId === null || $timeOperatorId === '') {
                    continue;
                }

                $operators[(string) $timeOperatorId] = [
                    'id' => (string) $timeOperatorId,
                    'qty' => $operators[(string) $timeOperatorId]['qty'] ?? null,
                ];
            }

            $normalizedAssignments[] = [
                'route_key' => $route['route_key'] ?? ('route_' . ($routeIndex + 1)),
                'order_seq' => $route['order_seq'] ?? ($routeIndex + 1),
                'route' => $route['route'] ?? ($route['route_key'] ?? ('route_' . ($routeIndex + 1))),
                'name' => $route['name'] ?? ($route['route'] ?? ('Route ' . ($routeIndex + 1))),
                'operators' => array_values($operators),
            ];
        }

        if (count($sourceAssignments) !== count($normalizedAssignments)) {
            $issues[] = $this->issue(
                'assignment_rows_deduplicated',
                sprintf('Normalized assignment rows from %d to %d.', count($sourceAssignments), count($normalizedAssignments)),
                'metadata.assignments.routes'
            );
        }

        return $normalizedAssignments;
    }

    protected function assignmentMatchesRoute(array $assignment, array $route): bool
    {
        $routeKey = $this->normalizeToken($route['route_key'] ?? null);
        $routeCode = $this->normalizeToken($route['route'] ?? null);
        $routeName = $this->normalizeToken($route['name'] ?? null);
        $routeOrder = (string) ($route['order_seq'] ?? '');

        $assignmentKey = $this->normalizeToken($assignment['route_key'] ?? $assignment['routeKey'] ?? null);
        $assignmentCode = $this->normalizeToken($assignment['route'] ?? $assignment['key'] ?? null);
        $assignmentName = $this->normalizeToken($assignment['name'] ?? null);
        $assignmentOrder = (string) ($assignment['order_seq'] ?? $assignment['orderSeq'] ?? '');

        if ($routeKey && $assignmentKey && $routeKey === $assignmentKey) {
            return true;
        }

        if ($routeCode && $assignmentCode && $routeCode === $assignmentCode && $routeOrder !== '' && $routeOrder === $assignmentOrder) {
            return true;
        }

        if ($routeName && $assignmentName && $routeName === $assignmentName) {
            return true;
        }

        return $routeOrder !== '' && $routeOrder === $assignmentOrder;
    }

    protected function extractAssigneeIds(array $assignments): array
    {
        $ids = [];
        foreach ($assignments as $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            foreach (($assignment['operators'] ?? []) as $operator) {
                if (! is_array($operator)) {
                    continue;
                }

                $id = $operator['id'] ?? $operator['user_id'] ?? $operator['userId'] ?? null;
                if ($id === null || $id === '') {
                    continue;
                }

                $ids[(string) $id] = (string) $id;
            }
        }

        return array_values($ids);
    }

    protected function firstIncompleteRouteIndex(array $routes): ?int
    {
        foreach ($routes as $index => $route) {
            if (! is_array($route)) {
                continue;
            }

            if (! $this->isCompletedRoute($route)) {
                return $index;
            }
        }

        return null;
    }

    protected function latestRouteCompletionTimestamp(array $routes): ?Carbon
    {
        $latest = null;
        foreach ($routes as $route) {
            if (! is_array($route)) {
                continue;
            }

            $raw = $route['completed_at'] ?? $route['completedAt'] ?? null;
            if (! $raw) {
                continue;
            }

            try {
                $parsed = Carbon::parse($raw);
            } catch (\Throwable) {
                continue;
            }

            if ($latest === null || $parsed->gt($latest)) {
                $latest = $parsed;
            }
        }

        return $latest;
    }

    protected function diffPaths(mixed $before, mixed $after, string $prefix = ''): array
    {
        if (is_array($before) && is_array($after)) {
            $paths = [];
            $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
            foreach ($keys as $key) {
                $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
                $beforeHas = array_key_exists($key, $before);
                $afterHas = array_key_exists($key, $after);

                if (! $beforeHas || ! $afterHas) {
                    $paths[] = $path;
                    continue;
                }

                $paths = array_merge($paths, $this->diffPaths($before[$key], $after[$key], $path));
            }

            return array_values(array_unique($paths));
        }

        if ($before !== $after) {
            return [$prefix === '' ? '$' : $prefix];
        }

        return [];
    }

    protected function issue(string $code, string $message, string $path, string $severity = 'warning'): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
            'path' => $path,
        ];
    }
}
