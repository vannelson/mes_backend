<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'batch_no',
        'type',
        'total_rows',
        'inserted_rows',
        'updated_rows',
        'skipped_rows',
        'failed_rows',
        'operator',
        'sheet',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'inserted_rows' => 'integer',
        'updated_rows' => 'integer',
        'skipped_rows' => 'integer',
        'failed_rows' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
