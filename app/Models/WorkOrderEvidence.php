<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkOrderEvidence extends Model
{
    use HasFactory;

    protected $table = 'work_order_evidences';

    protected $fillable = [
        'work_order_no',
        'route_name',
        'image_path',
        'original_name',
    ];
}
