<?php

namespace App\Http\Resources\WorkOrder;

use App\Http\Resources\Customer\CustomerResource;
use App\Http\Resources\TemplateRoute\TemplateRouteResource;
use App\Services\DiecutIntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\UserWorkOrder;

class WorkOrderResource extends JsonResource
{
    protected function normalizeRouteToken(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return null;
        }
        $raw = str_replace(['_', '-'], ' ', $raw);
        $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;
        return $raw;
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

    protected function isRouteCompleted(array $route): bool
    {
        $status = strtolower(trim((string) ($route['status'] ?? '')));
        if ($status === '') {
            $completedAt = $route['completed_at'] ?? $route['completedAt'] ?? null;
            if ($completedAt) {
                $status = 'completed';
            }
        }

        return in_array($status, ['completed', 'complete', 'done'], true);
    }

    protected function resolveRouteCompletion(array $routes): array
    {
        $total = 0;
        $completed = 0;
        $rollingComplete = false;
        $packingRouteComplete = false;
        $hasAny = false;

        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }
            $label = $route['route'] ?? $route['name'] ?? $route['key'] ?? $route['label'] ?? null;
            $token = $this->normalizeRouteToken($label);
            if ($token) {
                $hasAny = true;
            }

            $isCompleted = $this->isRouteCompleted($route);
            if ($token === 'rolling prep') {
                if ($isCompleted) {
                    $rollingComplete = true;
                }
                continue;
            }
            if ($token === 'packing checklist') {
                if ($isCompleted) {
                    $packingRouteComplete = true;
                }
                continue;
            }

