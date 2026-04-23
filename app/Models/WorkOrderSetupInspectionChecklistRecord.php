<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkOrderSetupInspectionChecklistRecord extends Model
{
    protected $table = 'work_order_setup_inspection_checklist_records';

    protected $fillable = [
        'work_order_no',
        'route_key',
        'route_name',
        'machine_id',
        'machine_key',
        'machine_type',
        'machine_no',
        'machine_label',
        'record_date',
        'slot',
        'entries',
        'save_count',
        'is_locked',
        'locked_reason',
        'locked_by',
        'locked_at',
        'unlocked_by',
        'unlocked_at',
        'approval_status',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'entries' => 'array',
        'record_date' => 'date:Y-m-d',
        'locked_at' => 'datetime',
        'unlocked_at' => 'datetime',
        'approved_at' => 'datetime',
        'is_locked' => 'boolean',
    ];
}

