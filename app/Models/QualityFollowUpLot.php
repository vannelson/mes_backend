<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityFollowUpLot extends Model
{
    use HasFactory;

    protected $fillable = [
        'quality_issue_id',
        'sequence_no',
        'label',
        'work_order_no',
        'lot_number',
        'result_status',
        'remarks',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function qualityIssue(): BelongsTo
    {
        return $this->belongsTo(QualityIssue::class);
    }
}
