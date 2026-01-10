<?php

namespace App\Http\Controllers;

use App\Models\Bom;
use App\Models\Customer;
use App\Models\Machine;
use App\Models\TemplateRoute;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Contracts\WorkOrderServiceInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class DashboardController extends Controller
{
    use ResponseTrait;

    public function __construct(
        protected WorkOrderServiceInterface $workOrderService
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        $onTimeDays = (int) $request->get('on_time_days', 30);
        $throughputDays = (int) $request->get('throughput_days', 7);
        $dueSoonDays = (int) $request->get('due_soon_days', 7);
        $recentLimit = max(1, (int) $request->get('recent_limit', 6));
        $performanceLimit = max(1, (int) $request->get('performance_limit', 3));
        $templateLimit = max(1, (int) $request->get('template_limit', 5));
        $customerLimit = max(1, (int) $request->get('customer_limit', 5));

        try {
            $workOrderSummary = $this->workOrderService->summary([
                'on_time_days' => $onTimeDays,
                'throughput_days' => $throughputDays,
                'due_soon_days' => $dueSoonDays,
            ]);

            $recentOrders = $this->fetchRecentWorkOrders($recentLimit);
            $recentOverview = $this->formatWorkOrderOverview($recentOrders);
            $machinePerformance = $this->formatMachinePerformance(
                $recentOrders->slice(0, $performanceLimit)->values()
            );

            $templateSnapshot = $this->buildTemplateRouteSnapshot($templateLimit);
            $bomSnapshot = $this->buildBomSnapshot($customerLimit);
            $customerSnapshot = $this->buildCustomerSnapshot($customerLimit);
            $machineSnapshot = $this->buildMachineSnapshot();
            $userSnapshot = $this->buildUserSnapshot();

            $alerts = $this->buildAlerts($workOrderSummary, $templateSnapshot, $bomSnapshot);

            return $this->success('Dashboard overview retrieved successfully!', [
                'work_orders' => [
                    'summary' => $workOrderSummary['summary'] ?? [],
                    'charts' => $workOrderSummary['charts'] ?? [],
                    'window' => $workOrderSummary['window'] ?? [],
                    'recent' => $recentOverview,
                    'performance' => $machinePerformance,
                ],
                'template_routes' => $templateSnapshot,
                'boms' => $bomSnapshot,
                'customers' => $customerSnapshot,
                'machines' => $machineSnapshot,
                'users' => $userSnapshot,
                'alerts' => $alerts,
            ]);
        } catch (Throwable $e) {
            return $this->error('Failed to load dashboard overview.', 500);
        }
    }

    protected function fetchRecentWorkOrders(int $limit)
    {
        return WorkOrder::query()
            ->select([
                'id',
                'work_order_no',
                'customer_name',
                'customer_code',
                'customer_part_number',
                'production_due_date',
                'requested_delivery_date',
                'order_date',
                'quantity_to_produce',
                'quantity_produced',
                'production_qty_completed',
                'metadata',
                'created_at',
                'production_date_completed',
            ])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    protected function formatWorkOrderOverview($orders): array
    {
        return $orders->map(function (WorkOrder $order): array {
            $metadata = $this->normalizeMetadata($order->metadata);
            $routes = $this->extractRoutes($metadata);
            $isCompleted = $this->resolveCompletionDate($order, $routes) !== null || $this->routesCompleted($routes);
            $status = $this->normalizeStatus($metadata['state']['status'] ?? $metadata['status'] ?? null, $isCompleted);
            $qty = $this->numericValue($order->quantity_to_produce);
            $dueDate = $this->resolveDueDate($order);
            $start = $order->order_date ?: $order->created_at;

            return [
                'id' => $order->id,
                'order' => $order->work_order_no ?: (string) $order->id,
                'part_no' => $order->customer_part_number ?: '--',
                'qty' => $qty > 0 ? $qty : null,
                'start_date' => $start ? $start->toDateString() : null,
                'need_date' => $dueDate ? $dueDate->toDateString() : null,
                'status' => $status,
                'customer' => $order->customer_name ?: $order->customer_code ?: '',
            ];
        })->values()->all();
    }

    protected function formatMachinePerformance($orders): array
    {
        $now = Carbon::now();

        return $orders->map(function (WorkOrder $order) use ($now): array {
            $metadata = $this->normalizeMetadata($order->metadata);
            $routes = $this->extractRoutes($metadata);
            $totalSteps = count($routes);
            $completedSteps = 0;
            foreach ($routes as $route) {
                $status = strtolower(trim((string) ($route['status'] ?? '')));
                if (in_array($status, ['completed', 'complete', 'done'], true)) {
                    $completedSteps++;
                }
            }
            $qty = $this->numericValue($order->quantity_to_produce);
            if ($qty <= 0) {
                $qty = $this->numericValue($metadata['state']['qty'] ?? null);
            }
            $progress = $totalSteps > 0 ? ($completedSteps / $totalSteps) * 100 : 0;
            $partsCompleted = $totalSteps > 0 ? round($qty * ($completedSteps / $totalSteps)) : 0;
            $outstanding = max($qty - $partsCompleted, 0);
            $due = $this->resolveDueDate($order);
            $daysTillDue = $due ? max(0, (int) $now->diffInDays($due, false)) : null;

            return [
                'id' => $order->work_order_no ?: (string) $order->id,
                'parts_completed' => $partsCompleted,
                'outstanding' => $outstanding,
                'days_till_due' => $daysTillDue,
                'current_progress' => round($progress, 1),
            ];
        })->values()->all();
    }

    protected function buildTemplateRouteSnapshot(int $limit): array
    {
        $templates = TemplateRoute::query()
            ->select(['id', 'template', 'metadata', 'updated_at', 'batch_number', 'user_id'])
            ->with('manager:id,firstname,lastname')
            ->get();

        $active = 0;
        $inactive = 0;
        foreach ($templates as $template) {
            if ($this->templateAppearsActive($template->metadata)) {
                $active++;
            } else {
                $inactive++;
            }
        }

        $topTemplates = TemplateRoute::query()
            ->with('manager:id,firstname,lastname')
            ->withCount('workOrders')
            ->orderByDesc('work_orders_count')
            ->limit($limit)
            ->get()
            ->map(function (TemplateRoute $template): array {
                return [
                    'id' => $template->id,
                    'template' => $template->template ?: ('Template #' . $template->id),
                    'work_orders' => $template->work_orders_count ?? 0,
                    'manager' => $this->formatUserName($template->manager),
                    'updated_at' => $template->updated_at ? $template->updated_at->toDateString() : null,
                    'batch_number' => $template->batch_number,
                    'status' => $this->templateAppearsActive($template->metadata) ? 'Active' : 'Inactive',
                ];
            })
            ->values()
            ->all();

        return [
            'total' => $templates->count(),
            'active' => $active,
            'inactive' => $inactive,
            'status_breakdown' => [
                ['status' => 'Active', 'count' => $active],
                ['status' => 'Inactive', 'count' => $inactive],
            ],
            'top_templates' => $topTemplates,
        ];
    }

    protected function buildBomSnapshot(int $limit): array
    {
        $totalRows = Bom::query()->count();
        $uniqueParts = Bom::query()->distinct('part_no')->whereNotNull('part_no')->where('part_no', '!=', '')->count('part_no');
        $uniqueCustomers = Bom::query()->distinct('customer_code')->whereNotNull('customer_code')->where('customer_code', '!=', '')->count('customer_code');

        $topCustomers = Bom::query()
            ->select('customer_code', DB::raw('COUNT(*) as total'))
            ->whereNotNull('customer_code')
            ->where('customer_code', '!=', '')
            ->groupBy('customer_code')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(function ($row): array {
                return [
                    'customer_code' => $row->customer_code,
                    'count' => (int) $row->total,
                ];
            })
            ->values()
            ->all();

        return [
            'total_rows' => $totalRows,
            'unique_parts' => $uniqueParts,
            'unique_customers' => $uniqueCustomers,
            'top_customers' => $topCustomers,
        ];
    }

    protected function buildCustomerSnapshot(int $limit): array
    {
        $total = Customer::query()->count();
        $statusBreakdown = Customer::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->get()
            ->map(function ($row): array {
                return [
                    'status' => $row->status ?: 'Unknown',
                    'count' => (int) $row->total,
                ];
            })
            ->values()
            ->all();

        $topCustomers = Customer::query()
            ->leftJoin('work_orders', 'customers.id', '=', 'work_orders.customer_id')
            ->select('customers.id', 'customers.customer_code', 'customers.customer_name', DB::raw('COUNT(work_orders.id) as total'))
            ->groupBy('customers.id', 'customers.customer_code', 'customers.customer_name')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(function ($row): array {
                return [
                    'customer_code' => $row->customer_code ?: (string) $row->id,
                    'customer_name' => $row->customer_name ?: 'Customer #' . $row->id,
                    'work_orders' => (int) $row->total,
                ];
            })
            ->values()
            ->all();

        return [
            'total' => $total,
            'status_breakdown' => $statusBreakdown,
            'top_work_orders' => $topCustomers,
        ];
    }

    protected function buildMachineSnapshot(): array
    {
        $total = Machine::query()->count();
        $byArea = Machine::query()
            ->select('production_area', DB::raw('COUNT(*) as total'))
            ->groupBy('production_area')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row): array {
                return [
                    'area' => $row->production_area ?: 'Unassigned',
                    'count' => (int) $row->total,
                ];
            })
            ->values()
            ->all();
        $byType = Machine::query()
            ->select('machine_type', DB::raw('COUNT(*) as total'))
            ->groupBy('machine_type')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row): array {
                return [
                    'type' => $row->machine_type ?: 'Unspecified',
                    'count' => (int) $row->total,
                ];
            })
            ->values()
            ->all();

        return [
            'total' => $total,
            'by_area' => $byArea,
            'by_type' => $byType,
        ];
    }

    protected function buildUserSnapshot(): array
    {
        $total = User::query()->count();
        $byType = User::query()
            ->select('user_type', DB::raw('COUNT(*) as total'))
            ->groupBy('user_type')
            ->get()
            ->map(function ($row): array {
                return [
                    'type' => $row->user_type ?: 'Unassigned',
                    'count' => (int) $row->total,
                ];
            })
            ->values()
            ->all();

        $planners = User::query()
            ->select(['id', 'firstname', 'lastname', 'user_type', 'position'])
            ->whereIn('user_type', ['Planner', 'Supervisor', 'QA'])
            ->orderBy('firstname')
            ->limit(5)
            ->get()
            ->map(function (User $user): array {
                return [
                    'id' => $user->id,
                    'name' => $this->formatUserName($user),
                    'user_type' => $user->user_type,
                    'position' => $user->position,
                ];
            })
            ->values()
            ->all();

        return [
            'total' => $total,
            'by_type' => $byType,
            'planners' => $planners,
        ];
    }

    protected function buildAlerts(array $workOrderSummary, array $templateSnapshot, array $bomSnapshot): array
    {
        $alerts = [];
        $summary = $workOrderSummary['summary'] ?? [];

        $overdue = (int) ($summary['overdue_orders'] ?? 0);
        if ($overdue > 0) {
            $alerts[] = [
                'title' => 'Overdue work orders',
                'detail' => sprintf('%d work orders are past due', $overdue),
                'severity' => $overdue >= 10 ? 'Critical' : 'Warning',
            ];
        }

        $missingTemplates = WorkOrder::query()->whereNull('template_route_id')->count();
        if ($missingTemplates > 0) {
            $alerts[] = [
                'title' => 'Work orders missing template routes',
                'detail' => sprintf('%d work orders need routing templates', $missingTemplates),
                'severity' => $missingTemplates >= 10 ? 'Critical' : 'Warning',
            ];
        }

        $inactiveTemplates = (int) ($templateSnapshot['inactive'] ?? 0);
        if ($inactiveTemplates > 0) {
            $alerts[] = [
                'title' => 'Inactive template routes',
                'detail' => sprintf('%d template routes are inactive', $inactiveTemplates),
                'severity' => $inactiveTemplates >= 5 ? 'Warning' : 'Info',
            ];
        }

        if (empty($alerts)) {
            $alerts[] = [
                'title' => 'No critical alerts',
                'detail' => 'Dashboard metrics are within expected thresholds.',
                'severity' => 'Info',
            ];
        }

        return array_slice($alerts, 0, 4);
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

    protected function extractRoutes(array $metadata): array
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

    protected function routesCompleted(array $routes): bool
    {
        if (empty($routes)) {
            return false;
        }

        foreach ($routes as $route) {
            $status = strtolower(trim((string) ($route['status'] ?? '')));
            if (!in_array($status, ['completed', 'complete', 'done'], true)) {
                return false;
            }
        }

        return true;
    }

    protected function resolveCompletionDate(WorkOrder $order, array $routes): ?Carbon
    {
        if ($order->production_date_completed) {
            return $order->production_date_completed instanceof Carbon
                ? $order->production_date_completed->copy()
                : Carbon::parse($order->production_date_completed);
        }

        $latest = null;
        foreach ($routes as $route) {
            $raw = $route['completed_at'] ?? $route['completedAt'] ?? null;
            if (!$raw) {
                continue;
            }

            try {
                $candidate = Carbon::parse($raw);
            } catch (Throwable $e) {
                continue;
            }

            if ($latest === null || $candidate->gt($latest)) {
                $latest = $candidate;
            }
        }

        return $latest ? $latest->copy() : null;
    }

    protected function resolveDueDate(WorkOrder $order): ?Carbon
    {
        $date = $order->production_due_date ?? $order->requested_delivery_date ?? null;
        if (!$date) {
            return null;
        }

        return $date instanceof Carbon ? $date->copy()->startOfDay() : Carbon::parse($date)->startOfDay();
    }

    protected function normalizeStatus(mixed $status, bool $isCompleted): string
    {
        if ($isCompleted) {
            return 'Completed';
        }

        $raw = strtolower(trim((string) $status));
        if ($raw === '') {
            return 'In Progress';
        }

        $map = [
            'draft' => 'Draft',
            'planned' => 'Draft',
            'new' => 'Draft',
            'released' => 'Released',
            'release' => 'Released',
            'ready' => 'Released',
            'in_progress' => 'In Progress',
            'in-progress' => 'In Progress',
            'in progress' => 'In Progress',
            'active' => 'In Progress',
            'hold' => 'On Hold',
            'on hold' => 'On Hold',
            'blocked' => 'On Hold',
            'paused' => 'On Hold',
        ];

        if (isset($map[$raw])) {
            return $map[$raw];
        }

        return ucwords($raw);
    }

    protected function numericValue(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    protected function templateAppearsActive(mixed $metadata): bool
    {
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $metadata = $decoded;
            }
        }

        if (!is_array($metadata)) {
            return true;
        }

        $candidates = [
            $metadata['active'] ?? null,
            $metadata['is_active'] ?? null,
            $metadata['enabled'] ?? null,
            $metadata['is_enabled'] ?? null,
            $metadata['status'] ?? null,
            $metadata['state'] ?? null,
        ];

        foreach ($candidates as $flag) {
            if ($flag === null) {
                continue;
            }
            if (is_bool($flag)) {
                return $flag;
            }
            if (is_numeric($flag)) {
                return (int) $flag === 1;
            }
            if (is_string($flag)) {
                $value = strtolower(trim($flag));
                if ($value === '') {
                    continue;
                }
                if (in_array($value, ['inactive', 'disabled', 'archived', 'retired'], true)) {
                    return false;
                }
                return in_array($value, ['active', 'enabled', 'published', 'in_use', 'inuse', 'true', '1'], true);
            }
        }

        return true;
    }

    protected function formatUserName(?User $user): string
    {
        if (!$user) {
            return '--';
        }
        $name = trim(sprintf('%s %s', $user->firstname ?? '', $user->lastname ?? ''));
        return $name !== '' ? $name : ('User #' . $user->id);
    }
}
