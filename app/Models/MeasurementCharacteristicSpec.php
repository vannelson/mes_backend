<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeasurementCharacteristicSpec extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'work_order_id',
        'part_number',
        'program_name',
        'characteristic_code',
        'characteristic_name',
        'nominal_value',
        'target_value',
        'lower_spec_limit',
        'upper_spec_limit',
        'lower_control_limit',
        'upper_control_limit',
        'units',
        'decimal_precision',
        'sampling_rule',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'nominal_value' => 'decimal:6',
        'target_value' => 'decimal:6',
        'lower_spec_limit' => 'decimal:6',
        'upper_spec_limit' => 'decimal:6',
        'lower_control_limit' => 'decimal:6',
        'upper_control_limit' => 'decimal:6',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
