<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalibrationMasterImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'calibration_master_id',
        'sort_order',
        'file_name',
        'file_path',
        'mime_type',
        'width',
        'height',
        'source_sheet_name',
        'source_row',
        'source_col',
        'metadata',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'source_row' => 'integer',
        'source_col' => 'integer',
        'metadata' => 'array',
    ];

    public function calibrationMaster(): BelongsTo
    {
        return $this->belongsTo(CalibrationMaster::class);
    }
}
