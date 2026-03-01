<?php

namespace App\Services;

use App\Models\OperationTrigger;
use App\Models\WorkOrder;
use App\Services\Contracts\OperationTriggerServiceInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OperationTriggerService implements OperationTriggerServiceInterface
{
    protected array $fieldMap = [
        'status' => 'status',
        'priority' => 'priority',
        'assignee' => 'metadata.state.assignee',
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
        protected FirebaseRealtimeService $firebaseRealtimeService
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
            'work_order' => $workOrder->toArray(),
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
        $current = $path ? data_get($context['work_order'] ?? [], $path) : null;

        $matched = false;
        $reason = null;

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
        }

        return [
            'field' => $fieldKey,
            'path' => $path,
            'operator' => $operator,
            'expected' => $expected,
            'expected_to' => $expectedTo,
            'actual' => $current,
            'matched' => $matched,
            'reason' => $reason,
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

        return (string) $actual === (string) $expected;
    }

    protected function contains(mixed $actual, mixed $expected): bool
    {
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
        if (is_array($expected)) {
            return in_array((string) $actual, array_map('strval', $expected), true);
        }

        $list = array_filter(array_map('trim', explode(',', (string) $expected)));
        return in_array((string) $actual, $list, true);
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
}
