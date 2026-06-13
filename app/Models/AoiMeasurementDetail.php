<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AoiMeasurementDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'aoi_measurement_header_id',
        'measurement_characteristic_spec_id',
        'characteristic_code',
        'characteristic_name',
        'numeric_value',
        'raw_value',
        'units',
        'nominal_value',
        'lower_spec_limit',
        'upper_spec_limit',
        'is_out_of_spec',
        'metadata',
    ];

    protected $casts = [
        'numeric_value' => 'decimal:6',
        'nominal_value' => 'decimal:6',
        'lower_spec_limit' => 'decimal:6',
        'upper_spec_limit' => 'decimal:6',
        'is_out_of_spec' => 'boolean',
        'metadata' => 'array',
    ];

    public function header(): BelongsTo
    {
        return $this->belongsTo(AoiMeasurementHeader::class, 'aoi_measurement_header_id');
    }

    public function spec(): BelongsTo
    {
        return $this->belongsTo(MeasurementCharacteristicSpec::class, 'measurement_characteristic_spec_id');
    }
}
