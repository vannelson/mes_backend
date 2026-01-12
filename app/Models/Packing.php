<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Packing extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'wd_part_no',
        'material',
        'description',
        'batch_number',
        'image',
        'design',
        'shipping_location',
        'customer_code',
        'box_size',
        'qty_per_box',
        'rolls_per_box',
        'core_label_left',
        'core_label_right',
        'hm_no',
        'ul_label_no',
        'cas',
        'important',
        'code_1',
        'underline_code',
        'colour_code',
        'wd_revision',
        'revised_by_pic',
        'date_of_revised',
        'remarks',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'date_of_revised' => 'date',
    ];
}
