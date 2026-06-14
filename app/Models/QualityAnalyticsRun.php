<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QualityAnalyticsRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'scope',
        'engine_name',
        'engine_version',
        'status',
        'requested_by_user_id',
        'started_at',
        'completed_at',
        'filters',
        'summary_metrics',
        'capability_results',
        'rule_summary',
        'metadata',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'filters' => 'array',
        'summary_metrics' => 'array',
        'capability_results' => 'array',
        'rule_summary' => 'array',
        'metadata' => 'array',
    ];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function charts(): HasMany
    {
        return $this->hasMany(QualityAnalyticsChart::class);
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
