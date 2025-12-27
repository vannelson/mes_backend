<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'template_route_id',
        'work_order_no',
        'batch_number',
        'selected',
        'mes_batch_no',
        'customer_code',
        'customer_name',
        'material_1_code',
        'material_2_code',
        'material_3_code',
        'material_4_code',
        'customer_part_number',
        'production_due_date',
        'quantity_to_produce',
        'quantity_produced',
        'forecast_quantity',
        'die_cut',
        'internal_remark',
        'requested_delivery_date',
        'no_of_colours',
        'sales_person_code',
        'order_date',
        'production_date_completed',
        'production_qty_completed',
        'qr_code',
        'metadata',
    ];

    protected $casts = [
        'selected' => 'boolean',
        'production_due_date' => 'date',
        'requested_delivery_date' => 'date',
        'order_date' => 'date',
        'production_date_completed' => 'date',
        'quantity_to_produce' => 'string',
        'quantity_produced' => 'string',
        'forecast_quantity' => 'string',
        'no_of_colours' => 'string',
        'production_qty_completed' => 'string',
        'metadata' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function templateRoute(): BelongsTo
    {
        return $this->belongsTo(TemplateRoute::class);
    }
}
