<?php

namespace App\Services;

use App\Http\Resources\TemplateRoute\TemplateRouteOptionResource;
use App\Http\Resources\TemplateRoute\TemplateRouteResource;
use App\Models\TemplateRoute;
use App\Models\WorkOrder;
use App\Repositories\Contracts\TemplateRouteRepositoryInterface;
use App\Services\Contracts\TemplateRouteServiceInterface;
use Illuminate\Support\Arr;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TemplateRouteService implements TemplateRouteServiceInterface
{
    public function __construct(
        protected TemplateRouteRepositoryInterface $templateRouteRepository
    ) {
    }

    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        $uniqueSequenceOnly = (bool) Arr::get($filters, 'unique_route_sequence', false);
        if ($uniqueSequenceOnly) {
            unset($filters['unique_route_sequence']);
            $allTemplates = $this->templateRouteRepository->listAll($filters, $order);
            $unique = $this->dedupeByRouteNameSequence($allTemplates);

            $total = count($unique);
            $page = max(1, $page);
            $limit = max(1, $limit);
            $offset = ($page - 1) * $limit;
            $paged = array_slice($unique, $offset, $limit);
            $paginator = new LengthAwarePaginator(
                $paged,
                $total,
                $limit,
                $page,
                ['path' => LengthAwarePaginator::resolveCurrentPath()]
            );

            return TemplateRouteResource::collection($paginator)->response()->getData(true);
        }

        return TemplateRouteResource::collection(
            $this->templateRouteRepository->listing($filters, $order, $limit, $page)
        )->response()->getData(true);
    }

    public function getOptions(
        array $filters = [],
        array $order = [],
        int $limit = 10,
        int $page = 1,
        bool $withWorkOrdersCount = false
    ): array
    {
        return TemplateRouteOptionResource::collection(
            $this->templateRouteRepository->options(
                $filters,
                $order,
                $limit,
                $page,
                $withWorkOrdersCount
            )
        )->response()->getData(true);
    }
    public function getTopUsed(array $filters = [], int $limit = 5): array
    {
        $query = WorkOrder::query()
            ->leftJoin('template_routes', 'template_routes.id', '=', 'work_orders.template_route_id')
            ->whereNotNull('work_orders.template_route_id')
            ->select(
                'work_orders.template_route_id',
                'template_routes.template',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('work_orders.template_route_id', 'template_routes.template');

        if ($dueFrom = Arr::get($filters, 'production_due_from')) {
            $query->whereDate('work_orders.production_due_date', '>=', $dueFrom);
        }
        if ($dueTo = Arr::get($filters, 'production_due_to')) {
            $query->whereDate('work_orders.production_due_date', '<=', $dueTo);
        }

        if ($statusFilter = Arr::get($filters, 'status')) {
            $query->where('work_orders.status', $statusFilter);
        }

        $scheduleFrom = Arr::get($filters, 'schedule_from');
        $scheduleTo = Arr::get($filters, 'schedule_to');
        if ($scheduleFrom || $scheduleTo) {
            $query->where(function ($range) use ($scheduleFrom, $scheduleTo) {
                if ($scheduleTo) {
                    $range->whereDate('work_orders.order_date', '<=', $scheduleTo);
                }
                if ($scheduleFrom) {
                    $range->where(function ($overlap) use ($scheduleFrom) {
                        $overlap->whereDate('work_orders.production_due_date', '>=', $scheduleFrom)
                            ->orWhereDate('work_orders.order_date', '>=', $scheduleFrom);
                    });
                }
            });
        }

        return $query
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(static function ($row): array {
                return [
                    'template_route_id' => (int) $row->template_route_id,
                    'template' => $row->template ?: ('Template #' . $row->template_route_id),
                    'work_orders' => (int) $row->total,
                ];
            })
            ->values()
            ->all();
    }
    public function detail(int $id): array
    {
        return (new TemplateRouteResource($this->templateRouteRepository->findById($id)))->response()->getData(true);
    }

    public function create(array $data): array
    {
        $data = $this->prepareVersionedPayload($data);
        $data['uuid'] = $data['uuid'] ?? (string) Str::uuid();
        $templateRoute = $this->templateRouteRepository->create($data);
        $this->activateVersion($templateRoute);

        return (new TemplateRouteResource($templateRoute->load('manager')))->response()->getData(true);
    }

    public function update(int $id, array $data): array
    {
        $data = $this->prepareVersionedPayload($data, $id, false);
        $updated = (bool) $this->templateRouteRepository->update($id, $data);

        if (! $updated) {
            return [];
        }

        $templateRoute = $this->templateRouteRepository->findById($id)->load('manager');

        return (new TemplateRouteResource($templateRoute))->response()->getData(true);
    }

    public function createVersion(int $id, array $data): array
    {
        $existing = $this->templateRouteRepository->findById($id);
        $base = $existing->toArray();
        unset($base['id'], $base['created_at'], $base['updated_at']);

        $payload = array_merge($base, $data, [
            'uuid' => (string) Str::uuid(),
            'parent_template_route_id' => $existing->parent_template_route_id ?: $existing->id,
            'created_from_template_route_id' => $existing->id,
            'user_id' => Arr::get($data, 'user_id', $existing->user_id),
        ]);
        $payload = $this->prepareVersionedPayload($payload, null, true);

        $created = $this->templateRouteRepository->create($payload);
        $this->activateVersion($created);

        return (new TemplateRouteResource($created->load('manager')))->response()->getData(true);
    }

    public function delete(int $id): bool
    {
        return $this->templateRouteRepository->delete($id);
    }

    public function importTemplates(array $templates, int $userId): array
    {
        $deduped = $this->dedupeTemplates($templates);
        $created = 0;
        $result = [];

        foreach ($deduped as $sequenceKey => $template) {
            $templateName = $template['template'] ?: $this->labelFromSequence($sequenceKey);
            $templateName = Str::limit($templateName, 250, '');
            $customerPartNumberRefs = $this->resolveCustomerPartNumberRefs($template);
            $batchNumber = Arr::get($template, 'batch_number');
            $sheet = Arr::get($template, 'sheet');
            $targetParts = !empty($customerPartNumberRefs)
                ? $customerPartNumberRefs
                : [null];

            foreach ($targetParts as $customerPartNo) {
                $payload = [
                    'template' => $templateName,
                    'metadata' => $template['metadata'] ?? [],
                    'customer_part_number_ref' => $customerPartNo,
                    'customer_part_no' => $customerPartNo,
                    'batch_number' => $batchNumber,
                    'sheet' => $sheet,
                    'user_id' => $userId,
                    'uuid' => (string) Str::uuid(),
                ];
                $payload = $this->prepareVersionedPayload($payload);

                $createdModel = $this->templateRouteRepository->create($payload);
                $this->activateVersion($createdModel);
                $createdModel->load('manager');
                $result[] = (new TemplateRouteResource($createdModel))->resolve();
                $created++;
            }
        }

        return [
            'created' => $created,
            'updated' => 0,
            'total' => $created,
            'templates' => $result,
        ];
    }

    public function listOrderedByWorkOrders(int $limit = 10, int $page = 1): array
    {
        $routes = $this->templateRouteRepository->orderedByWorkOrders($limit, $page);

        return TemplateRouteResource::collection($routes)
            ->response()
            ->getData(true);
    }

    public function replaceBatch(string $batchNumber, array $templates): array
    {
        $created = [];

        foreach ($templates as $template) {
            $customerPartNumberRefs = $this->resolveCustomerPartNumberRefs($template);
            $customerPartNumberRef = !empty($customerPartNumberRefs)
                ? $this->stringifyRefs($customerPartNumberRefs)
                : null;
            $payload = [
                'template' => $template['template'],
                'wod_ref' => $template['wod_ref'] ?? null,
                'customer_part_number_ref' => $customerPartNumberRef,
                'customer_part_no' => $template['customer_part_no'] ?? $this->resolvePrimaryCustomerPartNo($customerPartNumberRefs, $customerPartNumberRef),
                'batch_number' => $batchNumber,
                'sheet' => $template['sheet'] ?? null,
                'user_id' => $template['user_id'],
                'metadata' => $template['metadata'] ?? [],
                'uuid' => $template['uuid'] ?? (string) Str::uuid(),
            ];
            $payload = $this->prepareVersionedPayload($payload);

            $model = $this->templateRouteRepository->create($payload);
            $this->activateVersion($model);
            $model->load('manager');
            $created[] = (new TemplateRouteResource($model))->resolve();
        }

        return [
            'batch_number' => $batchNumber,
            'deleted' => 0,
            'created' => count($created),
            'templates' => $created,
        ];
    }

    public function listVersionsByCustomerPartNo(string $customerPartNo, bool $latestOnly = false): array
    {
        if ($latestOnly) {
            $latest = $this->templateRouteRepository->findLatestActiveByCustomerPartNo($customerPartNo);

            if (!$latest) {
                return [];
            }

            $latest->loadMissing('manager');

            return [(new TemplateRouteResource($latest))->resolve()];
        }

        $versions = $this->templateRouteRepository->listVersionsByCustomerPartNo($customerPartNo);

        return TemplateRouteResource::collection($versions)->resolve();
    }

    protected function dedupeTemplates(array $templates): array
    {
        $map = [];

        foreach ($templates as $template) {
            $metadata = Arr::get($template, 'metadata', []);
            if (!is_array($metadata) || empty($metadata)) {
                continue;
            }

            $sequenceKey = Arr::get($template, 'sequence');
            if (empty($sequenceKey)) {
                $sequenceKey = $this->buildSequenceKey($metadata);
            }

            if ($sequenceKey === '') {
                $sequenceKey = (string) Str::uuid();
            }

            $customerPartNumberRefs = $this->resolveCustomerPartNumberRefs($template);

            if (!isset($map[$sequenceKey])) {
                $map[$sequenceKey] = [
                    'template' => Arr::get($template, 'template') ?: $this->labelFromSequence($sequenceKey),
                    'metadata' => $metadata,
                    'customer_part_number_refs' => $customerPartNumberRefs,
                    'batch_number' => Arr::get($template, 'batch_number'),
                    'sheet' => Arr::get($template, 'sheet'),
                ];

                continue;
            }

            $map[$sequenceKey]['customer_part_number_refs'] = $this->mergeRefs(
                $map[$sequenceKey]['customer_part_number_refs'],
                $customerPartNumberRefs
            );

            if (empty($map[$sequenceKey]['template']) && !empty($template['template'])) {
                $map[$sequenceKey]['template'] = $template['template'];
            }

            if (empty($map[$sequenceKey]['metadata']) && !empty($metadata)) {
                $map[$sequenceKey]['metadata'] = $metadata;
            }

            if (empty($map[$sequenceKey]['batch_number']) && !empty($template['batch_number'])) {
                $map[$sequenceKey]['batch_number'] = $template['batch_number'];
            }

            if (empty($map[$sequenceKey]['sheet']) && !empty($template['sheet'])) {
                $map[$sequenceKey]['sheet'] = $template['sheet'];
            }
        }

        return $map;
    }

    protected function dedupeByRouteNameSequence($templates): array
    {
        $unique = [];
        $seen = [];

        foreach ($templates as $template) {
            $sequenceKey = (string) ($template->route_name_sequence_key ?? '');
            $uniqueKey = $sequenceKey !== '' ? $sequenceKey : "id:{$template->id}";
            if (isset($seen[$uniqueKey])) {
                continue;
            }
            $seen[$uniqueKey] = true;
            $unique[] = $template;
        }

        return $unique;
    }

    protected function buildSequenceKey(array $metadata): string
    {
        $parts = [];

        // Support new nested structure: [{workOrderLineNo, routes: [...]}, ...]
        foreach ($metadata as $block) {
            $routes = Arr::get($block, 'routes');
            if (is_array($routes)) {
                foreach ($routes as $route) {
                    $label = $this->normalizeStepLabel($route);
                    if ($label !== '') {
                        $parts[] = $label;
                    }
                }
            } else {
                $label = $this->normalizeStepLabel($block);
                if ($label !== '') {
                    $parts[] = $label;
                }
            }
        }

        return implode('-', $parts);
    }

    protected function normalizeStepLabel(array $route): string
    {
        $machine = Arr::get($route, 'machine', []);
        $metaMachine = Arr::get($route, 'metadata.machine', []);

        $label = Arr::get($machine, 'type')
            ?? Arr::get($machine, 'machine_type')
            ?? Arr::get($metaMachine, 'type')
            ?? Arr::get($metaMachine, 'machine_type')
            ?? Arr::get($route, 'name', '');

        $label = strtoupper(preg_replace('/[^A-Z0-9]+/i', '-', (string) $label));

        return trim($label, '-');
    }

    protected function labelFromSequence(string $sequenceKey): string
    {
        $normalized = strtoupper(str_replace(['>', '_', '|'], '-', $sequenceKey));
        $normalized = preg_replace('/-+/', '-', $normalized ?? '');
        $parts = array_values(array_filter(explode('-', (string) $normalized)));

        if (empty($parts)) {
            return 'TEMPLATE-' . Str::upper(Str::random(6));
        }

        $head = array_slice($parts, 0, 3);
        $remaining = max(count($parts) - count($head), 0);
        $base = implode('-', $head);
        $suffix = $remaining > 0 ? "+{$remaining} MORE" : '';
        $hash = substr(hash('crc32b', $sequenceKey), 0, 6);

        $label = trim("{$base}{$suffix}-{$hash}", '-');

        return Str::limit($label, 80, '');
    }

    protected function normalizeRefs(array ...$sources): array
    {
        $refs = [];

        foreach ($sources as $source) {
            foreach ($source as $value) {
                $value = strtoupper(trim((string) $value));
                if ($value !== '') {
                    $refs[] = $value;
                }
            }
        }

        return array_values(array_unique($refs));
    }

    protected function resolveCustomerPartNumberRefs(array $template): array
    {
        return $this->normalizeRefs(
            Arr::get($template, 'customer_part_number_refs', []),
            Arr::get($template, 'customer_part_numbers', []),
            $this->splitRefs((string) Arr::get($template, 'customer_part_number_ref', ''))
        );
    }

    protected function mergeRefs(array ...$sources): array
    {
        return $this->normalizeRefs(...$sources);
    }

    protected function splitRefs(string $wodRef): array
    {
        if ($wodRef === '') {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', $wodRef) ?: [];

        return array_values(array_filter(
            array_map(
                static fn ($part) => strtoupper(trim((string) $part)),
                $parts
            )
        ));
    }

    protected function stringifyRefs(array $refs): string
    {
        $normalized = $this->normalizeRefs($refs);

        return implode(', ', $normalized);
    }

    protected function prepareVersionedPayload(array $data, ?int $currentId = null, bool $forceNewVersion = true): array
    {
        $refs = $this->resolveCustomerPartNumberRefs($data);
        $primaryCustomerPartNo = $data['customer_part_no']
            ?? $this->resolvePrimaryCustomerPartNo($refs, (string) ($data['customer_part_number_ref'] ?? ''));

        if ($primaryCustomerPartNo !== null) {
            $data['customer_part_no'] = $primaryCustomerPartNo;
            $data['customer_part_number_ref'] = $data['customer_part_number_ref'] ?? $primaryCustomerPartNo;
        }

        if (!$primaryCustomerPartNo) {
            return $data;
        }

        if ($forceNewVersion || empty($data['template_route_version'])) {
            $data['template_route_version'] = $this->nextTemplateRouteVersion($primaryCustomerPartNo, $currentId);
        }

        if (!array_key_exists('is_active', $data)) {
            $data['is_active'] = true;
        }

        return $data;
    }

    protected function resolvePrimaryCustomerPartNo(array $refs, string $fallbackRef = ''): ?string
    {
        $normalized = $this->normalizeRefs($refs, $this->splitRefs($fallbackRef));
        if (empty($normalized)) {
            return null;
        }

        sort($normalized, SORT_STRING);

        return $normalized[0];
    }

    protected function nextTemplateRouteVersion(string $customerPartNo, ?int $exceptId = null): int
    {
        $query = TemplateRoute::query()->where('customer_part_no', $customerPartNo);
        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }

        return (int) $query->max('template_route_version') + 1;
    }

    protected function activateVersion(TemplateRoute $templateRoute): void
    {
        $customerPartNo = strtoupper(trim((string) ($templateRoute->customer_part_no ?? '')));
        if ($customerPartNo === '') {
            return;
        }

        TemplateRoute::query()
            ->where('customer_part_no', $customerPartNo)
            ->where('id', '!=', $templateRoute->id)
            ->update(['is_active' => false]);

        if (!$templateRoute->is_active) {
            $templateRoute->is_active = true;
            $templateRoute->save();
        }
    }
}
