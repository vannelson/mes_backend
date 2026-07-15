<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CalibrationMaster extends Model
{
    use HasFactory;

    protected $fillable = [
        'sheet_name',
        'sheet_order',
        'source_row',
        'reference_no',
        'supplier_vendor',
        'purchase_date',
        'calibration_type',
        'reminder_days',
        'is_active',
        'remarks',
        'name_type',
        'function',
        'image',
        'identification_number',
        'measurement_range',
        'inherent_accuracy',
        'usage_accuracy',
        'owner_location',
        'frequency_label',
        'frequency_interval_months',
        'last_calibration_date',
        'next_calibration_date',
        'metadata',
    ];

    protected $casts = [
        'sheet_order' => 'integer',
        'source_row' => 'integer',
        'purchase_date' => 'date',
        'reminder_days' => 'integer',
        'is_active' => 'boolean',
        'frequency_interval_months' => 'integer',
        'last_calibration_date' => 'date',
        'next_calibration_date' => 'date',
        'metadata' => 'array',
    ];

    public function images(): HasMany
    {
        return $this->hasMany(CalibrationMasterImage::class)->orderBy('sort_order');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(CalibrationMasterHistory::class)->orderByDesc('calibration_date')->orderByDesc('id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(QualityAttachment::class, 'attachable');
    }
}
