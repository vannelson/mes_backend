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
        'priority_type',
        'date',
        'posted_date',
        'document_date',
        'due_date',
        'date_printed',
        'document_type',
        'printed_by',
        'qr_code',
        'size',
        'websize',
        'material',
        'colours',
        'butt',
        'interval',
        'radius',
        'material_not_number',
        'operator_code',
        'number_of_used_up',
        'number_of_process',
        'meta_remarks',
        'metadata',
    ];

    protected $casts = [
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
