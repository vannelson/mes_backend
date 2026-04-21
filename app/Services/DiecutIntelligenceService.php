<?php

namespace App\Services;

use App\Models\CustomerPartDiecutProfile;
use App\Models\DiecutProfile;
use App\Models\DiecutTool;
use App\Models\DiecutToolUsage;
use App\Models\Machine;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class DiecutIntelligenceService
{
    public function normalizeCode(mixed $value): string
    {
        $value = strtoupper(trim((string) ($value ?? '')));
        return $value === '' ? '' : (preg_replace('/[^A-Z0-9]+/', '', $value) ?? '');
    }

    public function normalizeBaseCode(mixed $value): string
    {
        $value = strtoupper(trim((string) ($value ?? '')));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\([^)]*\)/', '', $value) ?? $value;
        return preg_replace('/[^A-Z0-9]+/', '', $value) ?? '';
    }

    public function normalizeCustomerPart(mixed $value): string
    {
        return $this->normalizeCode($value);
    }

    public function resolveProfile(
        ?string $diecutNo = null,
        ?string $customerPartNumber = null,
        ?string $customerCode = null
    ): ?DiecutProfile {
        $normalizedPart = $this->normalizeCustomerPart($customerPartNumber);
        if ($normalizedPart !== '') {
            $partQuery = CustomerPartDiecutProfile::query()
                ->with('profile.aliases')
                ->where('normalized_customer_part_number', $normalizedPart);

            $customerCode = trim((string) ($customerCode ?? ''));
            if ($customerCode !== '') {
                $partQuery->orderByRaw('CASE WHEN customer_code = ? THEN 0 ELSE 1 END', [$customerCode]);
            }

            $mapping = $partQuery->first();
            if ($mapping?->profile) {
                return $mapping->profile;
            }
        }

        $normalizedDiecut = $this->normalizeCode($diecutNo);
        $baseNormalized = $this->normalizeBaseCode($diecutNo);
        if ($normalizedDiecut === '') {
            return null;
        }

        return DiecutProfile::query()
            ->with('aliases')
            ->where('normalized_code', $normalizedDiecut)
            ->orWhere(function (Builder $query) use ($normalizedDiecut, $baseNormalized) {
                $query->whereHas('aliases', function (Builder $aliasQuery) use ($normalizedDiecut, $baseNormalized) {
                    $aliasQuery->where('normalized_alias', $normalizedDiecut);
                    if ($baseNormalized !== '') {
                        $aliasQuery->orWhere('base_normalized_alias', $baseNormalized);
                    }
                });
            })
            ->orWhere(function (Builder $query) use ($baseNormalized) {
                if ($baseNormalized !== '') {
                    $query->where('base_normalized_code', $baseNormalized);
                }
            })
            ->first();
    }

    public function summarizeTooling(?DiecutProfile $profile): array
    {
        if (!$profile) {
            return $this->emptyToolingSummary();
        }

        $tools = DiecutTool::query()
            ->where('diecut_profile_id', $profile->id)
            ->orderByDesc('is_active')
            ->orderBy('tool_code')
            ->get();

        if ($tools->isEmpty()) {
            return $this->emptyToolingSummary();
        }

        $usageByTool = DiecutToolUsage::query()
            ->selectRaw('diecut_tool_id, SUM(COALESCE(printed_qty, 0)) as used_pcs, SUM(COALESCE(number_of_press, 0)) as used_press')
            ->whereIn('diecut_tool_id', $tools->pluck('id')->all())
            ->groupBy('diecut_tool_id')
            ->get()
            ->keyBy('diecut_tool_id');

        $totals = [
            'used_pcs' => 0.0,
            'used_press' => 0.0,
            'remaining_pcs' => 0.0,
            'remaining_press' => 0.0,
            'has_remaining_pcs' => false,
            'has_remaining_press' => false,
        ];

        $toolPayload = $tools->map(function (DiecutTool $tool) use ($usageByTool, &$totals) {
            $usage = $usageByTool->get($tool->id);
            $usedPcs = (float) ($usage->used_pcs ?? 0);
            $usedPress = (float) ($usage->used_press ?? 0);
            $remainingPcs = $tool->tool_life_pcs !== null ? max(0, (float) $tool->tool_life_pcs - $usedPcs) : null;
            $remainingPress = $tool->tool_life_press !== null ? max(0, (float) $tool->tool_life_press - $usedPress) : null;

            $totals['used_pcs'] += $usedPcs;
            $totals['used_press'] += $usedPress;
            if ($remainingPcs !== null) {
                $totals['remaining_pcs'] += $remainingPcs;
                $totals['has_remaining_pcs'] = true;
            }
            if ($remainingPress !== null) {
                $totals['remaining_press'] += $remainingPress;
                $totals['has_remaining_press'] = true;
            }

            return [
                'id' => $tool->id,
                'tool_code' => $tool->tool_code,
                'status' => $tool->status,
                'is_active' => $tool->is_active,
                'cavity' => $tool->cavity,
                'tool_life_pcs' => $tool->tool_life_pcs,
                'tool_life_press' => $tool->tool_life_press,
                'used_pcs' => $usedPcs,
                'used_press' => $usedPress,
                'remaining_pcs' => $remainingPcs,
                'remaining_press' => $remainingPress,
                'received_date' => optional($tool->received_date)->toDateString(),
                'start_date' => optional($tool->start_date)->toDateString(),
                'return_date' => optional($tool->return_date)->toDateString(),
                'remarks' => $tool->remarks,
            ];
        })->values()->all();

        return [
            'active_tools' => $tools->where('is_active', true)->count(),
            'inactive_tools' => $tools->where('is_active', false)->count(),
            'has_active_tool' => $tools->contains(fn (DiecutTool $tool) => $tool->is_active),
            'remaining_pcs' => $totals['has_remaining_pcs'] ? $totals['remaining_pcs'] : null,
            'remaining_press' => $totals['has_remaining_press'] ? $totals['remaining_press'] : null,
            'used_pcs' => $totals['used_pcs'],
            'used_press' => $totals['used_press'],
            'tools' => $toolPayload,
        ];
    }

    protected function emptyToolingSummary(): array
    {
        return [
            'active_tools' => 0,
            'inactive_tools' => 0,
            'has_active_tool' => false,
            'remaining_pcs' => null,
            'remaining_press' => null,
            'used_pcs' => 0.0,
            'used_press' => 0.0,
            'tools' => [],
        ];
    }

    public function estimateDuration(array $payload): array
    {
        $quantity = $this->toFloat(
            Arr::get($payload, 'quantity')
            ?? Arr::get($payload, 'quantity_to_produce')
            ?? Arr::get($payload, 'forecast_quantity')
        );
        $machineSpeed = $this->resolveMachineSpeed($payload);
        $profile = $this->resolveProfile(
            Arr::get($payload, 'diecut_no') ?? Arr::get($payload, 'die_cut'),
            Arr::get($payload, 'customer_part_number'),
            Arr::get($payload, 'customer_code')
        );

        $pitchMm = null;
        $columns = null;
        $linealMeters = null;
        $estimatedMinutes = null;

        if ($profile) {
            $height = $this->toFloat($profile->height_mm);
            $intervalUd = $this->toFloat($profile->interval_ud_mm);
            $columns = $this->toFloat($profile->column_count) ?: $this->toFloat($profile->no_of_ups) ?: 1.0;
            $pitchMm = $height !== null ? $height + ($intervalUd ?? 0.0) : null;

            if ($quantity !== null && $pitchMm !== null && $columns > 0) {
                $linealMeters = ($quantity * $pitchMm / $columns) / 1000;
            }

            if ($linealMeters !== null && $machineSpeed !== null && $machineSpeed > 0) {
                $estimatedMinutes = (float) ceil($linealMeters / $machineSpeed);
            }
        }

        return [
            'matched' => $profile !== null,
            'profile' => $profile ? $this->profilePayload($profile) : null,
            'inputs' => [
                'quantity' => $quantity,
                'machine_speed_m_per_min' => $machineSpeed,
            ],
            'derived' => [
                'pitch_mm' => $pitchMm,
                'columns' => $columns,
                'lineal_meters' => $linealMeters,
                'estimated_minutes' => $estimatedMinutes,
            ],
        ];
    }

    public function buildWorkOrderContext(WorkOrder $order): array
    {
        $payload = [
            'die_cut' => $order->die_cut,
            'customer_part_number' => $order->customer_part_number,
            'customer_code' => $order->customer_code,
            'quantity_to_produce' => $order->quantity_to_produce,
            'forecast_quantity' => $order->forecast_quantity,
        ];

        $machineContext = $this->resolveWorkOrderMachineContext($order);
        $machine = $machineContext['record'];
        $routeMachine = $machineContext['route_machine'];
        $routeSpeed = $this->toFloat($routeMachine['average_speed'] ?? $routeMachine['speed'] ?? $routeMachine['machine_speed'] ?? null);

        if ($machine) {
            $payload['machine_no'] = $machine->machine_no;
            $payload['machine_name'] = $machine->machine_name;
        }
        if ($routeSpeed !== null) {
            $payload['machine_speed'] = $routeSpeed;
        }

        $profile = $this->resolveProfile($order->die_cut, $order->customer_part_number, $order->customer_code);

        return [
            'profile' => $profile ? $this->profilePayload($profile) : null,
            'machine' => $this->machinePayload($machine, $routeMachine, $routeSpeed),
            'tooling' => $this->summarizeTooling($profile),
            'estimate' => $this->estimateDuration($payload),
        ];
    }

    protected function machinePayload(?Machine $machine, ?array $routeMachine = null, ?float $routeSpeed = null): ?array
    {
        if (!$machine && !$routeMachine) {
            return null;
        }

        return [
            'id' => $machine?->id,
            'machine_no' => $machine?->machine_no ?? ($routeMachine['machine_no'] ?? $routeMachine['number'] ?? null),
            'machine_name' => $machine?->machine_name ?? ($routeMachine['machine_name'] ?? $routeMachine['name'] ?? $routeMachine['label'] ?? null),
            'machine_type' => $machine?->machine_type ?? ($routeMachine['machine_type'] ?? $routeMachine['type'] ?? null),
            'average_speed' => $machine?->average_speed ?? ($routeSpeed !== null ? (string) $routeSpeed : null),
        ];
    }

    protected function profilePayload(DiecutProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'profile_code' => $profile->profile_code,
            'diecut_type' => $profile->diecut_type,
            'height_mm' => $profile->height_mm,
            'width_mm' => $profile->width_mm,
            'interval_ud_mm' => $profile->interval_ud_mm,
            'interval_lr_mm' => $profile->interval_lr_mm,
            'column_count' => $profile->column_count,
            'no_of_ups' => $profile->no_of_ups,
            'default_tool_life_pcs' => $profile->default_tool_life_pcs,
            'default_tool_life_press' => $profile->default_tool_life_press,
        ];
    }

    protected function resolveMachineSpeed(array $payload): ?float
    {
        $speed = $this->toFloat(Arr::get($payload, 'machine_speed'));
        if ($speed !== null) {
            return $speed;
        }

        return $this->toFloat(
            $this->resolveMachineRecord(
                Arr::get($payload, 'machine_no'),
                Arr::get($payload, 'machine_name')
            )?->average_speed
        );
    }

    protected function resolveWorkOrderMachineContext(WorkOrder $order): array
    {
        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $routes = $metadata['routes'] ?? $metadata['data'] ?? $metadata['steps'] ?? [];
        if (!is_array($routes)) {
            return ['record' => null, 'route_machine' => null];
        }

        foreach ($this->flattenRoutes($routes) as $route) {
            if (!is_array($route)) {
                continue;
            }

            $machine = $route['machine'] ?? $route['metadata']['machine'] ?? null;
            if (!is_array($machine)) {
                continue;
            }

            $machineType = strtolower(trim((string) ($machine['machine_type'] ?? $machine['printing_type'] ?? '')));
            $machineName = strtolower(trim((string) ($machine['machine_name'] ?? $machine['name'] ?? $machine['label'] ?? '')));
            $routeName = strtolower(trim((string) ($route['name'] ?? $route['route'] ?? $route['key'] ?? '')));
            if (str_contains($machineType, 'die') || str_contains($machineName, 'die') || str_contains($routeName, 'die')) {
                return [
                    'record' => $this->resolveMachineRecord(
                        $machine['machine_no'] ?? $machine['number'] ?? $machine['no'] ?? null,
                        $machine['machine_name'] ?? $machine['name'] ?? $machine['label'] ?? null
                    ),
                    'route_machine' => $machine,
                ];
            }
        }

        return ['record' => null, 'route_machine' => null];
    }

    protected function flattenRoutes(array $routes): array
    {
        $flat = [];
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }
            if (isset($route['routes']) && is_array($route['routes'])) {
                array_push($flat, ...$this->flattenRoutes($route['routes']));
                continue;
            }
            if (isset($route['steps']) && is_array($route['steps'])) {
                array_push($flat, ...$this->flattenRoutes($route['steps']));
                continue;
            }
            $flat[] = $route;
        }
        return $flat;
    }

    protected function resolveMachineRecord(?string $machineNo = null, ?string $machineName = null): ?Machine
    {
        $machineNo = trim((string) ($machineNo ?? ''));
        $machineName = trim((string) ($machineName ?? ''));
        if ($machineNo === '' && $machineName === '') {
            return null;
        }

        return Machine::query()
            ->where(function (Builder $query) use ($machineNo, $machineName) {
                if ($machineNo !== '') {
                    $query->where('machine_no', $machineNo);
                }
                if ($machineName !== '') {
                    $machineNo !== ''
                        ? $query->orWhere('machine_name', $machineName)
                        : $query->where('machine_name', $machineName);
                }
            })
            ->first();
    }

    public function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = preg_replace('/[^0-9.\-]+/', '', (string) $value);
        return $normalized !== null && $normalized !== '' && is_numeric($normalized)
            ? (float) $normalized
            : null;
    }
}
