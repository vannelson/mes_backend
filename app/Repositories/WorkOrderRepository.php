<?php

namespace App\Repositories;

use App\Models\WorkOrder;
use App\Repositories\Contracts\WorkOrderRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkOrderRepository extends BaseRepository implements WorkOrderRepositoryInterface
{
    public function __construct(WorkOrder $workOrder)
    {
        parent::__construct($workOrder);
    }

    public function update(int $id, array $data): int
    {
        $workOrder = $this->model->findOrFail($id);

        $workOrder->fill($data);

        return $workOrder->isDirty() && $workOrder->save() ? 1 : 0;
    }

    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->with(['customer', 'templateRoute'])
            ->withCount('packingChecklist');

        if ($workOrderNo = Arr::get($filters, 'work_order_no')) {
            $query->where('work_order_no', 'LIKE', "%{$workOrderNo}%");
        }

        if ($batchNumber = Arr::get($filters, 'batch_number')) {
            $query->where('batch_number', 'LIKE', "%{$batchNumber}%");
        }

        if ($sheet = Arr::get($filters, 'sheet')) {
            $normalized = strtolower(trim((string) $sheet));
            if ($normalized !== '') {
                $query->whereRaw(
                    "LOWER(TRIM(COALESCE(sheet, ''))) = ?",
                    [$normalized]
                );
            }
        }

        if ($customerId = Arr::get($filters, 'customer_id')) {
            $query->where('customer_id', $customerId);
        }

        if (($selected = Arr::get($filters, 'selected')) !== null) {
            $query->where('selected', filter_var($selected, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]) ?? (bool) $selected);
        }

        if ($mesBatchNo = Arr::get($filters, 'mes_batch_no')) {
            $query->where('mes_batch_no', 'LIKE', "%{$mesBatchNo}%");
        }

        if ($customerCode = Arr::get($filters, 'customer_code')) {
            $query->where('customer_code', 'LIKE', "%{$customerCode}%");
        }

        if ($customerName = Arr::get($filters, 'customer_name')) {
            $query->where('customer_name', 'LIKE', "%{$customerName}%");
        }

        if ($customerPartNumber = Arr::get($filters, 'customer_part_number')) {
            $query->where('customer_part_number', 'LIKE', "%{$customerPartNumber}%");
        }

        if ($salesPersonCode = Arr::get($filters, 'sales_person_code')) {
            $query->where('sales_person_code', 'LIKE', "%{$salesPersonCode}%");
        }

        if ($orderFrom = Arr::get($filters, 'order_date_from')) {
            $query->whereDate('order_date', '>=', $orderFrom);
        }

        if ($orderTo = Arr::get($filters, 'order_date_to')) {
            $query->whereDate('order_date', '<=', $orderTo);
        }

        if ($dueFrom = Arr::get($filters, 'production_due_from')) {
            $query->whereDate('production_due_date', '>=', $dueFrom);
        }

        if ($dueTo = Arr::get($filters, 'production_due_to')) {
            $query->whereDate('production_due_date', '<=', $dueTo);
        }

        if ($requestedFrom = Arr::get($filters, 'requested_delivery_from')) {
            $query->whereDate('requested_delivery_date', '>=', $requestedFrom);
        }

        if ($requestedTo = Arr::get($filters, 'requested_delivery_to')) {
            $query->whereDate('requested_delivery_date', '<=', $requestedTo);
        }

        $scheduleFrom = Arr::get($filters, 'schedule_from');
        $scheduleTo = Arr::get($filters, 'schedule_to');
        if ($scheduleFrom || $scheduleTo) {
            $normalizedStatus = strtolower(trim((string) Arr::get($filters, 'status')));
            if ($normalizedStatus !== '' && str_contains($normalizedStatus, 'complete')) {
                $query->where(function ($range) use ($scheduleFrom, $scheduleTo) {
                    if ($scheduleFrom) {
                        $range->whereDate('production_date_completed', '>=', $scheduleFrom);
                    }
                    if ($scheduleTo) {
                        $range->whereDate('production_date_completed', '<=', $scheduleTo);
                    }
                });
            } else {
                $query->where(function ($range) use ($scheduleFrom, $scheduleTo) {
                    $startField = "COALESCE(production_start_date, production_due_date)";
                    if ($scheduleTo) {
                        $range->whereDate(DB::raw($startField), '<=', $scheduleTo);
                    }
                    if ($scheduleFrom) {
                        $range->where(function ($overlap) use ($scheduleFrom) {
                            $overlap->whereDate('production_due_date', '>=', $scheduleFrom)
                                ->orWhereDate(DB::raw("COALESCE(production_start_date, production_due_date)"), '>=', $scheduleFrom);
                        });
                    }
                });
            }
        }

        if ($orderDays = Arr::get($filters, 'order_date_days')) {
            $this->applyDayOfWeekFilter($query, 'order_date', $orderDays);
        }

        if ($dueDays = Arr::get($filters, 'production_due_days')) {
            $this->applyDayOfWeekFilter($query, 'production_due_date', $dueDays);
        }

        if ($requestedDays = Arr::get($filters, 'requested_delivery_days')) {
            $this->applyDayOfWeekFilter($query, 'requested_delivery_date', $requestedDays);
        }

        if ($templateRouteId = Arr::get($filters, 'template_route_id')) {
            $query->where('template_route_id', $templateRouteId);
        }

        if ($operatorId = Arr::get($filters, 'operator_id')) {
            $metadataMatchedIds = $this->resolveMetadataAssignedWorkOrderIds((string) $operatorId);
            $query->where(function ($q) use ($operatorId, $metadataMatchedIds) {
                $q->whereHas('userAssignments', function ($assignmentQuery) use ($operatorId) {
                    $assignmentQuery->where('user_id', $operatorId);
                });

                if (!empty($metadataMatchedIds)) {
                    $q->orWhereIn('id', $metadataMatchedIds);
                }
            });
        }

        if ($templateRouteBatch = Arr::get($filters, 'template_route_batch_number')) {
            $query->whereHas('templateRoute', function ($q) use ($templateRouteBatch) {
                $q->where('batch_number', $templateRouteBatch);
            });
        }

        if ($priority = Arr::get($filters, 'priority')) {
            $normalized = strtoupper(trim((string) $priority));
            if ($normalized !== '') {
                $normalized = str_replace([' ', '-'], '_', $normalized);
                $query->whereRaw(
                    "REPLACE(REPLACE(UPPER(TRIM(COALESCE(priority, priority_type, ''))), ' ', '_'), '-', '_') = ?",
                    [$normalized]
                );
            }
        }

        if (($isStarred = Arr::get($filters, 'is_starred')) !== null) {
            $query->where('is_starred', filter_var($isStarred, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]) ?? (bool) $isStarred);
        }

        if ($status = Arr::get($filters, 'status')) {
            $normalized = strtolower(trim((string) $status));
            if ($normalized !== '') {
                $statusField = "LOWER(TRIM(COALESCE(status, '')))";
                $query->whereRaw("{$statusField} = ?", [$normalized]);
            }
        }

        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $priorityCase = "CASE REPLACE(REPLACE(UPPER(TRIM(COALESCE(priority, priority_type, ''))), ' ', '_'), '-', '_') " .
            "WHEN 'ASAP' THEN 1 " .
            "WHEN 'VERY_URGENT' THEN 2 " .
            "WHEN 'URGENT' THEN 3 " .
            "WHEN 'MEDIUM' THEN 4 " .
            "WHEN 'LOW' THEN 5 " .
            "ELSE 99 END";

        switch ($orderBy) {
            case 'route_link':
                // Order by presence of a linked template route, then by id for stability
                $query
                    ->orderByRaw("template_route_id IS NULL " . ($direction === 'asc' ? 'ASC' : 'DESC'))
                    ->orderBy('template_route_id', $direction)
                    ->orderBy('id', $direction);
                break;
            case 'starred_priority':
                $query->orderBy('is_starred', 'desc')
                    ->orderByRaw($priorityCase)
                    ->orderBy('id', $direction);
                break;
            case 'priority':
                $query->orderByRaw($priorityCase)
                    ->orderBy('id', $direction);
                break;
            case 'production_due_date':
            case 'order_date':
            case 'requested_delivery_date':
            case 'status':
            case 'work_order_no':
            case 'customer_name':
            case 'customer_code':
            case 'customer_part_number':
            case 'id':
                $query->orderBy($orderBy, $direction);
                if ($orderBy !== 'id') {
                    $query->orderBy('id', $direction);
                }
                break;
            default:
                $query->orderBy('id', 'desc');
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    protected function resolveMetadataAssignedWorkOrderIds(string $operatorId): array
    {
        if ($operatorId === '') {
            return [];
        }

        return WorkOrder::query()
            ->select(['id', 'metadata'])
            ->get()
            ->filter(function (WorkOrder $workOrder) use ($operatorId): bool {
                return $this->workOrderMetadataHasOperatorAssignment($workOrder->metadata, $operatorId);
            })
            ->pluck('id')
            ->map(static fn($id) => (int) $id)
            ->values()
            ->all();
    }

    protected function workOrderMetadataHasOperatorAssignment(mixed $metadata, string $operatorId): bool
    {
        $normalized = $this->normalizeMetadata($metadata);
        if (empty($normalized)) {
            return false;
        }

        $routes = Arr::get($normalized, 'assignments.routes')
            ?? Arr::get($normalized, 'route_assignments')
            ?? Arr::get($normalized, 'routeAssignments')
            ?? Arr::get($normalized, 'routes')
            ?? [];

        foreach ($this->flattenAssignmentRoutes($routes) as $route) {
            if (!is_array($route)) {
                continue;
            }

            $operators = Arr::get($route, 'operators', []);
            if (is_array($operators)) {
                foreach ($operators as $operator) {
                    if ($this->operatorAssignmentMatches($operator, $operatorId)) {
                        return true;
                    }
                }
            }

            $directOperatorId = Arr::get($route, 'operator_id')
                ?? Arr::get($route, 'operatorId')
                ?? Arr::get($route, 'user_id')
                ?? Arr::get($route, 'metadata.machineOperatorId')
                ?? Arr::get($route, 'machineOperatorId');
            if ($directOperatorId !== null && (string) $directOperatorId === $operatorId) {
                return true;
            }

            $additionalMachines = Arr::get($route, 'metadata.additionalMachines')
                ?? Arr::get($route, 'additionalMachines')
                ?? [];
            if (is_array($additionalMachines)) {
                foreach ($additionalMachines as $machine) {
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
                    if ($machineOperatorId !== null && (string) $machineOperatorId === $operatorId) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    protected function flattenAssignmentRoutes(mixed $routes): array
    {
        if (is_string($routes)) {
            $decoded = json_decode($routes, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $routes = $decoded;
            }
        }

        if (!is_array($routes)) {
            return [];
        }

        if (Arr::has($routes, 'routes')) {
            $routes = Arr::get($routes, 'routes', []);
        }

        $flattened = [];
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }

            if (isset($route['routes']) && is_array($route['routes'])) {
                foreach ($route['routes'] as $nestedRoute) {
                    if (is_array($nestedRoute)) {
                        $flattened[] = $nestedRoute;
                    }
                }
                continue;
            }

            $flattened[] = $route;
        }

        return $flattened;
    }

    protected function operatorAssignmentMatches(mixed $operator, string $operatorId): bool
    {
        if (is_array($operator)) {
            $candidate = Arr::get($operator, 'id')
                ?? Arr::get($operator, 'user_id')
                ?? Arr::get($operator, 'userId');

            return $candidate !== null && (string) $candidate === $operatorId;
        }

        return $operator !== null && (string) $operator === $operatorId;
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

    public function options(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->select(['id', 'work_order_no']);

        if ($search = Arr::get($filters, 'search')) {
            $query->where('work_order_no', 'LIKE', "%{$search}%");
        }

        if ($customerId = Arr::get($filters, 'customer_id')) {
            $query->where('customer_id', $customerId);
        }

        [$orderBy, $direction] = !empty($order) ? $order : ['id', 'desc'];
        $query->orderBy($orderBy, $direction);

        return $query->paginate($limit, ['id', 'work_order_no'], 'page', $page);
    }

    public function findByColumn(string $column, mixed $value)
    {
        return $this->model->newQuery()->where($column, $value)->firstOrFail();
    }

    public function withTemplateRoutes(): Collection
    {
        return $this->model
            ->newQuery()
            ->with(['customer', 'templateRoute'])
            ->whereNotNull('template_route_id')
            ->whereHas('templateRoute')
            ->where(function ($query) {
                $query
                    ->whereNotNull('metadata')
                    ->where('metadata', '<>', '')
                    ->where('metadata', '<>', '[]')
                    ->where('metadata', '<>', '{}');
            })
            ->orderBy('id', 'desc')
            ->get();
    }

    public function countByBatch(string $batchNumber): int
    {
        return $this->model->newQuery()
            ->where('batch_number', $batchNumber)
            ->count();
    }

    public function deleteByBatch(string $batchNumber): int
    {
        return $this->model->newQuery()
            ->where('batch_number', $batchNumber)
            ->delete();
    }

    public function countByTemplateRouteBatch(string $batchNumber): int
    {
        return $this->model->newQuery()
            ->whereHas('templateRoute', function ($q) use ($batchNumber) {
                $q->where('batch_number', $batchNumber);
            })
            ->count();
    }

    public function updateByCustomerCodeAndPartNumber(
        string $customerCode,
        string $customerPartNumber,
        array $data
    ): int {
        return $this->model->newQuery()
            ->where('customer_code', $customerCode)
            ->where('customer_part_number', $customerPartNumber)
            ->update($data);
    }

    protected function applyDayOfWeekFilter($query, string $column, mixed $days): void
    {
        $tokens = $this->normalizeDayTokens($days);
        if (empty($tokens)) {
            return;
        }

        $driver = DB::getDriverName();
        $mysqlMap = [
            'mon' => 0,
            'tue' => 1,
            'wed' => 2,
            'thu' => 3,
            'fri' => 4,
            'sat' => 5,
            'sun' => 6,
        ];
        $isoMap = [
            'sun' => 0,
            'mon' => 1,
            'tue' => 2,
            'wed' => 3,
            'thu' => 4,
            'fri' => 5,
            'sat' => 6,
        ];

        if ($driver === 'mysql') {
            $values = array_values(array_unique(array_map(fn($d) => $mysqlMap[$d], $tokens)));
            $query->whereNotNull($column)
                ->whereIn(DB::raw("WEEKDAY({$column})"), $values);
            return;
        }

        if ($driver === 'pgsql') {
            $values = array_values(array_unique(array_map(fn($d) => $isoMap[$d], $tokens)));
            $query->whereNotNull($column)
                ->whereIn(DB::raw("EXTRACT(DOW FROM {$column})"), $values);
            return;
        }

        if ($driver === 'sqlite') {
            $values = array_values(array_unique(array_map(fn($d) => $isoMap[$d], $tokens)));
            $query->whereNotNull($column)
                ->whereIn(DB::raw("strftime('%w', {$column})"), $values);
            return;
        }

        if ($driver === 'sqlsrv') {
            $names = array_values(array_unique(array_map([$this, 'dayTokenToName'], $tokens)));
            $query->whereNotNull($column)
                ->whereIn(DB::raw("DATENAME(WEEKDAY, {$column})"), $names);
        }
    }

    protected function normalizeDayTokens(mixed $days): array
    {
        if ($days === null) {
            return [];
        }

        $raw = is_array($days) ? $days : preg_split('/[,\s]+/', (string) $days);
        $valid = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $tokens = [];

        foreach ($raw as $day) {
            if ($day === null) {
                continue;
            }
            $label = strtolower(trim((string) $day));
            if ($label === '') {
                continue;
            }
            $short = substr($label, 0, 3);
            if (in_array($short, $valid, true)) {
                $tokens[] = $short;
            }
        }

        return array_values(array_unique($tokens));
    }

    protected function dayTokenToName(string $token): string
    {
        return match ($token) {
            'mon' => 'Monday',
            'tue' => 'Tuesday',
            'wed' => 'Wednesday',
            'thu' => 'Thursday',
            'fri' => 'Friday',
            'sat' => 'Saturday',
            'sun' => 'Sunday',
            default => $token,
        };
    }
}
