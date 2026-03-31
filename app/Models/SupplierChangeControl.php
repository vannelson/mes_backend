<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierChangeControl extends Model
{
    use HasFactory;

    public const STEP_LABELS = [
        1 => 'Change request initiation',
        2 => 'Change request assessment',
        3 => 'Change request analysis',
        4 => 'Change request implementation',
        5 => 'Change request closure',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'supplier_name',
        'address',
        'tel_fax',
        'status',
        'current_step',
        'notes',
        'attachment_path',
        'initiated_at',
        'assessed_at',
        'analyzed_at',
        'implemented_at',
        'closed_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'current_step' => 'integer',
        'initiated_at' => 'datetime',
        'assessed_at' => 'datetime',
        'analyzed_at' => 'datetime',
        'implemented_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupplierChangeControlEvent::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}

