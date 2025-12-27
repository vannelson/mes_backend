<?php

namespace App\Http\Requests\WorkOrder;

use Illuminate\Foundation\Http\FormRequest;

class WorkOrderStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public static function baseRules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'template_route_id' => ['required', 'exists:template_routes,id'],
            'work_order_no' => ['required', 'string', 'max:255'],
            'batch_number' => ['nullable', 'string', 'max:20'],
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
            'qr_code' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function rules(): array
    {
        return self::baseRules();
    }
}
