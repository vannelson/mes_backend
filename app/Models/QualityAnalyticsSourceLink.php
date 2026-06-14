<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityAnalyticsSourceLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'quality_analytics_run_id',
        'quality_analytics_chart_id',
        'source_module',
        'source_type',
        'source_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(QualityAnalyticsRun::class, 'quality_analytics_run_id');
    }

    public function chart(): BelongsTo
    {
        return $this->belongsTo(QualityAnalyticsChart::class, 'quality_analytics_chart_id');
    }
}
