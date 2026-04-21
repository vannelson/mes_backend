<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiecutTool extends Model
{
    use HasFactory;

    protected $fillable = [
        'diecut_profile_id',
        'tool_code',
        'normalized_tool_code',
        'base_normalized_tool_code',
        'cavity',
        'tool_life_pcs',
        'tool_life_press',
        'status',
        'is_active',
        'received_date',
        'start_date',
        'return_date',
        'source_sheet',
        'source_batch',
        'remarks',
        'metadata',
    ];

    protected $casts = [
        'cavity' => 'float',
        'tool_life_pcs' => 'float',
        'tool_life_press' => 'float',
        'is_active' => 'boolean',
        'received_date' => 'date',
        'start_date' => 'date',
        'return_date' => 'date',
        'metadata' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(DiecutProfile::class, 'diecut_profile_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(DiecutToolUsage::class, 'diecut_tool_id');
    }
}
