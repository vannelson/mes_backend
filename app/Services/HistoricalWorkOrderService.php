<?php

namespace App\Services;

use App\Http\Resources\HistoricalWorkOrder\HistoricalWorkOrderResource;
use App\Models\HistoricalWorkOrder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class HistoricalWorkOrderService
{
    public function getList(array $filters = [], array $order = [], int $limit = 25, int $page = 1): array
    {
        $query = $this->applyFilters($this->baseListingQuery(), $filters);

        [$orderBy, $direction] = !empty($order) ? $order : ['date_completed', 'desc'];
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $allowed = [
            'date_completed',
            'work_order_no',
            'material_batch_no',
            'die_cut',
            'machine_name',
            'machine_code',
            'machine_type',
            'staff_code',
            'no_of_press',
            'printed_quantity',
            'no_of_ups',
            'customer_part_number',
            'customer_code',
            'id',
        ];

        if (!in_array($orderBy, $allowed, true)) {
            $orderBy = 'date_completed';
        }

        if (in_array($orderBy, ['printed_quantity', 'no_of_press', 'no_of_ups'], true)) {
            $column = $this->qualifyColumn($orderBy);
            $query->orderByRaw(
                "CAST(REPLACE(COALESCE({$column}, '0'), ',', '') AS DECIMAL(18,2)) {$direction}"
            );
        } else {
            $query->orderBy($this->qualifyColumn($orderBy), $direction);
        }

        $paginator = $query->paginate($limit, ['*'], 'page', $page);

        return HistoricalWorkOrderResource::collection($paginator)->response()->getData(true);
    }

    public function getByPartNumber(array $filters = [], array $order = [], int $limit = 12, int $page = 1): array
    {
        $baseSub = $this->buildPartNumberBaseQuery($filters);

        [$orderBy, $direction] = !empty($order) ? $order : ['latest_completed', 'desc'];
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $allowed = [
            'customer_part_number',
            'customer_code',
            'total_rows',
            'total_work_orders',
            'total_printed_qty',
            'total_press',
            'total_ups',
            'average_cycle_days',
            'min_cycle_days',
            'max_cycle_days',
            'earliest_started',
            'latest_completed',
        ];

        if (!in_array($orderBy, $allowed, true)) {
            $orderBy = 'latest_completed';
        }

        $query = DB::query()
            ->fromSub($baseSub, 'part_history')
            ->selectRaw('part_number_label AS customer_part_number')
            ->selectRaw('customer_code_label AS customer_code')
            ->selectRaw('COUNT(*) AS total_rows')
            ->selectRaw("COUNT(DISTINCT NULLIF(work_order_label, 'Unassigned')) AS total_work_orders")
            ->selectRaw('SUM(press_value) AS total_press')
            ->selectRaw('SUM(ups_value) AS total_ups')
            ->selectRaw('SUM(printed_value) AS total_printed_qty')
            ->selectRaw('MIN(started_date) AS earliest_started')
            ->selectRaw('MAX(completed_date) AS latest_completed')
            ->selectRaw('AVG(cycle_days) AS average_cycle_days')
            ->selectRaw('MIN(cycle_days) AS min_cycle_days')
            ->selectRaw('MAX(cycle_days) AS max_cycle_days')
            ->groupBy('part_number_label', 'customer_code_label');

        $query->orderBy($orderBy, $direction)
            ->orderBy('customer_part_number')
            ->orderBy('customer_code');

        $paginator = $query->paginate($limit, ['*'], 'page', $page);
        $items = collect($paginator->items())
            ->map(fn ($row) => (array) $row)
            ->values();

        $workOrdersByPart = $this->getWorkOrdersForPartGroups($filters, $items->all());

        $data = $items
            ->map(function (array $row) use ($workOrdersByPart): array {
                $key = $this->partGroupKey(
                    $row['customer_part_number'] ?? '',
                    $row['customer_code'] ?? ''
                );

                return [
                    'customer_part_number' => $row['customer_part_number'] ?? 'Unassigned',
                    'customer_code' => $row['customer_code'] ?? 'Unknown',
                    'total_rows' => (int) ($row['total_rows'] ?? 0),
                    'total_work_orders' => (int) ($row['total_work_orders'] ?? 0),
                    'total_press' => (float) ($row['total_press'] ?? 0),
                    'total_ups' => (float) ($row['total_ups'] ?? 0),
                    'total_printed_qty' => (float) ($row['total_printed_qty'] ?? 0),
                    'earliest_started' => $row['earliest_started'] ?? null,
                    'latest_completed' => $row['latest_completed'] ?? null,
                    'average_cycle_days' => $row['average_cycle_days'] !== null
                        ? (float) $row['average_cycle_days']
                        : null,
                    'min_cycle_days' => $row['min_cycle_days'] !== null
                        ? (float) $row['min_cycle_days']
                        : null,
                    'max_cycle_days' => $row['max_cycle_days'] !== null
                        ? (float) $row['max_cycle_days']
                        : null,
                    'work_orders' => $workOrdersByPart[$key] ?? [],
                ];
            })
            ->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function summary(array $filters = []): array
    {
        $baseQuery = $this->applyFilters($this->baseFilterQuery(), $filters);

        $totalRows = (clone $baseQuery)->count();
        $uniqueWorkOrders = (clone $baseQuery)
            ->whereNotNull($this->qualifyColumn('work_order_no'))
            ->where($this->qualifyColumn('work_order_no'), '!=', '')
            ->distinct()
            ->count($this->qualifyColumn('work_order_no'));

        $totalPrintedQty = (clone $baseQuery)->selectRaw(
            "SUM({$this->numericExpression('printed_quantity')}) AS total"
        )->value('total');

        $totalPress = (clone $baseQuery)->selectRaw(
            "SUM({$this->numericExpression('no_of_press')}) AS total"
        )->value('total');

        $totalUps = (clone $baseQuery)->selectRaw(
            "SUM({$this->numericExpression('no_of_ups')}) AS total"
        )->value('total');

        $uniqueMachines = (clone $baseQuery)->selectRaw(
            "COUNT(DISTINCT COALESCE(NULLIF(TRIM({$this->qualifyColumn('machine_code')}), ''), NULLIF(TRIM({$this->qualifyColumn('machine_name')}), ''))) AS total"
        )->value('total');

        $machineTypeRaw = "NULLIF(TRIM({$this->qualifyColumn('machine_type')}), '')";
        $machineTypeExpr = "COALESCE({$machineTypeRaw}, 'Unknown')";
        $uniqueMachineTypes = (clone $baseQuery)->selectRaw(
            "COUNT(DISTINCT {$machineTypeExpr}) AS total"
        )->value('total');

        $staffCodeRaw = "NULLIF(TRIM({$this->qualifyColumn('staff_code')}), '')";
        $staffCodeExpr = "COALESCE({$staffCodeRaw}, 'Unknown')";
        $staffNameRaw = "NULLIF(TRIM({$this->staffNameExpression()}), '')";
        $staffNameExpr = "COALESCE({$staffNameRaw}, 'Unknown')";
        $uniqueStaff = (clone $baseQuery)->selectRaw(
            "COUNT(DISTINCT {$staffCodeExpr}) AS total"
        )->value('total');

        $latestCompleted = (clone $baseQuery)
            ->whereNotNull($this->qualifyColumn('date_completed'))
            ->where($this->qualifyColumn('date_completed'), '!=', '')
            ->orderBy($this->qualifyColumn('date_completed'), 'desc')
            ->value($this->qualifyColumn('date_completed'));

        $machineSub = (clone $baseQuery)
            ->selectRaw("{$machineTypeExpr} AS machine_type_label")
            ->selectRaw("{$this->numericExpression('no_of_press')} AS press_value")
            ->selectRaw("{$this->numericExpression('no_of_ups')} AS ups_value")
            ->selectRaw("{$this->numericExpression('printed_quantity')} AS printed_value");

        $machineRows = DB::query()
            ->fromSub($machineSub, 'machine_rollup')
            ->selectRaw('machine_type_label AS label')
            ->selectRaw('COUNT(*) AS row_count')
            ->selectRaw('SUM(press_value) AS press_total')
            ->selectRaw('SUM(ups_value) AS ups_total')
            ->selectRaw('SUM(printed_value) AS printed_total')
            ->groupBy('machine_type_label')
            ->orderByRaw('SUM(printed_value) DESC')
            ->get()
            ->map(static function ($row): array {
                return [
                    'label' => $row->label,
                    'row_count' => (int) $row->row_count,
                    'press_total' => (float) $row->press_total,
                    'ups_total' => (float) $row->ups_total,
                    'printed_total' => (float) $row->printed_total,
                ];
            })
            ->values()
            ->all();

        $staffSub = (clone $baseQuery)
            ->selectRaw("{$staffCodeExpr} AS staff_code_label")
            ->selectRaw("{$staffNameExpr} AS staff_name_label")
            ->selectRaw("{$this->numericExpression('no_of_press')} AS press_value")
            ->selectRaw("{$this->numericExpression('no_of_ups')} AS ups_value")
            ->selectRaw("{$this->numericExpression('printed_quantity')} AS printed_value");

        $staffRows = DB::query()
            ->fromSub($staffSub, 'staff_rollup')
            ->selectRaw('staff_code_label AS staff_code')
            ->selectRaw('staff_name_label AS staff_name')
            ->selectRaw('COUNT(*) AS row_count')
            ->selectRaw('SUM(press_value) AS press_total')
            ->selectRaw('SUM(ups_value) AS ups_total')
            ->selectRaw('SUM(printed_value) AS printed_total')
            ->groupBy('staff_code_label', 'staff_name_label')
            ->orderByRaw('SUM(printed_value) DESC')
            ->get()
            ->map(static function ($row): array {
                return [
                    'staff_code' => $row->staff_code,
                    'staff_name' => $row->staff_name,
                    'row_count' => (int) $row->row_count,
                    'press_total' => (float) $row->press_total,
                    'ups_total' => (float) $row->ups_total,
                    'printed_total' => (float) $row->printed_total,
                ];
            })
            ->values()
            ->all();

        return [
            'total_rows' => (int) $totalRows,
            'unique_work_orders' => (int) $uniqueWorkOrders,
            'total_printed_qty' => $totalPrintedQty ? (float) $totalPrintedQty : 0,
            'total_press' => $totalPress ? (float) $totalPress : 0,
            'total_ups' => $totalUps ? (float) $totalUps : 0,
            'unique_machines' => (int) ($uniqueMachines ?? 0),
            'unique_machine_types' => (int) ($uniqueMachineTypes ?? 0),
            'unique_staff' => (int) ($uniqueStaff ?? 0),
            'latest_completed' => $latestCompleted,
            'by_machine_type' => $machineRows,
            'by_staff' => $staffRows,
        ];
    }

    public function getFilterOptions(string $column, array $filters = []): array
    {
        $expressions = $this->filterOptionExpressions();
        if (!array_key_exists($column, $expressions)) {
            return [];
        }

        $expression = $expressions[$column];
        $valueExpression = "NULLIF(TRIM({$expression}), '')";

        $query = $this->applyFilters($this->baseFilterQuery(), $filters)
            ->selectRaw("{$valueExpression} AS value")
            ->whereRaw("{$valueExpression} IS NOT NULL");

        if ($column === 'completed_month') {
            $sortExpression = $this->monthSortExpression();
            $query->selectRaw("{$sortExpression} AS sort_value")
                ->groupBy('value', 'sort_value')
                ->orderBy('sort_value', 'desc');
        } else {
            $query->distinct()->orderBy('value');
        }

        return $query
            ->pluck('value')
            ->filter(static fn ($value) => $value !== null && $value !== '')
            ->values()
            ->all();
    }

    private function baseListingQuery(): Builder
    {
        return $this->baseFilterQuery()
            ->select([
                'historical_work_orders.*',
                DB::raw($this->staffNameExpression() . ' AS staff_name'),
                DB::raw($this->effectiveStartDateExpression() . ' AS effective_start_date'),
            ]);
    }

    private function baseFilterQuery(): Builder
    {
        return HistoricalWorkOrder::query()
            ->leftJoin(
                'users',
                $this->qualifyColumn('staff_code'),
                '=',
                'users.staff_code'
            );
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        if ($search = Arr::get($filters, 'q')) {
            $query->where(function (Builder $q) use ($search) {
                $q->where($this->qualifyColumn('work_order_no'), 'LIKE', "%{$search}%")
                    ->orWhere($this->qualifyColumn('material_batch_no'), 'LIKE', "%{$search}%")
                    ->orWhere($this->qualifyColumn('die_cut'), 'LIKE', "%{$search}%")
                    ->orWhere($this->qualifyColumn('machine_name'), 'LIKE', "%{$search}%")
                    ->orWhere($this->qualifyColumn('machine_code'), 'LIKE', "%{$search}%")
                    ->orWhere($this->qualifyColumn('machine_type'), 'LIKE', "%{$search}%")
                    ->orWhere($this->qualifyColumn('staff_code'), 'LIKE', "%{$search}%")
                    ->orWhereRaw($this->staffNameExpression() . " LIKE ?", ["%{$search}%"])
                    ->orWhere($this->qualifyColumn('customer_part_number'), 'LIKE', "%{$search}%")
                    ->orWhere($this->qualifyColumn('customer_code'), 'LIKE', "%{$search}%");
            });
        }

        $this->applyLikeFilter($query, $filters, 'work_order_no');
        $this->applyLikeFilter($query, $filters, 'material_batch_no');
        $this->applyLikeFilter($query, $filters, 'die_cut');
        $this->applyLikeFilter($query, $filters, 'machine_name');
        $this->applyLikeFilter($query, $filters, 'machine_code');
        $this->applyLikeFilter($query, $filters, 'machine_type');
        $this->applyLikeFilter($query, $filters, 'staff_code');
        $this->applyStaffNameFilter($query, $filters);
        $this->applyLikeFilter($query, $filters, 'customer_part_number');
        $this->applyLikeFilter($query, $filters, 'customer_code');

        if ($dateFrom = Arr::get($filters, 'date_completed_from')) {
            $query->whereRaw('DATE(' . $this->qualifyColumn('date_completed') . ') >= ?', [$dateFrom]);
        }
        if ($dateTo = Arr::get($filters, 'date_completed_to')) {
            $query->whereRaw('DATE(' . $this->qualifyColumn('date_completed') . ') <= ?', [$dateTo]);
        }
        if ($completedMonth = Arr::get($filters, 'completed_month')) {
            $parsed = $this->parseCompletedMonthFilter($completedMonth);
            if ($parsed) {
                $column = $this->qualifyColumn('date_completed');
                $query->whereRaw(
                    "YEAR({$column}) = ? AND MONTH({$column}) = ?",
                    [$parsed['year'], $parsed['month']]
                );
            } else {
                $expression = $this->monthYearExpression();
                $query->whereRaw("{$expression} = ?", [trim((string) $completedMonth)]);
            }
        }

        $this->applyNumericFilter($query, $filters, 'no_of_press');
        $this->applyNumericFilter($query, $filters, 'no_of_ups');
        $this->applyNumericFilter($query, $filters, 'printed_quantity');

        return $query;
    }

    private function applyLikeFilter(Builder $query, array $filters, string $key): void
    {
        $value = Arr::get($filters, $key);
        if ($value === null || $value === '') {
            return;
        }
        $query->where($this->qualifyColumn($key), 'LIKE', "%{$value}%");
    }

    private function applyStaffNameFilter(Builder $query, array $filters): void
    {
        $value = Arr::get($filters, 'staff_name');
        if ($value === null || $value === '') {
            return;
        }
        $query->whereRaw($this->staffNameExpression() . " LIKE ?", ["%{$value}%"]);
    }

    private function applyNumericFilter(Builder $query, array $filters, string $key): void
    {
        $min = Arr::get($filters, "{$key}_min");
        $max = Arr::get($filters, "{$key}_max");
        if ($min === null && $max === null) {
            return;
        }

        $expression = $this->numericExpression($key);

        if ($min !== null && $min !== '') {
            $query->whereRaw("{$expression} >= ?", [$min]);
        }
        if ($max !== null && $max !== '') {
            $query->whereRaw("{$expression} <= ?", [$max]);
        }
    }

    private function qualifyColumn(string $column): string
    {
        return "historical_work_orders.{$column}";
    }

    private function buildPartNumberBaseQuery(array $filters): Builder
    {
        return $this->applyFilters($this->baseFilterQuery(), $filters)
            ->selectRaw($this->partNumberExpression() . ' AS part_number_label')
            ->selectRaw($this->customerCodeExpression() . ' AS customer_code_label')
            ->selectRaw($this->workOrderExpression() . ' AS work_order_label')
            ->selectRaw($this->machineNameExpression() . ' AS machine_name_label')
            ->selectRaw($this->staffNameLabelExpression() . ' AS staff_name_label')
            ->selectRaw("{$this->numericExpression('no_of_press')} AS press_value")
            ->selectRaw("{$this->numericExpression('no_of_ups')} AS ups_value")
            ->selectRaw("{$this->numericExpression('printed_quantity')} AS printed_value")
            ->selectRaw($this->effectiveStartDateExpression() . ' AS started_date')
            ->selectRaw($this->dateExpression('date_completed') . ' AS completed_date')
            ->selectRaw($this->cycleDaysExpression() . ' AS cycle_days');
    }

    private function getWorkOrdersForPartGroups(array $filters, array $groups): array
    {
        if (empty($groups)) {
            return [];
        }

        $detailQuery = DB::query()
            ->fromSub($this->buildPartNumberBaseQuery($filters), 'part_work_order_history')
            ->selectRaw('part_number_label AS customer_part_number')
            ->selectRaw('customer_code_label AS customer_code')
            ->selectRaw('work_order_label AS work_order_no')
            ->selectRaw('COUNT(*) AS total_rows')
            ->selectRaw('SUM(press_value) AS total_press')
            ->selectRaw('SUM(ups_value) AS total_ups')
            ->selectRaw('SUM(printed_value) AS total_printed_qty')
            ->selectRaw('MIN(started_date) AS earliest_started')
            ->selectRaw('MAX(completed_date) AS latest_completed')
            ->selectRaw('AVG(cycle_days) AS average_cycle_days')
            ->selectRaw('MIN(cycle_days) AS min_cycle_days')
            ->selectRaw('MAX(cycle_days) AS max_cycle_days')
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(machine_name_label, 'Unknown') ORDER BY machine_name_label SEPARATOR ', ') AS machine_names")
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(staff_name_label, 'Unknown') ORDER BY staff_name_label SEPARATOR ', ') AS staff_names")
            ->groupBy('part_number_label', 'customer_code_label', 'work_order_label');

        $detailQuery->where(function ($query) use ($groups) {
            foreach ($groups as $group) {
                $query->orWhere(function ($nested) use ($group) {
                    $nested->where('part_number_label', $group['customer_part_number'] ?? 'Unassigned')
                        ->where('customer_code_label', $group['customer_code'] ?? 'Unknown');
                });
            }
        });

        return $detailQuery
            ->get()
            ->map(function ($row): array {
                return [
                    'customer_part_number' => $row->customer_part_number,
                    'customer_code' => $row->customer_code,
                    'work_order_no' => $row->work_order_no,
                    'total_rows' => (int) $row->total_rows,
                    'total_press' => (float) ($row->total_press ?? 0),
                    'total_ups' => (float) ($row->total_ups ?? 0),
                    'total_printed_qty' => (float) ($row->total_printed_qty ?? 0),
                    'earliest_started' => $row->earliest_started,
                    'latest_completed' => $row->latest_completed,
                    'average_cycle_days' => $row->average_cycle_days !== null
                        ? (float) $row->average_cycle_days
                        : null,
                    'min_cycle_days' => $row->min_cycle_days !== null
                        ? (float) $row->min_cycle_days
                        : null,
                    'max_cycle_days' => $row->max_cycle_days !== null
                        ? (float) $row->max_cycle_days
                        : null,
                    'machine_names' => $row->machine_names
                        ? array_values(array_filter(array_map('trim', explode(',', $row->machine_names))))
                        : [],
                    'staff_names' => $row->staff_names
                        ? array_values(array_filter(array_map('trim', explode(',', $row->staff_names))))
                        : [],
                ];
            })
            ->sortBy([
                ['latest_completed', 'desc'],
                ['total_printed_qty', 'desc'],
                ['work_order_no', 'asc'],
            ])
            ->groupBy(fn (array $row) => $this->partGroupKey(
                $row['customer_part_number'] ?? '',
                $row['customer_code'] ?? ''
            ))
            ->map(
                fn ($rows) => $rows
                    ->map(fn (array $row) => Arr::except($row, ['customer_part_number', 'customer_code']))
                    ->values()
                    ->all()
            )
            ->all();
    }

    private function filterOptionExpressions(): array
    {
        return [
            'work_order_no' => $this->qualifyColumn('work_order_no'),
            'material_batch_no' => $this->qualifyColumn('material_batch_no'),
            'die_cut' => $this->qualifyColumn('die_cut'),
            'machine_name' => $this->qualifyColumn('machine_name'),
            'machine_code' => $this->qualifyColumn('machine_code'),
            'machine_type' => $this->qualifyColumn('machine_type'),
            'staff_code' => $this->qualifyColumn('staff_code'),
            'staff_name' => $this->staffNameExpression(),
            'customer_part_number' => $this->qualifyColumn('customer_part_number'),
            'customer_code' => $this->qualifyColumn('customer_code'),
            'completed_month' => $this->monthYearExpression(),
        ];
    }

    private function staffNameExpression(): string
    {
        return "TRIM(COALESCE(NULLIF(CONCAT_WS(' ', users.firstname, users.middlename, users.lastname), ''), NULLIF({$this->qualifyColumn('add_user')}, ''), ''))";
    }

    private function staffNameLabelExpression(): string
    {
        return "COALESCE(NULLIF({$this->staffNameExpression()}, ''), 'Unknown')";
    }

    private function partNumberExpression(): string
    {
        return "COALESCE(NULLIF(TRIM({$this->qualifyColumn('customer_part_number')}), ''), 'Unassigned')";
    }

    private function customerCodeExpression(): string
    {
        return "COALESCE(NULLIF(TRIM({$this->qualifyColumn('customer_code')}), ''), 'Unknown')";
    }

    private function workOrderExpression(): string
    {
        return "COALESCE(NULLIF(TRIM({$this->qualifyColumn('work_order_no')}), ''), 'Unassigned')";
    }

    private function machineNameExpression(): string
    {
        return "COALESCE(NULLIF(TRIM({$this->qualifyColumn('machine_name')}), ''), 'Unknown')";
    }

    private function monthYearExpression(): string
    {
        $column = $this->qualifyColumn('date_completed');
        return "DATE_FORMAT({$column}, '%M-%Y')";
    }

    private function monthSortExpression(): string
    {
        $column = $this->qualifyColumn('date_completed');
        return "DATE_FORMAT({$column}, '%Y-%m')";
    }

    private function parseCompletedMonthFilter(mixed $value): ?array
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^(\\d{4})[-\\/](\\d{1,2})$/', $raw, $matches)) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            if ($year > 0 && $month >= 1 && $month <= 12) {
                return ['year' => $year, 'month' => $month];
            }
        }

        $normalized = preg_replace('/\\s+/', '-', $raw) ?? $raw;
        foreach (['F-Y', 'M-Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $normalized);
            } catch (\Throwable) {
                $date = null;
            }

            if ($date) {
                return [
                    'year' => (int) $date->format('Y'),
                    'month' => (int) $date->format('n'),
                ];
            }
        }

        return null;
    }

    private function numericExpression(string $column): string
    {
        $qualified = $this->qualifyColumn($column);
        return "CAST(REPLACE(COALESCE({$qualified}, '0'), ',', '') AS DECIMAL(18,2))";
    }

    private function dateExpression(string $column): string
    {
        $qualified = $this->qualifyColumn($column);
        return "DATE(NULLIF(TRIM({$qualified}), ''))";
    }

    private function cycleDaysExpression(): string
    {
        $started = $this->effectiveStartDateExpression();
        $completed = $this->dateExpression('date_completed');

        return "CASE WHEN {$started} IS NOT NULL AND {$completed} IS NOT NULL THEN DATEDIFF({$completed}, {$started}) END";
    }

    private function effectiveStartDateExpression(): string
    {
        $added = $this->dateExpression('add_date');
        $started = $this->dateExpression('date_started');

        return "COALESCE({$added}, {$started})";
    }

    private function partGroupKey(string $partNumber, string $customerCode): string
    {
        return "{$partNumber}::{$customerCode}";
    }
}
