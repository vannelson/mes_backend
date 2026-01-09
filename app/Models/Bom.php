<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bom extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_number',
        'sheet',
        'customer_code',
        'part_no',
        'description',
        'material_1_code',
        'material_1_desc',
        'material_2_code',
        'material_2_desc',
        'material_3_code',
        'material_3_desc',
        'material_4_code',
        'material_4_desc',
        'colour_code_1',
        'colour_code_2',
        'colour_code_3',
        'colour_code_4',
    ];
}
