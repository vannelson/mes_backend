<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserWorkOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'work_order_id',
        'user_id',
        'route_key',
        'route_code',
        'route_name',
        'order_seq',
        'assigned_qty',
    ];

    protected $casts = [
        'order_seq' => 'integer',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
