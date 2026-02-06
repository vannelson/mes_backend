<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TemplateRoute extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'template',
        'wod_ref',
        'customer_part_number_ref',
        'batch_number',
        'sheet',
        'user_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function getRouteNameSequenceKeyAttribute(): string
    {
        $metadata = $this->metadata;
        if (is_array($metadata) && isset($metadata['sequence']) && is_string($metadata['sequence'])) {
            $sequence = trim($metadata['sequence']);
            if ($sequence !== '') {
                return $sequence;
            }
        }

        $routes = $this->flattenRoutes($metadata);
        if (empty($routes)) {
            return '';
        }

        $parts = [];
        $seen = [];
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }
            $label = $this->normalizeRouteLabel($route);
            if ($label !== '') {
                $key = strtoupper($label);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $parts[] = $label;
            }
        }

        return implode('->', $parts);
    }

    public function setMetadataAttribute($value): void
    {
        $this->attributes['metadata'] = is_array($value)
            ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $value;
    }

    public function getRouteSequenceWithMachinesAttribute(): string
    {
        $metadata = $this->metadata;
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        $lines = [];
        if (is_array($metadata) && array_key_exists('routes', $metadata) && is_array($metadata['routes'])) {
            $lines = $metadata['routes'];
        } elseif (is_array($metadata)) {
            $lines = $metadata;
        }

        $stepTypes = [];
        $machineCounts = [];

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $routes = [];
            if (array_key_exists('routes', $line) && is_array($line['routes'])) {
                $routes = $line['routes'];
            } else {
                $routes = [$line];
            }

            if (!empty($routes) && is_array($routes[0]) && isset($routes[0]['order_seq'])) {
                usort($routes, static function ($a, $b) {
                    return ((int) ($a['order_seq'] ?? 0)) <=> ((int) ($b['order_seq'] ?? 0));
                });
            }

            $stepIndex = 0;
            foreach ($routes as $route) {
                if (!is_array($route)) {
                    $stepIndex++;
                    continue;
                }
                $type = $this->normalizeRouteLabel($route);
                if ($type === '') {
                    $stepIndex++;
                    continue;
                }
                $stepTypes[$stepIndex] = $stepTypes[$stepIndex] ?? $type;
                $machineName = $this->resolveMachineName($route);
                if ($machineName !== '') {
                    $machineCounts[$stepIndex] = $machineCounts[$stepIndex] ?? [];
                    $machineCounts[$stepIndex][$machineName] = ($machineCounts[$stepIndex][$machineName] ?? 0) + 1;
                }
                $stepIndex++;
            }
        }

        if (empty($stepTypes)) {
            return '';
        }

        $parts = [];
        foreach ($stepTypes as $index => $type) {
            $machine = $this->pickCanonicalMachine($machineCounts[$index] ?? []);
            $parts[] = $machine !== '' ? "{$type}[{$machine}]" : $type;
        }

        return implode('->', $parts);
    }

    protected function normalizeRouteLabel(array $route): string
    {
        $label = $route['name'] ?? $route['route'] ?? $route['key'] ?? $route['step'] ?? '';
        $canonical = $this->canonicalizeRouteLabel((string) $label);
        if ($canonical !== '') {
            return $canonical;
        }

        $fallback = strtoupper(preg_replace('/[^A-Z0-9]+/i', ' ', (string) $label));
        $fallback = trim(preg_replace('/\s+/', ' ', $fallback));
        if ($fallback === '') {
            return '';
        }

        $tokens = preg_split('/\s+/', $fallback) ?: [];
        $filtered = array_values(array_filter(
            $tokens,
            static fn ($token) => !preg_match('/[0-9]/', (string) $token)
        ));
        $candidate = !empty($filtered) ? implode(' ', $filtered) : $fallback;

        return trim($candidate);
    }

    protected function canonicalizeRouteLabel(string $value): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9()]+/i', ' ', $value));
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized));

        if ($normalized === '') {
            return '';
        }

        if (preg_match('/DIE\s*CUT\s*\(\s*D\s*\)/', $normalized)
            || preg_match('/DIE\s*CUT\s+\bD\b/', $normalized)) {
            return 'DIE-CUT (D)';
        }
        if (preg_match('/DIE\s*CUT\s*\(\s*L\s*\)/', $normalized)
            || preg_match('/DIE\s*CUT\s+\bL\b/', $normalized)) {
            return 'DIE-CUT (L)';
        }
        if (preg_match('/DIE\s*CUT/', $normalized)) {
            return 'DIE-CUT';
        }
        if (preg_match('/\bFLEXO\b/', $normalized)) {
            return 'FLEXO';
        }
        if (preg_match('/\bDIGITAL\b/', $normalized)) {
            return 'DIGITAL';
        }
        if (preg_match('/\bLP\b/', $normalized)) {
            return 'LP';
        }
        if (preg_match('/\bAOI\b/', $normalized)) {
            return 'AOI';
        }
        if (preg_match('/\bINSPECTION\b/', $normalized)) {
            return 'INSPECTION';
        }
        if (preg_match('/\bSLITTING\b/', $normalized)) {
            return 'SLITTING';
        }

        return '';
    }

    protected function normalizeRouteToken(string $label): string
    {
        $label = strtoupper(preg_replace('/[^A-Z0-9]+/i', '-', $label));

        return trim($label, '-');
    }

    protected function flattenRoutes(mixed $metadata): array
    {
        $raw = $metadata;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        if (array_key_exists('routes', $raw) && is_array($raw['routes'])) {
            $raw = $raw['routes'];
        } elseif (array_key_exists('data', $raw) && is_array($raw['data'])) {
            $raw = $raw['data'];
        } elseif (array_key_exists('steps', $raw) && is_array($raw['steps'])) {
            $raw = $raw['steps'];
        }

        $routes = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (array_key_exists('routes', $entry) && is_array($entry['routes'])) {
                foreach ($entry['routes'] as $route) {
                    if (is_array($route)) {
                        $routes[] = $route;
                    }
                }
                continue;
            }
            $routes[] = $entry;
        }

        return $routes;
    }

    protected function resolveMachineName(array $route): string
    {
        $machine = $route['machine'] ?? $route['metadata']['machine'] ?? null;
        if (is_array($machine)) {
            $name = trim((string) ($machine['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
            $label = trim((string) ($machine['label'] ?? ''));
            if ($label !== '') {
                return $label;
            }
            $code = trim((string) ($machine['code'] ?? ''));
            if ($code !== '') {
                return $code;
            }
        }

        $name = trim((string) ($route['machine_name'] ?? $route['metadata']['machine_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return '';
    }

    protected function pickCanonicalMachine(array $counts): string
    {
        if (empty($counts)) {
            return '';
        }

        $max = max($counts);
        $candidates = array_keys(array_filter(
            $counts,
            static fn ($count) => $count === $max
        ));

        if (empty($candidates)) {
            return '';
        }

        sort($candidates, SORT_STRING);

        return $candidates[0];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}
