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
        $routes = $this->flattenRoutes($this->metadata);
        if (empty($routes)) {
            return '';
        }

        $parts = [];
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }
            $label = $this->normalizeRouteLabel($route);
            if ($label !== '') {
                $parts[] = $label;
            }
        }

        return implode('-', $parts);
    }

    public function setMetadataAttribute($value): void
    {
        $this->attributes['metadata'] = is_array($value)
            ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $value;
    }

    protected function normalizeRouteLabel(array $route): string
    {
        $label = $route['name'] ?? $route['route'] ?? $route['key'] ?? $route['step'] ?? '';
        $canonical = $this->canonicalizeRouteLabel((string) $label);
        if ($canonical !== '') {
            return $this->normalizeRouteToken($canonical);
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
        $candidate = !empty($filtered) ? implode('-', $filtered) : str_replace(' ', '-', $fallback);

        return trim($candidate, '-');
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

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}
