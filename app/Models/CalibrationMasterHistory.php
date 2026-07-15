<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalibrationMasterHistory extends Model
{
    protected $fillable = [
        'calibration_master_id',
        'calibration_date',
        'cert_no',
        'performed_by',
        'notes',
        'cert_file_path',
        'cert_file_name',
        'cert_mime_type',
        'logged_by',
    ];

    protected $casts = [
        'calibration_date' => 'date',
    ];

    public function calibrationMaster(): BelongsTo
    {
        return $this->belongsTo(CalibrationMaster::class);
    }

    public function loggedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}
