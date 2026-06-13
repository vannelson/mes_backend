<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class QualityIssue extends Model
{
    use HasFactory;

    protected $fillable = [
        'issue_type',
        'serial_no',
        'date_issue',
        'month_label',
        'tracking_number',
        'external_tracking_number',
        'customer_id',
        'supplier_id',
        'customer_name',
        'supplier_name',
        'severity',
        'work_order_id',
        'work_order_no',
        'part_number',
        'part_name',
        'material_code',
        'material_name',
        'lot_number',
        'problem_statement',
        'reject_rate',
        'pic',
        'status',
        'closure_date',
        'target_acknowledgement_date',
        'actual_acknowledgement_date',
        'kpi_days',
        'target_closure_date',
        'actual_tat_days',
        'root_cause',
        'immediate_action',
        'corrective_action',
        'preventive_action',
        'remarks',
        'comment',
        'type_label',
        'metadata',
    ];

    protected $casts = [
        'date_issue' => 'date',
        'closure_date' => 'date',
        'target_acknowledgement_date' => 'date',
        'actual_acknowledgement_date' => 'date',
        'target_closure_date' => 'date',
        'metadata' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function followUpLots(): HasMany
    {
        return $this->hasMany(QualityFollowUpLot::class)->orderBy('sequence_no');
    }

    public function eightDReports(): HasMany
    {
        return $this->hasMany(EightDReport::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(QualityAttachment::class, 'attachable');
    }
}
