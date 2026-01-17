<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Diecut extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_number',
        'sheet',
        'diecut_no',
        'diecut_type',
        'width',
        'length',
        'no_of_ups',
        'rev',
        'radius',
        'perforate',
        'int_ud',
    ];
}
