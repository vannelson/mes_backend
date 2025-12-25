<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Machine extends Model
{
    use HasFactory;

    protected $fillable = [
        'production_area',
        'machine_type',
        'printing_type',
        'machine_no',
        'cost_center_old',
        'cost_center_new',
        'average_speed',
        'max_colors',
        'die_cut_stations',
        'max_die_cut_width_mm',
        'max_repeat_length_mm',
        'max_material_width_mm',
        'diecut_type',
        'rotary',
        'varnish',
        'inline_slitting',
        'auto_inspection',
        'auto_inspection_slitting',
        'manual_inspection',
        'machine_counting',
        'manual_counting',
        'manual_slitting',
        'packing',
        'lamination',
        'units',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
