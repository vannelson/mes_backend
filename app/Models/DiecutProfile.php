<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiecutProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_code',
        'normalized_code',
        'base_normalized_code',
        'diecut_type',
        'height_mm',
        'width_mm',
        'interval_ud_mm',
        'interval_lr_mm',
        'column_count',
        'no_of_ups',
        'default_tool_life_pcs',
        'default_tool_life_press',
        'rev',
        'status',
        'source_sheet',
        'source_batch',
        'metadata',
    ];

    protected $casts = [
        'height_mm' => 'float',
        'width_mm' => 'float',
        'interval_ud_mm' => 'float',
        'interval_lr_mm' => 'float',
        'column_count' => 'float',
        'no_of_ups' => 'float',
        'default_tool_life_pcs' => 'float',
        'default_tool_life_press' => 'float',
        'metadata' => 'array',
    ];

    public function aliases(): HasMany
    {
        return $this->hasMany(DiecutProfileAlias::class);
    }

    public function customerPartMappings(): HasMany
    {
        return $this->hasMany(CustomerPartDiecutProfile::class);
    }

    public function tools(): HasMany
    {
        return $this->hasMany(DiecutTool::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(DiecutToolUsage::class);
    }
}
