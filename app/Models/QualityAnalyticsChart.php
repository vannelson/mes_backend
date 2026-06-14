<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QualityAnalyticsChart extends Model
{
    use HasFactory;

    protected $fillable = [
        'quality_analytics_run_id',
        'module_key',
        'chart_key',
        'chart_type',
        'title',
        'image_path',
        'mime_type',
        'file_size',
        'is_spc',
        'filters',
        'series_payload',
        'stat_payload',
        'metadata',
    ];

    protected $casts = [
        'is_spc' => 'boolean',
        'filters' => 'array',
        'series_payload' => 'array',
        'stat_payload' => 'array',
        'metadata' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(QualityAnalyticsRun::class, 'quality_analytics_run_id');
    }

    public function ruleViolations(): HasMany
    {
        return $this->hasMany(QualityAnalyticsRuleViolation::class);
    }

    public function sourceLinks(): HasMany
    {
        return $this->hasMany(QualityAnalyticsSourceLink::class);
    }
}
