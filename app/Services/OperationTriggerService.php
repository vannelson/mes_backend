<?php

namespace App\Services;

use App\Models\OperationTrigger;
use App\Models\PlaylistItem;
use App\Models\User;
use App\Models\WorkOrderNotification;
use App\Models\WorkOrder;
use App\Services\Contracts\OperationTriggerServiceInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OperationTriggerService implements OperationTriggerServiceInterface
{
    protected array $fieldMap = [
        'status' => 'status',
        'priority' => 'priority',
        'assignee' => 'metadata.state.assignees',
        'team' => 'metadata.state.team',
        'sla_timer' => 'metadata.sla.minutes',
        'sla_breach' => 'metadata.sla.breached',
        'validation_result' => 'metadata.validation.result',
        'checklist_packing' => 'metadata.checklists.packing.completed',
        'checklist_quality' => 'metadata.checklists.quality.completed',
        'progress_pct' => 'metadata.state.progressPct',
        'parameter_temp' => 'metadata.parameters.temperature',
        'updated_at' => 'updated_at',
        'custom_field' => 'metadata.custom',
    ];

    public function __construct(
        protected FirebaseRealtimeService $firebaseRealtimeService,
        protected MessageService $messageService,
        protected WorkOrderNotificationService $notificationService
    ) {
    }

    public function getList(array $filters = [], array $order = [], int $limit = 20, int $page = 1): LengthAwarePaginator
    {
        $query = OperationTrigger::query();

        $status = Arr::get($filters, 'status');
        if ($status) {
            $query->where('status', $status);
        }

        $search = Arr::get($filters, 'q');
        if ($search) {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        [$column, $direction] = $this->normalizeOrder($order);

        return $query
            ->orderBy($column, $direction)
            ->paginate($limit, ['*'], 'page', $page);
    }

    public function detail(int $id): array
    {
        $trigger = OperationTrigger::query()->findOrFail($id);

        return $trigger->toArray();
    }

    public function create(array $data, ?int $actorId = null): array
    {
        $payload = $this->normalizePayload($data);
        $payload['created_by'] = $actorId;
        $payload['updated_by'] = $actorId;

        $trigger = OperationTrigger::query()->create($payload);

        $this->appendVersion($trigger, $actorId, 'Initial draft');
        $this->appendAudit($trigger, $actorId, 'Created trigger');
        $trigger->save();

        $this->syncTriggerDefinition($trigger);
        $this->publishRealtimeUpdate($trigger, 'created');

        return $trigger->toArray();
    }

    public function update(int $id, array $data, ?int $actorId = null): array
    {
        $trigger = OperationTrigger::query()->findOrFail($id);
        $payload = $this->normalizePayload($data, $trigger);
        $payload['updated_by'] = $actorId;

        $trigger->fill($payload);
        $this->appendAudit($trigger, $actorId, 'Updated trigger');
        $trigger->save();

        $this->syncTriggerDefinition($trigger);
        $this->publishRealtimeUpdate($trigger, 'updated');

        return $trigger->toArray();
    }

    public function publish(int $id, ?int $actorId = null): array
    {
        $trigger = OperationTrigger::query()->findOrFail($id);
        $trigger->status = 'published';
        $trigger->is_active = true;
        $trigger->version = max(1, (int) $trigger->version) + 1;
        $trigger->updated_by = $actorId;

        $this->appendVersion($trigger, $actorId, 'Published');
        $this->appendAudit($trigger, $actorId, 'Published trigger');
        $trigger->save();

        $this->syncTriggerDefinition($trigger);
        $this->publishRealtimeUpdate($trigger, 'published');

        return $trigger->toArray();
    }

    public function disable(int $id, ?int $actorId = null): array
    {
        $trigger = OperationTrigger::query()->findOrFail($id);
        $trigger->status = 'disabled';
        $trigger->is_active = false;
        $trigger->updated_by = $actorId;

        $this->appendAudit($trigger, $actorId, 'Disabled trigger');
        $trigger->save();

        $this->syncTriggerDefinition($trigger);
        $this->publishRealtimeUpdate($trigger, 'disabled');

        return $trigger->toArray();
    }

    public function simulate(int $id, array $payload = []): array
    {
        $trigger = OperationTrigger::query()->findOrFail($id);
        $workOrderId = Arr::get($payload, 'work_order_id');
        $workOrderNo = Arr::get($payload, 'work_order_no');

        $workOrder = WorkOrder::query()
            ->when($workOrderId, fn($query) => $query->where('id', $workOrderId))
            ->when($workOrderNo, fn($query) => $query->where('work_order_no', $workOrderNo))
            ->first();

        if (! $workOrder) {
            throw ValidationException::withMessages([
                'work_order' => 'Work order not found for simulation.',
            ]);
        }

        $context = [
            'work_order' => $this->hydrateWorkOrderContext($workOrder->toArray()),
            'changes' => Arr::get($payload, 'changes', []),
        ];

        $rule = is_array($trigger->rule) ? $trigger->rule : [];
        $evaluation = $this->evaluateGroup($rule, $context);

        return [
            'trigger_id' => $trigger->id,
            'matched' => $evaluation['matched'] ?? false,
            'summary' => $evaluation,
            'work_order' => [
                'id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'status' => $workOrder->status,
                'priority' => $workOrder->priority,
                'updated_at' => $workOrder->updated_at?->toIso8601String(),
            ],
        ];
    }

    public function execute(int $id, array $payload = [], ?int $actorId = null): array
    {
        $trigger = OperationTrigger::query()->findOrFail($id);
        if ($trigger->status !== 'published' || ! $trigger->is_active) {
            return [
                'trigger_id' => $trigger->id,
                'status' => 'skipped',
                'reason' => 'Trigger is not active.',
            ];
        }

        $workOrderId = Arr::get($payload, 'work_order_id');
        $workOrderNo = Arr::get($payload, 'work_order_no');
        $executionId = Arr::get($payload, 'execution_id');

        $workOrder = WorkOrder::query()
            ->when($workOrderId, fn($query) => $query->where('id', $workOrderId))
            ->when($workOrderNo, fn($query) => $query->where('work_order_no', $workOrderNo))
            ->first();

        if (! $workOrder) {
            throw ValidationException::withMessages([
                'work_order' => 'Work order not found for trigger execution.',
            ]);
        }

        $contextWorkOrder = $this->hydrateWorkOrderContext($workOrder->toArray());
        $context = [
            'work_order' => $contextWorkOrder,
            'changes' => Arr::get($payload, 'changes', []),
        ];

        $rule = is_array($trigger->rule) ? $trigger->rule : [];
        $evaluation = $this->evaluateGroup($rule, $context);

        if (! ($evaluation['matched'] ?? false)) {
            $this->appendExecution($trigger, [
                'id' => $executionId ?? Str::uuid()->toString(),
                'work_order_id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'status' => 'skipped',
                'at' => now()->toIso8601String(),
                'summary' => $evaluation,
            ]);

            return [
                'trigger_id' => $trigger->id,
                'status' => 'skipped',
                'matched' => false,
                'summary' => $evaluation,
            ];
        }

        $variables = $this->buildTemplateVariables($contextWorkOrder, $payload);
        $actions = is_array($trigger->actions) ? $trigger->actions : [];
        $actionResults = [];
        $startedAt = microtime(true);

        foreach ($actions as $action) {
            if (! Arr::get($action, 'enabled', true)) {
                $actionResults[] = [
                    'type' => $action['type'] ?? 'unknown',
                    'status' => 'skipped',
                    'reason' => 'Action disabled.',
                ];
                continue;
            }

            $type = $action['type'] ?? 'unknown';
            $result = match ($type) {
                'in_app' => $this->executeInAppAction($action, $workOrder, $variables, $actorId),
                'push' => $this->executeNotificationAction($action, $workOrder, $variables, $actorId),
                'virtual_screen' => $this->executeVirtualScreenAction($action, $workOrder, $variables),
                'webhook' => $this->executeWebhookAction($action, $variables),
                default => [
                    'type' => $type,
                    'status' => 'skipped',
                    'reason' => 'Unsupported action type.',
                ],
            };

            $actionResults[] = $result;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $hasFailure = collect($actionResults)->contains(
            static fn($result): bool => ($result['status'] ?? '') === 'failed'
        );

        $status = $hasFailure ? 'failed' : 'success';

        $this->appendExecution($trigger, [
            'id' => $executionId ?? Str::uuid()->toString(),
            'work_order_id' => $workOrder->id,
            'work_order_no' => $workOrder->work_order_no,
            'status' => $status,
            'duration_ms' => $durationMs,
            'at' => now()->toIso8601String(),
            'summary' => $evaluation,
            'actions' => $actionResults,
        ]);

        $this->firebaseRealtimeService->publishTriggerExecution([
            'trigger_id' => $trigger->id,
            'work_order_id' => $workOrder->id,
            'status' => $status,
            'duration_ms' => $durationMs,
        ]);

        return [
            'trigger_id' => $trigger->id,
            'status' => $status,
            'matched' => true,
            'actions' => $actionResults,
            'duration_ms' => $durationMs,
        ];
    }

    protected function normalizePayload(array $data, ?OperationTrigger $trigger = null): array
    {
        return [
            'tenant_id' => Arr::get($data, 'tenant_id', $trigger?->tenant_id),
            'name' => Arr::get($data, 'name', $trigger?->name),
            'description' => Arr::get($data, 'description', $trigger?->description),
            'status' => Arr::get($data, 'status', $trigger?->status ?? 'draft'),
            'tags' => Arr::get($data, 'tags', $trigger?->tags ?? []),
            'rule' => Arr::get($data, 'rule', $trigger?->rule ?? []),
            'schedule' => Arr::get($data, 'schedule', $trigger?->schedule ?? []),
            'actions' => Arr::get($data, 'actions', $trigger?->actions ?? []),
            'cooldown' => Arr::get($data, 'cooldown', $trigger?->cooldown ?? []),
            'debounce' => Arr::get($data, 'debounce', $trigger?->debounce ?? []),
            'version' => Arr::get($data, 'version', $trigger?->version ?? 1),
            'last_fired_at' => Arr::get($data, 'last_fired_at', $trigger?->last_fired_at),
            'is_active' => Arr::get($data, 'is_active', $trigger?->is_active ?? true),
        ];
    }

    protected function appendVersion(OperationTrigger $trigger, ?int $actorId, string $note): void
    {
        $versions = is_array($trigger->versions) ? $trigger->versions : [];
        $versions[] = [
            'id' => Str::uuid()->toString(),
            'label' => 'v' . $trigger->version,
            'note' => $note,
            'actor_id' => $actorId,
            'at' => now()->toIso8601String(),
        ];
        $trigger->versions = $versions;
    }

    protected function appendAudit(OperationTrigger $trigger, ?int $actorId, string $action): void
    {
        $audit = is_array($trigger->audit) ? $trigger->audit : [];
        $audit[] = [
            'id' => Str::uuid()->toString(),
            'actor_id' => $actorId,
            'action' => $action,
            'at' => now()->toIso8601String(),
        ];
        $trigger->audit = $audit;
    }

    protected function publishRealtimeUpdate(OperationTrigger $trigger, string $action): void
    {
        try {
            $this->firebaseRealtimeService->publishTriggerUpdate([
                'trigger_id' => $trigger->id,
                'status' => $trigger->status,
                'action' => $action,
            ]);
        } catch (\Throwable) {
            // Ignore realtime failures for trigger updates.
        }
    }

    protected function appendExecution(OperationTrigger $trigger, array $execution): void
    {
        $executions = is_array($trigger->executions) ? $trigger->executions : [];
        array_unshift($executions, $execution);
        $trigger->executions = array_slice($executions, 0, 20);
        if (($execution['status'] ?? null) === 'success') {
            $trigger->last_fired_at = now();
        }
        $trigger->save();
    }

    protected function buildTemplateVariables(array $workOrder, array $payload = []): array
    {
        $metadata = Arr::get($workOrder, 'metadata', []);
        $state = is_array($metadata['state'] ?? null) ? $metadata['state'] : [];
        $assignees = $this->resolveAssigneeIds($metadata);
        $progressPct = Arr::get($state, 'progressPct', null);
        if ($progressPct === null) {
            $progressPct = $this->computeProgressPctFromMetadata($metadata);
        }

        return [
            'work_order_id' => $workOrder['id'] ?? null,
            'work_order_no' => $workOrder['work_order_no'] ?? null,
            'status' => $workOrder['status'] ?? null,
            'priority' => $workOrder['priority'] ?? null,
            'assignee' => implode(', ', $assignees),
            'assignees' => implode(', ', $assignees),
            'team' => Arr::get($state, 'team', ''),
            'progress_pct' => $progressPct,
            'sla_timer' => Arr::get($metadata, 'sla.minutes'),
            'event_id' => Arr::get($payload, 'event_id'),
        ];
    }

    protected function executeInAppAction(
        array $action,
        WorkOrder $workOrder,
        array $variables,
        ?int $actorId = null
    ): array {
        $recipientIds = $this->resolveRecipientIds($action, $workOrder);
        if (empty($recipientIds)) {
            return [
                'type' => 'in_app',
                'status' => 'skipped',
                'reason' => 'No recipients resolved.',
            ];
        }

        $sender = $this->resolveSystemSender($actorId);
        if (! $sender) {
            return [
                'type' => 'in_app',
                'status' => 'failed',
                'reason' => 'System sender not configured.',
            ];
        }

        $body = $this->renderTemplate(Arr::get($action, 'template', ''), $variables);
        $recipients = User::query()->whereIn('id', $recipientIds)->get()->keyBy('id');
        $sent = 0;

        foreach ($recipientIds as $recipientId) {
            $recipient = $recipients->get((int) $recipientId);
            if (! $recipient) {
                continue;
            }
            $this->messageService->send($sender, $recipient, $body);
            $sent++;
        }

        return [
            'type' => 'in_app',
            'status' => 'success',
            'recipients' => $sent,
        ];
    }

    protected function executeNotificationAction(
        array $action,
        WorkOrder $workOrder,
        array $variables,
        ?int $actorId = null
    ): array {
        $recipientIds = $this->resolveRecipientIds($action, $workOrder);
        if (empty($recipientIds)) {
            return [
                'type' => 'push',
                'status' => 'skipped',
                'reason' => 'No recipients resolved.',
            ];
        }

        $title = Arr::get($action, 'label', 'Work order update');
        $message = $this->renderTemplate(Arr::get($action, 'template', ''), $variables);
        $actionUrl = "/operations/work-orders/{$workOrder->id}";

        foreach ($recipientIds as $recipientId) {
            WorkOrderNotification::query()->create([
                'recipient_id' => $recipientId,
                'actor_id' => $actorId,
                'work_order_id' => $workOrder->id,
                'type' => 'work_order_trigger',
                'title' => $title,
                'message' => $message,
                'action_url' => $actionUrl,
                'data' => [
                    'trigger_label' => $title,
                ],
            ]);
        }

        $this->firebaseRealtimeService->publishNotificationUpdate([
            'work_order_id' => $workOrder->id,
            'work_order_no' => $workOrder->work_order_no,
            'type' => 'work_order_trigger',
            'context' => 'trigger',
            'actor_id' => $actorId,
        ]);

        return [
            'type' => 'push',
            'status' => 'success',
            'recipients' => count($recipientIds),
        ];
    }

    protected function executeVirtualScreenAction(
        array $action,
        WorkOrder $workOrder,
        array $variables
    ): array {
        $screenId = Arr::get($action, 'recipients.screenId');
        if (! $screenId) {
            return [
                'type' => 'virtual_screen',
                'status' => 'skipped',
                'reason' => 'Virtual screen not selected.',
            ];
        }

        $message = $this->renderTemplate(Arr::get($action, 'template', ''), $variables);
        $nextOrder = (int) (PlaylistItem::query()
            ->where('virtual_screen_id', $screenId)
            ->max('order') ?? -1) + 1;

        PlaylistItem::query()->create([
            'virtual_screen_id' => $screenId,
            'type' => 'widget',
            'content' => [
                'widget_type' => 'ticker',
                'text' => $message,
            ],
            'duration' => Arr::get($action, 'duration', 10),
            'order' => $nextOrder,
            'is_active' => true,
        ]);

        return [
            'type' => 'virtual_screen',
            'status' => 'success',
            'screen_id' => $screenId,
        ];
    }

    protected function executeWebhookAction(array $action, array $variables): array
    {
        $webhook = Arr::get($action, 'webhook', []);
        $url = Arr::get($webhook, 'url');
        if (! $url) {
            return [
                'type' => 'webhook',
                'status' => 'skipped',
                'reason' => 'Webhook URL not configured.',
            ];
        }

        $method = strtoupper(Arr::get($webhook, 'method', 'POST'));
        $headers = $this->normalizeWebhookHeaders(Arr::get($webhook, 'headers', []));
        $payload = $this->renderTemplate(Arr::get($action, 'template', ''), $variables);
        $json = json_decode($payload, true);

        try {
            $request = Http::withHeaders($headers);
            $response = $json !== null && json_last_error() === JSON_ERROR_NONE
                ? $request->send($method, $url, ['json' => $json])
                : $request->send($method, $url, ['body' => $payload]);
        } catch (\Throwable $e) {
            return [
                'type' => 'webhook',
                'status' => 'failed',
                'reason' => $e->getMessage(),
            ];
        }

        if (! $response->successful()) {
            return [
                'type' => 'webhook',
                'status' => 'failed',
                'reason' => $response->body(),
            ];
        }

        return [
            'type' => 'webhook',
            'status' => 'success',
            'status_code' => $response->status(),
        ];
    }

    protected function resolveRecipientIds(array $action, WorkOrder $workOrder): array
    {
        $mode = Arr::get($action, 'recipients.mode', 'assignee');
        $metadata = $this->hydrateWorkOrderContext($workOrder->toArray())['metadata'] ?? [];

        return match ($mode) {
            'all' => User::query()->pluck('id')->all(),
            'team' => $this->resolveTeamRecipients(Arr::get($action, 'recipients.team')),
            'users' => array_values(array_filter(
                array_map('intval', Arr::get($action, 'recipients.users', []))
            )),
            default => $this->resolveAssigneeIds($metadata),
        };
    }

    protected function resolveAssigneeIds(array $metadata): array
    {
        $assignees = Arr::get($metadata, 'state.assignees', []);
        if (!is_array($assignees)) {
            $assignees = array_filter(array_map('trim', explode(',', (string) $assignees)));
        }

        if (!empty($assignees)) {
            return array_values(array_unique(array_map('intval', $assignees)));
        }

        $operators = [];
        $routes = Arr::get($metadata, 'assignments.routes', []);
        foreach ($routes as $route) {
            foreach (Arr::get($route, 'operators', []) as $operator) {
                $id = $operator['id'] ?? null;
                if ($id !== null) {
                    $operators[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($operators));
    }

    protected function resolveTeamRecipients(?string $team): array
    {
        $team = trim((string) $team);
        if ($team === '') {
            return [];
        }

        return User::query()
            ->where('department', $team)
            ->orWhere('position', $team)
            ->orWhere('user_type', $team)
            ->pluck('id')
            ->all();
    }

    protected function renderTemplate(string $template, array $variables): string
    {
        $rendered = $template;
        foreach ($variables as $key => $value) {
            $replacement = is_array($value) ? implode(', ', $value) : (string) ($value ?? '');
            $pattern = '/\\{\\{\\s*' . preg_quote((string) $key, '/') . '\\s*\\}\\}/i';
            $rendered = preg_replace($pattern, $replacement, $rendered) ?? $rendered;
        }

        return $rendered;
    }

    protected function normalizeWebhookHeaders(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function resolveSystemSender(?int $actorId = null): ?User
    {
        if ($actorId) {
            $actor = User::query()->find($actorId);
            if ($actor) {
                return $actor;
            }
        }

        return User::query()
            ->whereIn('role', ['superadmin', 'admin'])
            ->orderBy('id')
            ->first()
            ?? User::query()->orderBy('id')->first();
    }

    protected function syncTriggerDefinition(OperationTrigger $trigger): void
    {
        try {
            $this->firebaseRealtimeService->publishTriggerDefinition([
                'id' => $trigger->id,
                'tenant_id' => $trigger->tenant_id ?? 'default',
                'name' => $trigger->name,
                'description' => $trigger->description,
                'status' => $trigger->status,
                'is_active' => (bool) $trigger->is_active,
                'rule' => $trigger->rule ?? [],
                'schedule' => $trigger->schedule ?? [],
                'actions' => $trigger->actions ?? [],
                'cooldown' => $trigger->cooldown ?? [],
                'debounce' => $trigger->debounce ?? [],
                'version' => $trigger->version ?? 1,
                'updated_at' => $trigger->updated_at?->toIso8601String(),
            ]);
        } catch (\Throwable) {
            // Ignore realtime failures for definition sync.
        }
    }

    protected function normalizeOrder(array $order): array
    {
        $column = $order[0] ?? 'updated_at';
        $direction = strtolower($order[1] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return [$column, $direction];
    }

    protected function evaluateGroup(array $group, array $context): array
    {
        $gate = strtolower((string) Arr::get($group, 'gate', 'all'));
        $conditions = Arr::get($group, 'conditions', []);
        $groups = Arr::get($group, 'groups', []);

        $conditionResults = [];
        foreach ($conditions as $condition) {
            $conditionResults[] = $this->evaluateCondition($condition, $context);
        }

        $groupResults = [];
        foreach ($groups as $child) {
            $groupResults[] = $this->evaluateGroup($child, $context);
        }

        $allResults = array_merge(
            array_column($conditionResults, 'matched'),
            array_column($groupResults, 'matched')
        );

        $matched = $gate === 'any'
            ? in_array(true, $allResults, true)
            : (!empty($allResults) && !in_array(false, $allResults, true));

        return [
            'gate' => $gate,
            'matched' => $matched,
            'conditions' => $conditionResults,
            'groups' => $groupResults,
        ];
    }

    protected function evaluateCondition(array $condition, array $context): array
    {
        $fieldKey = Arr::get($condition, 'field');
        $path = Arr::get($condition, 'path', $this->fieldMap[$fieldKey] ?? $fieldKey);
        $operator = Arr::get($condition, 'operator', 'eq');
        $expected = Arr::get($condition, 'value');
        $expectedTo = Arr::get($condition, 'valueTo');
        $changes = Arr::get($context, 'changes', []);
        $workOrder = $context['work_order'] ?? [];
        $pathExists = $path ? Arr::has($workOrder, $path) : false;
        $current = $path ? data_get($workOrder, $path) : null;

        $matched = false;
        $reason = null;

        if (! $pathExists && $fieldKey === 'progress_pct') {
            $computed = $this->computeProgressPctFromMetadata(
                Arr::get($workOrder, 'metadata', [])
            );
            if ($computed !== null) {
                $current = $computed;
                $pathExists = true;
            }
        }

        $before = null;
        $after = null;
        if (in_array($operator, ['changed', 'changed_to', 'changed_from'], true)) {
            $changeKey = $fieldKey ?? $path;
            $change = $changes[$changeKey] ?? null;
            if (!is_array($change)) {
                $reason = 'Change context missing';
            } else {
                $before = $change['before'] ?? null;
                $after = $change['after'] ?? null;
                if ($operator === 'changed') {
                    $matched = true;
                } elseif ($operator === 'changed_to') {
                    $matched = $this->compare($after, $expected);
                } else {
                    $matched = $this->compare($before, $expected);
                }
            }
        } else {
            $matched = $this->evaluateOperator($operator, $current, $expected, $expectedTo);
            if (! $pathExists) {
                $reason = 'Field not found in work order snapshot';
            } elseif ($operator === 'between' && ($expected === null || $expectedTo === null)) {
                $reason = 'Between operator requires both values';
            }
        }

        return [
            'field' => $fieldKey,
            'path' => $path,
            'operator' => $operator,
            'expected' => $expected,
            'expected_to' => $expectedTo,
            'actual' => $current,
            'before' => $before,
            'after' => $after,
            'matched' => $matched,
            'reason' => $reason,
            'path_exists' => $pathExists,
        ];
    }

    protected function evaluateOperator(string $operator, mixed $actual, mixed $expected, mixed $expectedTo): bool
    {
        return match ($operator) {
            'eq' => $this->compare($actual, $expected),
            'neq' => ! $this->compare($actual, $expected),
            'contains' => $this->contains($actual, $expected),
            'starts_with' => $this->startsWith($actual, $expected),
            'ends_with' => $this->endsWith($actual, $expected),
            'in' => $this->isIn($actual, $expected),
            'not_in' => ! $this->isIn($actual, $expected),
            'gt' => $this->toNumber($actual) > $this->toNumber($expected),
            'gte' => $this->toNumber($actual) >= $this->toNumber($expected),
            'lt' => $this->toNumber($actual) < $this->toNumber($expected),
            'lte' => $this->toNumber($actual) <= $this->toNumber($expected),
            'between' => $this->between($actual, $expected, $expectedTo),
            'true' => (bool) $actual === true,
            'false' => (bool) $actual === false,
            'before' => $this->compareDates($actual, $expected, '<'),
            'after' => $this->compareDates($actual, $expected, '>'),
            'within_last' => $this->withinLast($actual, $expected),
            default => false,
        };
    }

    protected function compare(mixed $actual, mixed $expected): bool
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        if (is_array($actual) || is_array($expected)) {
            $actualList = is_array($actual) ? $actual : [$actual];
            $expectedList = is_array($expected) ? $expected : [$expected];
            $actualList = array_map('strtolower', array_map('strval', $actualList));
            $expectedList = array_map('strtolower', array_map('strval', $expectedList));
            sort($actualList);
            sort($expectedList);
            return $actualList === $expectedList;
        }

        $left = strtolower((string) $actual);
        $right = strtolower((string) $expected);
        return $left === $right;
    }

    protected function contains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            if (is_array($expected)) {
                $actualList = array_map('strtolower', array_map('strval', $actual));
                $expectedList = array_map('strtolower', array_map('strval', $expected));
                return (bool) array_intersect($actualList, $expectedList);
            }
            return in_array(strtolower((string) $expected), array_map('strtolower', array_map('strval', $actual)), true);
        }

        return str_contains(strtolower((string) $actual), strtolower((string) $expected));
    }

    protected function startsWith(mixed $actual, mixed $expected): bool
    {
        return str_starts_with(strtolower((string) $actual), strtolower((string) $expected));
    }

    protected function endsWith(mixed $actual, mixed $expected): bool
    {
        return str_ends_with(strtolower((string) $actual), strtolower((string) $expected));
    }

    protected function isIn(mixed $actual, mixed $expected): bool
    {
        $expectedList = is_array($expected)
            ? array_map('strtolower', array_map('strval', $expected))
            : array_map('strtolower', array_map('trim', explode(',', (string) $expected)));

        $expectedList = array_values(array_filter($expectedList, static fn($value) => $value !== ''));

        if (is_array($actual)) {
            $actualList = array_map('strtolower', array_map('strval', $actual));
            return (bool) array_intersect($actualList, $expectedList);
        }

        return in_array(strtolower((string) $actual), $expectedList, true);
    }

    protected function between(mixed $actual, mixed $expected, mixed $expectedTo): bool
    {
        if ($this->isDateValue($actual) && ($this->isDateValue($expected) || $this->isDateValue($expectedTo))) {
            $date = Carbon::parse($actual);
            $from = Carbon::parse($expected);
            $to = Carbon::parse($expectedTo);
            return $date->between($from, $to);
        }

        $value = $this->toNumber($actual);
        return $value >= $this->toNumber($expected) && $value <= $this->toNumber($expectedTo);
    }

    protected function compareDates(mixed $actual, mixed $expected, string $operator): bool
    {
        if (! $this->isDateValue($actual) || ! $this->isDateValue($expected)) {
            return false;
        }

        $actualDate = Carbon::parse($actual);
        $expectedDate = Carbon::parse($expected);

        return $operator === '>' ? $actualDate->gt($expectedDate) : $actualDate->lt($expectedDate);
    }

    protected function withinLast(mixed $actual, mixed $expected): bool
    {
        if (! $this->isDateValue($actual)) {
            return false;
        }

        $minutes = $this->toNumber($expected);
        $threshold = now()->subMinutes((int) $minutes);

        return Carbon::parse($actual)->gte($threshold);
    }

    protected function toNumber(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    protected function isDateValue(mixed $value): bool
    {
        if (! $value) {
            return false;
        }

        try {
            Carbon::parse($value);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function hydrateWorkOrderContext(array $workOrder): array
    {
        $metadata = Arr::get($workOrder, 'metadata', []);
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $progressPct = $this->computeProgressPctFromMetadata($metadata);
        if ($progressPct !== null) {
            $state = is_array($metadata['state'] ?? null) ? $metadata['state'] : [];
            $state['progressPct'] = $progressPct;
            $metadata['state'] = $state;
        }

        $workOrder['metadata'] = $metadata;
        return $workOrder;
    }

    protected function computeProgressPctFromMetadata(array $metadata): ?float
    {
        $routes = $this->extractRoutesFromMetadata($metadata);
        if (empty($routes)) {
            return null;
        }

        $best = null;
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }

            $timeTracker = $this->resolveRouteTimeTracker($route);
            $entries = is_array($timeTracker['entries'] ?? null) ? $timeTracker['entries'] : [];

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $value = $entry['route_progress_pct']
                    ?? $entry['routeProgressPct']
                    ?? $entry['operator_progress_pct']
                    ?? $entry['operatorProgressPct']
                    ?? null;
                if ($value === null) {
                    continue;
                }
                $numeric = $this->toNumber($value);
                if ($best === null || $numeric > $best) {
                    $best = $numeric;
                }
            }

            if ($best === null && !empty($entries)) {
                $produced = $this->resolvePrintedQty($entries);
                $target = $this->resolveTargetPrintedQty($entries, $metadata);
                if ($produced !== null && $target !== null && $target > 0) {
                    $ratio = min(1, $produced / $target);
                    $candidate = max(0, $ratio * 100);
                    if ($best === null || $candidate > $best) {
                        $best = $candidate;
                    }
                }
            }
        }

        return $best;
    }

    protected function extractRoutesFromMetadata(array $metadata): array
    {
        $routes = $metadata['routes'] ?? $metadata['data'] ?? $metadata['steps'] ?? [];
        if (!is_array($routes)) {
            return [];
        }

        $flattened = [];
        foreach ($routes as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (array_key_exists('routes', $entry) && is_array($entry['routes'])) {
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

    protected function resolveRouteTimeTracker(array $route): array
    {
        $metadata = is_array($route['metadata'] ?? null) ? $route['metadata'] : [];
        $timeTracker = $metadata['timeTracker'] ?? $metadata['time_tracker'] ?? $route['timeTracker'] ?? $route['time_tracker'] ?? [];
        return is_array($timeTracker) ? $timeTracker : [];
    }

    protected function resolvePrintedQty(array $entries): ?float
    {
        $max = null;
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $value = $entry['total_printed_qty']
                ?? $entry['totalPrintedQty']
                ?? $entry['printed_qty']
                ?? $entry['printedQty']
                ?? null;
            if ($value === null) {
                continue;
            }
            $numeric = $this->toNumber($value);
            if ($max === null || $numeric > $max) {
                $max = $numeric;
            }
        }

        return $max;
    }

    protected function resolveTargetPrintedQty(array $entries, array $metadata): ?float
    {
        $max = null;
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $value = $entry['target_printed_qty'] ?? $entry['targetPrintedQty'] ?? null;
            if ($value === null) {
                continue;
            }
            $numeric = $this->toNumber($value);
            if ($max === null || $numeric > $max) {
                $max = $numeric;
            }
        }

        if ($max !== null) {
            return $max;
        }

        $qty = Arr::get($metadata, 'state.qty');
        if ($qty === null || $qty === '') {
            return null;
        }

        return $this->toNumber($qty);
    }
}
