<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperationTrigger extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'status',
        'tags',
        'rule',
        'loop',
        'schedule',
        'actions',
        'flow',
        'cooldown',
        'debounce',
        'version',
        'last_fired_at',
        'is_active',
        'versions',
        'audit',
        'executions',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tags' => 'array',
        'rule' => 'array',
        'loop' => 'array',
        'schedule' => 'array',
        'actions' => 'array',
        'flow' => 'array',
        'cooldown' => 'array',
        'debounce' => 'array',
        'versions' => 'array',
        'audit' => 'array',
        'executions' => 'array',
        'version' => 'integer',
        'is_active' => 'boolean',
        'last_fired_at' => 'datetime',
    ];
}
