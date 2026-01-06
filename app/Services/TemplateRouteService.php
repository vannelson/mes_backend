<?php

namespace App\Services;

use App\Http\Resources\TemplateRoute\TemplateRouteResource;
use App\Repositories\Contracts\TemplateRouteRepositoryInterface;
use App\Services\Contracts\TemplateRouteServiceInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class TemplateRouteService implements TemplateRouteServiceInterface
{
    public function __construct(
        protected TemplateRouteRepositoryInterface $templateRouteRepository
    ) {
    }

    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        return TemplateRouteResource::collection(
            $this->templateRouteRepository->listing($filters, $order, $limit, $page)
        )->response()->getData(true);
    }

    public function detail(int $id): array
    {
        return (new TemplateRouteResource($this->templateRouteRepository->findById($id)))->response()->getData(true);
    }

    public function create(array $data): array
    {
        $data['uuid'] = $data['uuid'] ?? (string) Str::uuid();
        $templateRoute = $this->templateRouteRepository->create($data);

        return (new TemplateRouteResource($templateRoute->load('manager')))->response()->getData(true);
    }

    public function update(int $id, array $data): array
    {
        $updated = (bool) $this->templateRouteRepository->update($id, $data);

        if (! $updated) {
            return [];
        }

        $templateRoute = $this->templateRouteRepository->findById($id)->load('manager');

        return (new TemplateRouteResource($templateRoute))->response()->getData(true);
    }

    public function delete(int $id): bool
    {
        return $this->templateRouteRepository->delete($id);
    }

    public function importTemplates(array $templates, int $userId): array
    {
        $deduped = $this->dedupeTemplates($templates);
        $created = 0;
        $updated = 0;
        $result = [];

        foreach ($deduped as $sequenceKey => $template) {
            $templateName = $template['template'] ?: $this->labelFromSequence($sequenceKey);
            $templateName = Str::limit($templateName, 250, '');
            $wodRefs = $this->stringifyRefs($template['wod_refs'] ?? []);

            $payload = [
                'template' => $templateName,
                'metadata' => $template['metadata'] ?? [],
                'wod_ref' => $wodRefs,
                'user_id' => $userId,
            ];

            $existing = $this->templateRouteRepository->findByTemplate($templateName);

            if ($existing) {
                $mergedRefs = $this->mergeRefs(
                    $this->splitRefs((string) $existing->wod_ref),
                    $template['wod_refs'] ?? []
                );

                $updatePayload = $payload;
                $updatePayload['wod_ref'] = $this->stringifyRefs($mergedRefs);
                $updatePayload['user_id'] = $existing->user_id ?: $userId;

                $this->templateRouteRepository->update($existing->id, $updatePayload);
                $model = $this->templateRouteRepository->findById($existing->id)->load('manager');
                $result[] = (new TemplateRouteResource($model))->resolve();
                $updated++;
            } else {
                $payload['uuid'] = (string) Str::uuid();
                $createdModel = $this->templateRouteRepository->create($payload)->load('manager');
                $result[] = (new TemplateRouteResource($createdModel))->resolve();
                $created++;
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'total' => count($deduped),
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

            $wodRefs = $this->normalizeRefs(
                Arr::get($template, 'wod_refs', []),
                Arr::get($template, 'work_orders', []),
                $this->splitRefs((string) Arr::get($template, 'wod_ref', ''))
            );

            if (!isset($map[$sequenceKey])) {
                $map[$sequenceKey] = [
                    'template' => Arr::get($template, 'template') ?: $this->labelFromSequence($sequenceKey),
                    'metadata' => $metadata,
                    'wod_refs' => $wodRefs,
                ];

                continue;
            }

            $map[$sequenceKey]['wod_refs'] = $this->mergeRefs($map[$sequenceKey]['wod_refs'], $wodRefs);

            if (empty($map[$sequenceKey]['template']) && !empty($template['template'])) {
                $map[$sequenceKey]['template'] = $template['template'];
            }

            if (empty($map[$sequenceKey]['metadata']) && !empty($metadata)) {
                $map[$sequenceKey]['metadata'] = $metadata;
            }
        }

        return $map;
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
}
