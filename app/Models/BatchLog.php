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
        'total_rows',
        'operator',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
