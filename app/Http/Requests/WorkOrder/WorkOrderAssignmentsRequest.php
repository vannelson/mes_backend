<?php

namespace App\Http\Requests\WorkOrder;

use Illuminate\Foundation\Http\FormRequest;

class WorkOrderAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'routes' => ['required', 'array'],
            'routes.*.order_seq' => ['nullable', 'integer'],
            'routes.*.route' => ['nullable', 'string', 'max:50'],
            'routes.*.name' => ['nullable', 'string', 'max:120'],
            'routes.*.operators' => ['nullable', 'array'],
            'routes.*.operators.*.id' => ['required', 'integer', 'exists:users,id'],
            'routes.*.operators.*.qty' => ['nullable', 'numeric'],
        ];
    }
}
