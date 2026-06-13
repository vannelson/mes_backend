<?php

namespace App\Repositories;

use App\Models\CalibrationMaster;
use App\Repositories\Contracts\CalibrationMasterRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

class CalibrationMasterRepository extends BaseRepository implements CalibrationMasterRepositoryInterface
{
    public function __construct(CalibrationMaster $calibrationMaster)
    {
        parent::__construct($calibrationMaster);
    }

    public function listing(array $filters = [], array $order = [], int $limit = 10, int $page = 1): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->with(['images'])->withCount('images');

        if ($search = Arr::get($filters, 'q')) {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('name_type', 'like', "%{$search}%")
                    ->orWhere('function', 'like', "%{$search}%")
                    ->orWhere('identification_number', 'like', "%{$search}%")
                    ->orWhere('reference_no', 'like', "%{$search}%")
                    ->orWhere('owner_location', 'like', "%{$search}%")
                    ->orWhere('measurement_range', 'like', "%{$search}%");
            });
        }

        if ($sheetName = Arr::get($filters, 'sheet_name')) {
            $query->where('sheet_name', $sheetName);
        }

        if ($ownerLocation = Arr::get($filters, 'owner_location')) {
            $query->where('owner_location', 'like', "%{$ownerLocation}%");
        }

        if ($function = Arr::get($filters, 'function')) {
            $query->where('function', 'like', "%{$function}%");
        }

        if ($frequency = Arr::get($filters, 'frequency_label')) {
            $query->where('frequency_label', 'like', "%{$frequency}%");
        }

        if ($dueState = Arr::get($filters, 'due_state')) {
            $today = Carbon::today()->toDateString();
            $thirtyDays = Carbon::today()->addDays(30)->toDateString();

            match ($dueState) {
                'overdue' => $query->whereNotNull('next_calibration_date')->whereDate('next_calibration_date', '<', $today),
                'due_today' => $query->whereDate('next_calibration_date', $today),
                'due_soon' => $query
                    ->whereNotNull('next_calibration_date')
                    ->whereDate('next_calibration_date', '>', $today)
                    ->whereDate('next_calibration_date', '<=', $thirtyDays),
                'unscheduled' => $query->whereNull('next_calibration_date'),
                default => null,
            };
        }

        [$orderBy, $direction] = !empty($order) ? $order : ['next_calibration_date', 'asc'];
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        if ($orderBy === 'next_calibration_date') {
            $query->orderByRaw('next_calibration_date is null')->orderBy('next_calibration_date', $direction);
        } else {
            $query->orderBy($orderBy, $direction);
        }

        $query->orderBy('sheet_order')->orderBy('source_row');

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function insights(?int $nearDays = 30): array
    {
        $today = Carbon::today();
        $nearDate = $today->copy()->addDays(max(1, (int) ($nearDays ?? 30)));

        return [
            'total' => $this->model->count(),
            'overdue' => $this->model
                ->whereNotNull('next_calibration_date')
                ->whereDate('next_calibration_date', '<', $today)
                ->count(),
            'due_today' => $this->model
                ->whereDate('next_calibration_date', $today)
                ->count(),
            'due_within_7_days' => $this->model
                ->whereNotNull('next_calibration_date')
                ->whereBetween('next_calibration_date', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])
                ->count(),
            'due_within_30_days' => $this->model
                ->whereNotNull('next_calibration_date')
                ->whereBetween('next_calibration_date', [$today->toDateString(), $nearDate->toDateString()])
                ->count(),
            'unscheduled' => $this->model
                ->whereNull('next_calibration_date')
                ->count(),
        ];
    }
}
