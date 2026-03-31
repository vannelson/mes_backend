<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierChangeControlEvent extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'supplier_change_control_id',
        'action',
        'step',
        'note',
        'payload',
        'user_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'step' => 'integer',
        'payload' => 'array',
    ];

    public function supplierChangeControl(): BelongsTo
    {
        return $this->belongsTo(SupplierChangeControl::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

