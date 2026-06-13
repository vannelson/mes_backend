<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class VpdClaim extends Model
{
    use HasFactory;

    protected $fillable = [
        'vpd_number',
        'claim_date',
        'supplier_id',
        'vendor_name',
        'material_code',
        'description',
        'defect_type',
        'sqm',
        'unit_price',
        'amount',
        'currency',
        'exchange_rate',
        'total_sgd',
        'car_completion_date',
        'remarks',
        'additional_reference',
        'status',
        'notes',
        'quality_issue_id',
        'metadata',
    ];

    protected $casts = [
        'claim_date' => 'date',
        'car_completion_date' => 'date',
        'unit_price' => 'decimal:4',
        'amount' => 'decimal:4',
        'exchange_rate' => 'decimal:6',
        'total_sgd' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function qualityIssue(): BelongsTo
    {
        return $this->belongsTo(QualityIssue::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(QualityAttachment::class, 'attachable');
    }
}
