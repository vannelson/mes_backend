<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderStepCompletion extends Model
{
    use HasFactory;

    protected $fillable = [
        'work_order_id',
        'step_key',
        'status',
        'payload',
        'completed_at',
        'completed_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'completed_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
