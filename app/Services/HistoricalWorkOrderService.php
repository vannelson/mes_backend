<?php

namespace App\Services;

use App\Http\Resources\HistoricalWorkOrder\HistoricalWorkOrderResource;
use App\Models\HistoricalWorkOrder;
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

        return $this->applyFilters($this->baseFilterQuery(), $filters)
            ->selectRaw("DISTINCT {$valueExpression} AS value")
            ->whereRaw("{$valueExpression} IS NOT NULL")
            ->orderBy('value')
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
        ];
    }

    private function staffNameExpression(): string
    {
        return "TRIM(COALESCE(NULLIF(CONCAT_WS(' ', users.firstname, users.middlename, users.lastname), ''), NULLIF({$this->qualifyColumn('add_user')}, ''), ''))";
    }

    private function numericExpression(string $column): string
    {
        $qualified = $this->qualifyColumn($column);
        return "CAST(REPLACE(COALESCE({$qualified}, '0'), ',', '') AS DECIMAL(18,2))";
    }
}
