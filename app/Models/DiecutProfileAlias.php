<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiecutProfileAlias extends Model
{
    use HasFactory;

    protected $fillable = [
        'diecut_profile_id',
        'alias_code',
        'normalized_alias',
        'base_normalized_alias',
        'alias_type',
        'confidence_score',
        'metadata',
    ];

    protected $casts = [
        'confidence_score' => 'float',
        'metadata' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(DiecutProfile::class, 'diecut_profile_id');
    }
}
