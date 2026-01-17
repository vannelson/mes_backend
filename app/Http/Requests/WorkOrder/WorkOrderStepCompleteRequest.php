<?php

namespace App\Http\Requests\WorkOrder;

use Illuminate\Foundation\Http\FormRequest;

class WorkOrderStepCompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'max:30'],
            'completed_at' => ['nullable', 'date'],
            'completed_by' => ['nullable', 'integer', 'exists:users,id'],
            'payload' => ['nullable', 'array'],
        ];
    }
}
