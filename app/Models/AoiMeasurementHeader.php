<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AoiMeasurementHeader extends Model
{
    use HasFactory;

    protected $fillable = [
        'aoi_import_batch_id',
        'measurement_time',
        'result_status',
        'lot_number',
        'serial_counter',
        'operator_name',
        'roll_id',
        'machine_number',
        'computer_name',
        'program_name',
        'work_order_id',
        'work_order_no',
        'customer_id',
        'customer_name',
        'machine_id',
        'operator_user_id',
        'shift_name',
        'part_number',
        'batch_number',
        'error_code',
        'stop_reason',
        'is_reinspection',
        'metadata',
    ];

    protected $casts = [
        'measurement_time' => 'datetime',
        'is_reinspection' => 'boolean',
        'metadata' => 'array',
    ];

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(AoiImportBatch::class, 'aoi_import_batch_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(AoiMeasurementDetail::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(QualityAttachment::class, 'attachable');
    }
}
