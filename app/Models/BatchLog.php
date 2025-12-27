<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BatchLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_no',
        'total_rows',
        'operator',
    ];
}
