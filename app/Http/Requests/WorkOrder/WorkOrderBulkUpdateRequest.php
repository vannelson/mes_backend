<?php

namespace App\Http\Requests\WorkOrder;

use Illuminate\Foundation\Http\FormRequest;

class WorkOrderBulkUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_code' => ['required', 'string', 'max:100'],
            'customer_part_number' => ['required', 'string', 'max:120'],
            'changes' => ['required', 'array', 'min:1'],
            'changes.template_route_id' => ['sometimes', 'exists:template_routes,id'],
            'changes.production_due_date' => ['nullable', 'date'],
            'changes.requested_delivery_date' => ['nullable', 'date'],
            'changes.order_date' => ['nullable', 'date'],
            'changes.quantity_to_produce' => ['nullable', 'string', 'max:100'],
            'changes.quantity_produced' => ['nullable', 'string', 'max:100'],
            'changes.forecast_quantity' => ['nullable', 'string', 'max:100'],
            'changes.production_qty_completed' => ['nullable', 'string', 'max:100'],
            'changes.metadata' => ['nullable', 'array'],
        ];
    }
}
