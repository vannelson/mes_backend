<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PackingChecklist extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'work_order_no',
        'wd_part_no',
        'double_bag_checklist',
        'quantity_verification',
        'roll_per_box',
        'ul_label_image',
        'product_image',
        'core_image',
        'carton_label_data',
        'carton_label_image',
        'no_of_cartons',
        'user_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'double_bag_checklist' => 'array',
        'quantity_verification' => 'array',
        'carton_label_data' => 'array',
        'roll_per_box' => 'boolean',
    ];
}