            if ($label !== null) {
                $total++;
                if ($isCompleted) {
                    $completed++;
                }
            }
        }

        return [
            'total' => $total,
            'completed' => $completed,
            'rolling_complete' => $rollingComplete,
            'packing_route_complete' => $packingRouteComplete,
            'has_any' => $hasAny,
        ];
    }

    protected function hasPackingChecklist(): bool
    {
        if (isset($this->packing_checklist_count)) {
            return (int) $this->packing_checklist_count > 0;
        }
        if (isset($this->packing_checklist_exists)) {
            return (bool) $this->packing_checklist_exists;
        }
        if (!$this->work_order_no) {
            return false;
        }

        return \App\Models\PackingChecklist::query()
            ->where('work_order_no', $this->work_order_no)
            ->exists();
    }

    protected function resolveIsReleased(string $statusRaw): bool
    {
        if ($statusRaw !== '') {
            $normalized = str_replace(['-', '_'], ' ', $statusRaw);
            $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

            if (in_array($normalized, ['draft', 'backlog', 'new', 'planned', 'plan', 'hold', 'on hold', 'blocked', 'paused'], true)) {
                return false;
            }

            if (in_array($normalized, ['released', 'in progress', 'active', 'completed', 'complete', 'done'], true)) {
                return true;
            }
        }

        return (bool) $this->is_released;
    }

    protected function resolveNormalizedStatus(): string
    {
        $columnStatus = trim((string) ($this->status ?? ''));
        if ($columnStatus !== '') {
            return $columnStatus;
        }

        $metadata = $this->metadata;
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $metadata = $decoded;
            }
        }
        $metadata = is_array($metadata) ? $metadata : [];
        $state = is_array($metadata['state'] ?? null) ? $metadata['state'] : [];
        $statusRaw = strtolower(trim((string) ($state['status'] ?? $metadata['status'] ?? ($this->status ?? ''))));
        $isReleased = $this->resolveIsReleased($statusRaw);

        $routes = $this->extractRoutes($metadata);
        $routeStats = $this->resolveRouteCompletion($routes);

        $hasRouteLink = !empty($this->template_route_id) || $routeStats['has_any'] || $routeStats['total'] > 0;
        $allRoutesCompleted = $routeStats['total'] > 0 && $routeStats['completed'] >= $routeStats['total'];
        $rollingComplete = $routeStats['rolling_complete'];
        $packingComplete = $routeStats['packing_route_complete'] || $this->hasPackingChecklist();

        if ($allRoutesCompleted && $rollingComplete && $packingComplete) {
            return 'Completed';
        }

        $backlogRaw = in_array($statusRaw, [
            'draft',
            'planned',
            'plan',
            'new',
            'backlog',
            'hold',
            'on hold',
            'blocked',
            'paused',
        ], true);

        if ($backlogRaw || !$hasRouteLink || !$isReleased) {
            return 'Backlog';
        }

        return 'In Progress';
    }

    protected function resolveDisplayStatus(): string
    {
        $status = trim((string) ($this->status ?? ''));
        $normalized = strtolower($status);
        $isTerminal = $normalized !== '' &&
            (str_contains($normalized, 'complete') || str_contains($normalized, 'cancel'));
        if ($isTerminal && $status !== '') {
            return $status;
        }

        $isReleased = (bool) ($this->is_released ?? false);
        return $isReleased ? 'In Progress' : 'Backlog';
    }
    protected function resolveEvidenceUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        $clean = ltrim($path, '/');
        if (str_starts_with($clean, 'images/')) {
            $clean = substr($clean, strlen('images/'));
        }
        return url("/api/v1/images/{$clean}");
    }

    protected function shouldIncludeDiecutContext(Request $request): bool
    {
        return $request->boolean('include_diecut_context')
            || $request->is('api/v1/work-orders/detail')
            || preg_match('#^api/v1/work-orders/[0-9]+$#', trim($request->path(), '/'));
    }

    protected function resolveDiecutContext(Request $request): ?array
    {
        if (!$this->shouldIncludeDiecutContext($request)) {
            return null;
        }

        try {
            return app(DiecutIntelligenceService::class)->buildWorkOrderContext($this->resource);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $userAssignments = null;
        if ($this->relationLoaded('userAssignments')) {
            $userAssignments = $this->userAssignments
                ->map(function (UserWorkOrder $assignment): array {
                    return [
                        'id' => $assignment->id,
                        'user_id' => $assignment->user_id,
                        'route_key' => $assignment->route_key,
                        'route_code' => $assignment->route_code,
                        'route_name' => $assignment->route_name,
                        'order_seq' => $assignment->order_seq,
                        'assigned_qty' => $assignment->assigned_qty,
                        'user' => $assignment->user ? [
                            'id' => $assignment->user->id,
                            'firstname' => $assignment->user->firstname,
                            'lastname' => $assignment->user->lastname,
                            'middlename' => $assignment->user->middlename,
                            'email' => $assignment->user->email,
                            'picture_url' => $assignment->user->picture_url,
                        ] : null,
                    ];
                })
                ->values()
                ->all();
        }

        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'template_route_id' => $this->template_route_id,
            'work_order_no' => $this->work_order_no,
            'batch_number' => $this->batch_number,
            'selected' => $this->selected,
            'mes_batch_no' => $this->mes_batch_no,
            'customer_code' => $this->customer_code,
            'customer_name' => $this->customer_name,
            'material_1_code' => $this->material_1_code,
            'material_2_code' => $this->material_2_code,
            'material_3_code' => $this->material_3_code,
            'material_4_code' => $this->material_4_code,
            'customer_part_number' => $this->customer_part_number,
            'production_due_date' => $this->production_due_date,
            'production_start_date' => $this->production_start_date,
            'quantity_to_produce' => $this->quantity_to_produce,
            'quantity_produced' => $this->quantity_produced,
            'forecast_quantity' => $this->forecast_quantity,
            'die_cut' => $this->die_cut,
            'internal_remark' => $this->internal_remark,
            'requested_delivery_date' => $this->requested_delivery_date,
            'no_of_colours' => $this->no_of_colours,
            'sales_person_code' => $this->sales_person_code,
            'order_date' => $this->order_date,
            'production_date_completed' => $this->production_date_completed,
            'production_qty_completed' => $this->production_qty_completed,
            'status' => $this->resolveNormalizedStatus(),
            'display_status' => $this->resolveDisplayStatus(),
            'priority' => $this->priority ?? $this->priority_type,
            'is_starred' => (bool) ($this->is_starred ?? false),
            'qr_code' => $this->qr_code,
            'sheet' => $this->sheet,
            'is_released' => $this->is_released,
            'evidence_images' => $this->evidence_images ?? [],
            'evidence_image_urls' => array_map(
                fn ($path) => $this->resolveEvidenceUrl($path),
                $this->evidence_images ?? []
            ),
            'metadata' => $this->metadata,
            'user_assignments' => $userAssignments,
            'diecut_context' => $this->resolveDiecutContext($request),
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'template_route' => TemplateRouteResource::make($this->whenLoaded('templateRoute')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
