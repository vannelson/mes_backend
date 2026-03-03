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
        'route_status' => 'loop.item.status',
        'route_name' => 'loop.item.name',
        'route_code' => 'loop.item.route',
        'route_progress_pct' => 'loop.item.progress_pct',
        'assignee_id' => 'loop.item.id',
        'assignee_name' => 'loop.item.name',
        'checklist_label' => 'loop.item.label',
        'checklist_status' => 'loop.item.status',
        'parameter_name' => 'loop.item.name',
        'parameter_value' => 'loop.item.value',
    ];

    public function __construct(
        protected FirebaseRealtimeService $firebaseRealtimeService,
        protected MessageService $messageService,
        protected WorkOrderNotificationService $notificationService,
        protected TriggerEmailService $triggerEmailService
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

        $evaluation = $this->evaluateTriggerLogic($trigger, $context);

        return [
            'trigger_id' => $trigger->id,
            'matched' => $evaluation['matched'] ?? false,
            'branch' => $evaluation['branch'] ?? null,
            'summary' => $evaluation['summary'] ?? null,
            'branches' => $evaluation['branches'] ?? [],
            'loop' => $evaluation['loop'] ?? [],
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

        return $this->executeForTrigger(
            $trigger,
            $workOrder,
            $context,
            $actorId,
            Arr::get($payload, 'event_id'),
            $executionId
        );
    }

    public function executeForWorkOrderEvent(
        string $event,
        WorkOrder $workOrder,
        array $beforeSnapshot,
        array $afterSnapshot,
        ?int $actorId = null,
        ?string $eventId = null
    ): array {
        $triggers = OperationTrigger::query()
            ->where('status', 'published')
            ->where('is_active', true)
            ->get();

        if ($triggers->isEmpty()) {
            return [
                'count' => 0,
                'results' => [],
            ];
        }

        $workOrderContext = $this->buildWorkOrderContextFromSnapshot($workOrder, $afterSnapshot);
        $changes = $this->buildChangeMap($beforeSnapshot, $afterSnapshot);
        $context = [
            'work_order' => $workOrderContext,
            'changes' => $changes,
        ];

        $results = [];
        foreach ($triggers as $trigger) {
            if (! $this->shouldTriggerForEvent($trigger, $event)) {
                continue;
            }
            if ($this->shouldSkipForCooldown($trigger)) {
                $results[] = [
                    'trigger_id' => $trigger->id,
                    'status' => 'skipped',
                    'reason' => 'Cooldown/debounce window active.',
                ];
                continue;
            }

            $results[] = $this->executeForTrigger(
                $trigger,
                $workOrder,
                $context,
                $actorId,
                $eventId
            );
        }

        return [
            'count' => count($results),
            'results' => $results,
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
            'loop' => Arr::get($data, 'loop', $trigger?->loop ?? []),
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

    protected function executeForTrigger(
        OperationTrigger $trigger,
        WorkOrder $workOrder,
        array $context,
        ?int $actorId = null,
        ?string $eventId = null,
        ?string $executionId = null
    ): array {
        $rule = is_array($trigger->rule) ? $trigger->rule : [];
        $loop = $this->normalizeLoopConfig($trigger->loop ?? []);
        $branches = $this->normalizeActionBranches($trigger->actions ?? []);
        $evaluation = null;
        $actionResults = [];
        $matched = false;
        $startedAt = microtime(true);

        if ($loop['mode'] === 'for_each') {
            $items = $this->resolveLoopItems($loop, $context);
            $items = array_slice($items, 0, $loop['limit']);
            $loopResults = [];
            $matchedCount = 0;

            foreach ($items as $index => $item) {
                $loopContext = array_merge($context, [
                    'loop' => [
                        'item' => $item,
                        'index' => $index,
                        'collection' => $loop['collection'],
                    ],
                ]);
                $branchEval = $this->evaluateBranchRules($rule, $branches, $loopContext);
                $loopResults[] = $branchEval;

                if ($branchEval['matched'] ?? false) {
                    $matchedCount++;
                    if ($loop['gate'] === 'any') {
                        $variables = $this->buildTemplateVariables(
                            $context['work_order'] ?? $workOrder->toArray(),
                            [
                                'event_id' => $eventId,
                                'loop_item' => $item,
                                'loop_index' => $index,
                                'loop_collection' => $loop['collection'],
                            ]
                        );
                        $results = $this->executeActionList(
                            $branchEval['actions'] ?? [],
                            $workOrder,
                            $variables,
                            $actorId
                        );
                        foreach ($results as &$result) {
                            $result['loop_index'] = $index;
                            $result['branch'] = $branchEval['branch'] ?? null;
                        }
                        $actionResults = array_merge($actionResults, $results);
                    }
                }
            }

            $allMatched = ! empty($items) && $matchedCount === count($items);
            $matched = $loop['gate'] === 'all' ? $allMatched : $matchedCount > 0;

            if ($loop['gate'] === 'all' && $matched) {
                foreach ($loopResults as $index => $branchEval) {
                    if (! ($branchEval['matched'] ?? false)) {
                        continue;
                    }
                    $item = $items[$index] ?? null;
                    $variables = $this->buildTemplateVariables(
                        $context['work_order'] ?? $workOrder->toArray(),
                        [
                            'event_id' => $eventId,
                            'loop_item' => $item,
                            'loop_index' => $index,
                            'loop_collection' => $loop['collection'],
                        ]
                    );
                    $results = $this->executeActionList(
                        $branchEval['actions'] ?? [],
                        $workOrder,
                        $variables,
                        $actorId
                    );
                    foreach ($results as &$result) {
                        $result['loop_index'] = $index;
                        $result['branch'] = $branchEval['branch'] ?? null;
                    }
                    $actionResults = array_merge($actionResults, $results);
                }
            }

            $evaluation = [
                'matched' => $matched,
                'summary' => $loopResults[0]['summary'] ?? null,
                'branches' => $loopResults[0]['branches'] ?? [],
                'loop' => [
                    'mode' => 'for_each',
                    'collection' => $loop['collection'],
                    'gate' => $loop['gate'],
                    'items' => count($items),
                    'matched' => $matchedCount,
                ],
            ];
        } else {
            if ($loop['mode'] === 'while') {
                $loopCondition = is_array($loop['condition'] ?? null) ? $loop['condition'] : [];
                $loopEval = $this->evaluateGroup($loopCondition, $context);
                if (! ($loopEval['matched'] ?? false)) {
                    $evaluation = [
                        'matched' => false,
                        'summary' => $loopEval,
                        'loop' => [
                            'mode' => 'while',
                            'condition' => $loopEval,
                        ],
                    ];
                }
            }

            if ($evaluation === null) {
                $branchEval = $this->evaluateBranchRules($rule, $branches, $context);
                $evaluation = $branchEval;
                $matched = $branchEval['matched'] ?? false;

                if ($matched) {
                    $variables = $this->buildTemplateVariables(
                        $context['work_order'] ?? $workOrder->toArray(),
                        ['event_id' => $eventId]
                    );
                    $actionResults = $this->executeActionList(
                        $branchEval['actions'] ?? [],
                        $workOrder,
                        $variables,
                        $actorId
                    );
                }
            }
        }

        if (! $matched) {
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

    protected function buildWorkOrderContextFromSnapshot(
        WorkOrder $workOrder,
        array $snapshot
    ): array {
        $context = $workOrder->toArray();
        foreach (['status', 'priority', 'is_released', 'metadata'] as $key) {
            if (array_key_exists($key, $snapshot)) {
                $context[$key] = $snapshot[$key];
            }
        }

        return $this->hydrateWorkOrderContext($context);
    }

    protected function buildChangeMap(array $beforeSnapshot, array $afterSnapshot): array
    {
        $changes = [];
        foreach ($this->fieldMap as $fieldKey => $path) {
            if (str_starts_with((string) $path, 'loop.')) {
                continue;
            }
            $before = data_get($beforeSnapshot, $path);
            $after = data_get($afterSnapshot, $path);
            if (! $this->compare($before, $after)) {
                $changes[$fieldKey] = [
                    'before' => $before,
                    'after' => $after,
                ];
            }
        }

        foreach (['status', 'priority', 'is_released'] as $fieldKey) {
            $before = $beforeSnapshot[$fieldKey] ?? null;
            $after = $afterSnapshot[$fieldKey] ?? null;
            if (! $this->compare($before, $after)) {
                $changes[$fieldKey] = [
                    'before' => $before,
                    'after' => $after,
                ];
            }
        }

        return $changes;
    }

    protected function shouldTriggerForEvent(OperationTrigger $trigger, string $event): bool
    {
        $schedule = is_array($trigger->schedule) ? $trigger->schedule : [];
        $mode = strtolower((string) ($schedule['mode'] ?? 'event'));
        if ($mode !== 'event') {
            return false;
        }

        $scheduleEvent = $schedule['event'] ?? null;
        if (! $scheduleEvent) {
            return true;
        }

        if ($scheduleEvent === $event) {
            return true;
        }

        if ($scheduleEvent === 'work_order.updated') {
            return in_array($event, [
                'work_order.status_changed',
                'work_order.progress',
                'work_order.checklist',
                'work_order.validation',
            ], true);
        }

        return false;
    }

    protected function shouldSkipForCooldown(OperationTrigger $trigger): bool
    {
        $lastFired = $trigger->last_fired_at;
        if (! $lastFired) {
            return false;
        }

        $cooldown = is_array($trigger->cooldown) ? $trigger->cooldown : [];
        $debounce = is_array($trigger->debounce) ? $trigger->debounce : [];

        $cooldownMinutes = $this->normalizeWindowMinutes($cooldown);
        $debounceMinutes = $this->normalizeWindowMinutes($debounce);

        $windowMinutes = max($cooldownMinutes, $debounceMinutes);
        if ($windowMinutes <= 0) {
            return false;
        }

        return $lastFired->gt(now()->subMinutes($windowMinutes));
    }

    protected function normalizeWindowMinutes(array $window): int
    {
        $value = (int) ($window['value'] ?? 0);
        if ($value <= 0) {
            return 0;
        }
        $unit = strtolower((string) ($window['unit'] ?? 'minutes'));
        return match ($unit) {
            'hours' => $value * 60,
            'days' => $value * 1440,
            default => $value,
        };
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

        $variables = [
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
            'app_url' => config('app.url'),
        ];

        $loopItem = $payload['loop_item'] ?? null;
        if (is_array($loopItem)) {
            $variables['loop_item_json'] = json_encode($loopItem);
            $variables['loop_item_name'] = $loopItem['name'] ?? $loopItem['label'] ?? null;
            $variables['loop_item_id'] = $loopItem['id'] ?? null;
        }

        return array_merge($variables, $payload);
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

    protected function executeEmailAction(
        array $action,
        WorkOrder $workOrder,
        array $variables,
        ?int $actorId = null
    ): array {
        $emails = $this->resolveRecipientEmails($action, $workOrder);
        if (empty($emails)) {
            return [
                'type' => 'email',
                'status' => 'skipped',
                'reason' => 'No recipients resolved.',
            ];
        }

        $subjectTemplate = Arr::get($action, 'subject')
            ?: Arr::get($action, 'label', 'Work order update');
        $subject = $this->renderTemplate($subjectTemplate, $variables);
        $html = $this->renderTemplate(Arr::get($action, 'template', ''), $variables);
        $text = trim(strip_tags($html));

        try {
            $this->triggerEmailService->send([
                'to' => $emails,
                'subject' => $subject,
                'html' => $html,
                'text' => $text !== '' ? $text : null,
                'category' => config('services.mailtrap.category', 'MES Automation'),
            ]);
        } catch (\Throwable $e) {
            return [
                'type' => 'email',
                'status' => 'failed',
                'reason' => $e->getMessage(),
            ];
        }

        return [
            'type' => 'email',
            'status' => 'success',
            'recipients' => count($emails),
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
        $urlTemplate = Arr::get($webhook, 'url');
        $url = $urlTemplate ? $this->renderTemplate((string) $urlTemplate, $variables) : null;
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
        $payload = is_string($payload) ? trim($payload) : $payload;
        $json = is_string($payload) ? json_decode($payload, true) : null;

        try {
            $request = Http::withHeaders($headers);
            if ($method === 'GET' && ($payload === '' || $payload === null)) {
                $response = $request->send($method, $url);
            } elseif ($json !== null && json_last_error() === JSON_ERROR_NONE) {
                $response = $request->send($method, $url, ['json' => $json]);
            } else {
                $response = $request->send($method, $url, ['body' => $payload]);
            }
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

    protected function resolveRecipientEmails(array $action, WorkOrder $workOrder): array
    {
        $mode = Arr::get($action, 'recipients.mode', 'assignee');

        if ($mode === 'emails') {
            return $this->normalizeEmailList(Arr::get($action, 'recipients.emails', ''));
        }

        $recipientIds = $this->resolveRecipientIds($action, $workOrder);
        if (empty($recipientIds)) {
            return [];
        }

        $emails = User::query()
            ->whereIn('id', $recipientIds)
            ->pluck('email')
            ->filter()
            ->all();

        return $this->normalizeEmailList($emails);
    }

    protected function normalizeEmailList(mixed $value): array
    {
        $items = [];

        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value)) {
            $items = preg_split('/[,\s;]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $emails = [];
        foreach ($items as $item) {
            $email = trim((string) $item);
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $emails[] = strtolower($email);
        }

        return array_values(array_unique($emails));
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
                'loop' => $trigger->loop ?? [],
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

    protected function normalizeLoopConfig(mixed $raw): array
    {
        $loop = is_array($raw) ? $raw : [];
        $mode = strtolower((string) ($loop['mode'] ?? 'none'));

        return [
            'mode' => in_array($mode, ['for_each', 'while'], true) ? $mode : 'none',
            'collection' => $loop['collection'] ?? 'routes',
            'gate' => strtolower((string) ($loop['gate'] ?? 'any')) === 'all' ? 'all' : 'any',
            'limit' => max(1, (int) ($loop['limit'] ?? 25)),
            'condition' => is_array($loop['condition'] ?? null) ? $loop['condition'] : [],
        ];
    }

    protected function normalizeActionBranches(mixed $raw): array
    {
        if (! is_array($raw)) {
            return ['if' => [], 'else_if' => [], 'else' => []];
        }

        if (array_values($raw) === $raw) {
            return [
                'if' => $raw,
                'else_if' => [],
                'else' => [],
            ];
        }

        $elseIf = [];
        foreach (($raw['else_if'] ?? []) as $branch) {
            if (! is_array($branch)) {
                continue;
            }
            $elseIf[] = [
                'id' => $branch['id'] ?? Str::uuid()->toString(),
                'label' => $branch['label'] ?? 'Else if',
                'rule' => is_array($branch['rule'] ?? null) ? $branch['rule'] : [],
                'actions' => is_array($branch['actions'] ?? null) ? $branch['actions'] : [],
            ];
        }

        return [
            'if' => is_array($raw['if'] ?? null) ? $raw['if'] : [],
            'else_if' => $elseIf,
            'else' => is_array($raw['else'] ?? null) ? $raw['else'] : [],
        ];
    }

    protected function evaluateBranchRules(array $rule, array $branches, array $context): array
    {
        $ifEval = $this->evaluateGroup($rule, $context);
        $elseIfResults = [];

        if ($ifEval['matched'] ?? false) {
            return [
                'matched' => true,
                'branch' => 'if',
                'summary' => $ifEval,
                'branches' => [
                    'if' => $ifEval,
                    'else_if' => [],
                    'else' => ['matched' => false],
                ],
                'actions' => $branches['if'] ?? [],
            ];
        }

        foreach ($branches['else_if'] ?? [] as $branch) {
            $evaluation = $this->evaluateGroup(
                is_array($branch['rule'] ?? null) ? $branch['rule'] : [],
                $context
            );
            $elseIfResults[] = [
                'id' => $branch['id'] ?? null,
                'label' => $branch['label'] ?? 'Else if',
                'evaluation' => $evaluation,
            ];

            if ($evaluation['matched'] ?? false) {
                return [
                    'matched' => true,
                    'branch' => 'else_if',
                    'branch_id' => $branch['id'] ?? null,
                    'summary' => $evaluation,
                    'branches' => [
                        'if' => $ifEval,
                        'else_if' => $elseIfResults,
                        'else' => ['matched' => false],
                    ],
                    'actions' => $branch['actions'] ?? [],
                ];
            }
        }

        $elseActions = $branches['else'] ?? [];
        $elseMatched = ! empty($elseActions);

        return [
            'matched' => $elseMatched,
            'branch' => $elseMatched ? 'else' : null,
            'summary' => $ifEval,
            'branches' => [
                'if' => $ifEval,
                'else_if' => $elseIfResults,
                'else' => ['matched' => $elseMatched],
            ],
            'actions' => $elseMatched ? $elseActions : [],
        ];
    }

    protected function resolveLoopItems(array $loop, array $context): array
    {
        $workOrder = $context['work_order'] ?? [];
        $metadata = Arr::get($workOrder, 'metadata', []);
        $collection = $loop['collection'] ?? 'routes';

        return match ($collection) {
            'routes' => $this->buildRouteLoopItems($metadata),
            'assignees' => $this->buildAssigneeLoopItems($metadata),
            'checklists' => $this->buildChecklistLoopItems($metadata),
            'parameters' => $this->buildParameterLoopItems($metadata),
            default => [],
        };
    }

    protected function buildRouteLoopItems(array $metadata): array
    {
        $routes = $this->extractRoutesFromMetadata($metadata);
        $items = [];
        foreach ($routes as $route) {
            if (! is_array($route)) {
                continue;
            }
            $items[] = array_merge($route, [
                'progress_pct' => $this->computeProgressPctFromRoute($route),
            ]);
        }

        return $items;
    }

    protected function buildAssigneeLoopItems(array $metadata): array
    {
        $assignees = $this->resolveAssigneeIds($metadata);
        if (empty($assignees)) {
            return [];
        }

        $users = User::query()
            ->whereIn('id', $assignees)
            ->get()
            ->keyBy('id');

        return array_map(
            static function ($id) use ($users) {
                $user = $users->get((int) $id);
                $name = trim((string) ($user?->firstname . ' ' . $user?->lastname));
                return [
                    'id' => (string) $id,
                    'name' => $name !== '' ? $name : ($user?->username ?? null),
                ];
            },
            $assignees
        );
    }

    protected function buildChecklistLoopItems(array $metadata): array
    {
        $routes = $this->extractRoutesFromMetadata($metadata);
        $items = [];
        foreach ($routes as $route) {
            if (! is_array($route)) {
                continue;
            }
            $checklists = $route['checklist'] ?? $route['checks'] ?? [];
            if (! is_array($checklists)) {
                continue;
            }
            foreach ($checklists as $check) {
                if (! is_array($check)) {
                    continue;
                }
                $items[] = array_merge($check, [
                    'route' => $route['route'] ?? null,
                    'route_name' => $route['name'] ?? null,
                ]);
            }
        }

        return $items;
    }

    protected function buildParameterLoopItems(array $metadata): array
    {
        $routes = $this->extractRoutesFromMetadata($metadata);
        $items = [];
        foreach ($routes as $route) {
            if (! is_array($route)) {
                continue;
            }
            $parameters = $route['parameters'] ?? [];
            if (! is_array($parameters)) {
                $parameters = [];
            }
            foreach ($parameters as $param) {
                if (! is_array($param)) {
                    continue;
                }
                $items[] = [
                    'name' => $param['name'] ?? ($param['label'] ?? null),
                    'label' => $param['label'] ?? null,
                    'value' => $param['current_value'] ?? $param['value'] ?? null,
                    'route' => $route['route'] ?? null,
                    'route_name' => $route['name'] ?? null,
                ];
            }

            $params = $route['params'] ?? [];
            if (is_array($params)) {
                foreach ($params as $key => $value) {
                    $items[] = [
                        'name' => (string) $key,
                        'value' => $value,
                        'route' => $route['route'] ?? null,
                        'route_name' => $route['name'] ?? null,
                    ];
                }
            }
        }

        return $items;
    }

    protected function computeProgressPctFromRoute(array $route): ?float
    {
        $timeTracker = $this->resolveRouteTimeTracker($route);
        $entries = is_array($timeTracker['entries'] ?? null) ? $timeTracker['entries'] : [];
        $best = null;

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
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

        return $best;
    }

    protected function evaluateTriggerLogic(OperationTrigger $trigger, array $context): array
    {
        $loop = $this->normalizeLoopConfig($trigger->loop ?? []);
        $rule = is_array($trigger->rule) ? $trigger->rule : [];
        $branches = $this->normalizeActionBranches($trigger->actions ?? []);

        if ($loop['mode'] === 'for_each') {
            $items = $this->resolveLoopItems($loop, $context);
            $limitedItems = array_slice($items, 0, $loop['limit']);
            $results = [];
            $matchedCount = 0;

            foreach ($limitedItems as $index => $item) {
                $loopContext = array_merge($context, [
                    'loop' => [
                        'item' => $item,
                        'index' => $index,
                        'collection' => $loop['collection'],
                    ],
                ]);
                $result = $this->evaluateBranchRules($rule, $branches, $loopContext);
                $results[] = $result;
                if ($result['matched'] ?? false) {
                    $matchedCount++;
                }
            }

            $allMatched = ! empty($limitedItems) && $matchedCount === count($limitedItems);
            $matched = $loop['gate'] === 'all' ? $allMatched : $matchedCount > 0;

            return [
                'matched' => $matched,
                'branch' => null,
                'summary' => $results[0]['summary'] ?? null,
                'branches' => [
                    'if' => $results[0]['branches']['if'] ?? null,
                    'else_if' => $results[0]['branches']['else_if'] ?? [],
                    'else' => $results[0]['branches']['else'] ?? ['matched' => false],
                ],
                'loop' => [
                    'mode' => 'for_each',
                    'collection' => $loop['collection'],
                    'gate' => $loop['gate'],
                    'items' => count($limitedItems),
                    'matched' => $matchedCount,
                ],
            ];
        }

        if ($loop['mode'] === 'while') {
            $loopCondition = is_array($loop['condition'] ?? null) ? $loop['condition'] : [];
            $loopEval = $this->evaluateGroup($loopCondition, $context);
            if (! ($loopEval['matched'] ?? false)) {
                return [
                    'matched' => false,
                    'branch' => null,
                    'summary' => $loopEval,
                    'branches' => [],
                    'loop' => [
                        'mode' => 'while',
                        'collection' => null,
                        'gate' => null,
                        'items' => 0,
                        'matched' => 0,
                        'condition' => $loopEval,
                    ],
                ];
            }
        }

        $result = $this->evaluateBranchRules($rule, $branches, $context);
        $result['loop'] = [
            'mode' => $loop['mode'],
        ];

        return $result;
    }

    protected function executeActionList(
        array $actions,
        WorkOrder $workOrder,
        array $variables,
        ?int $actorId = null
    ): array {
        $actionResults = [];

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
                'email' => $this->executeEmailAction($action, $workOrder, $variables, $actorId),
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

        return $actionResults;
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
        $source = $workOrder;
        if ($path && (str_starts_with($path, 'loop.') || str_starts_with($path, 'loop_item.'))) {
            $source = $context;
        }
        $pathExists = $path ? Arr::has($source, $path) : false;
        $current = $path ? data_get($source, $path) : null;

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
