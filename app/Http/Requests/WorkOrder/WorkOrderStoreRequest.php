<?php

namespace App\Http\Requests\WorkOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkOrderStoreRequest extends FormRequest
{
    private const PRIORITY_TYPES = [
        'low',
        'normal',
        'High(2 - 3)',
        'High(4 - 8)',
        'High(9 - 12)',
        'Urgent(2 - 3)',
        'Urgent(4 - 8)',
        'Urgent(9 - 12)',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => 'required|exists:customers,id',
            'template_route_id' => 'required|exists:template_routes,id',
            'work_order_no' => 'required|string|max:255',
            'priority_type' => ['nullable', 'string', Rule::in(self::PRIORITY_TYPES)],
            'date' => 'required|date',
            'posted_date' => 'required|date',
            'document_date' => 'required|date',
            'due_date' => 'required|date',
            'date_printed' => 'nullable|date',
            'document_type' => 'nullable|string|max:255',
            'printed_by' => 'nullable|string|max:255',
            'qr_code' => 'nullable|string|max:255',
            'size' => 'nullable|string|max:255',
            'websize' => 'nullable|string|max:255',
            'material' => 'nullable|string|max:255',
            'colours' => 'nullable|string|max:255',
            'butt' => 'nullable|string|max:255',
            'interval' => 'nullable|string|max:255',
            'radius' => 'nullable|string|max:255',
            'material_not_number' => 'nullable|string|max:255',
            'operator_code' => 'nullable|string|max:255',
            'number_of_used_up' => 'nullable|integer|min:0',
            'number_of_process' => 'nullable|integer|min:0',
            'meta_remarks' => 'nullable|string',
            'metadata' => 'nullable|array',
        ];
    }
}
