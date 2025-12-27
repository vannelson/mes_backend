<?php

namespace App\Http\Requests\WorkOrder;

use Illuminate\Foundation\Http\FormRequest;

class WorkOrderUpdateRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => 'sometimes|exists:customers,id',
            'template_route_id' => 'sometimes|exists:template_routes,id',
            'work_order_no' => 'sometimes|string|max:255',
            'batch_number' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[0-9]{6}T[0-9]{4}$/'],
            'selected' => ['sometimes', 'boolean'],
            'mes_batch_no' => ['nullable', 'string', 'max:100'],
            'customer_code' => ['nullable', 'string', 'max:100'],
            'customer_name' => ['nullable', 'string', 'max:200'],
            'material_1_code' => ['nullable', 'string', 'max:100'],
            'material_2_code' => ['nullable', 'string', 'max:100'],
            'material_3_code' => ['nullable', 'string', 'max:100'],
            'material_4_code' => ['nullable', 'string', 'max:100'],
            'customer_part_number' => ['nullable', 'string', 'max:120'],
            'production_due_date' => ['nullable', 'date'],
            'quantity_to_produce' => ['nullable', 'string', 'max:100'],
            'quantity_produced' => ['nullable', 'string', 'max:100'],
            'forecast_quantity' => ['nullable', 'string', 'max:100'],
            'die_cut' => ['nullable', 'string', 'max:120'],
            'internal_remark' => ['nullable', 'string'],
            'requested_delivery_date' => ['nullable', 'date'],
            'no_of_colours' => ['nullable', 'string', 'max:100'],
            'sales_person_code' => ['nullable', 'string', 'max:100'],
            'order_date' => ['nullable', 'date'],
            'production_date_completed' => ['nullable', 'date'],
            'production_qty_completed' => ['nullable', 'string', 'max:100'],
            'qr_code' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
        ];
    }
}
