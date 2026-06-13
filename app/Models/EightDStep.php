<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EightDStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'eight_d_report_id',
        'step_code',
        'title',
        'owner_user_id',
        'owner_name',
        'is_completed',
        'completed_by_user_id',
        'completed_at',
        'approval_status',
        'content',
        'remarks',
        'metadata',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(EightDReport::class, 'eight_d_report_id');
    }
}
