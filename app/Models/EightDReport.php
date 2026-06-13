<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class EightDReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'quality_issue_id',
        'report_number',
        'tracking_number',
        'date_issue',
        'assigned_to_user_id',
        'assigned_to_name',
        'severity',
        'status',
        'ack_due_at',
        'd3_due_at',
        'd4_due_at',
        'd5_due_at',
        'd8_due_at',
        'closure_due_at',
        'closed_at',
        'metadata',
    ];

    protected $casts = [
        'date_issue' => 'date',
        'ack_due_at' => 'datetime',
        'd3_due_at' => 'datetime',
        'd4_due_at' => 'datetime',
        'd5_due_at' => 'datetime',
        'd8_due_at' => 'datetime',
        'closure_due_at' => 'datetime',
        'closed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function qualityIssue(): BelongsTo
    {
        return $this->belongsTo(QualityIssue::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(EightDStep::class)->orderBy('step_code');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(QualityAttachment::class, 'attachable');
    }
}
