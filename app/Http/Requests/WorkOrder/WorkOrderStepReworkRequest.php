<?php

namespace App\Http\Requests\WorkOrder;

use Illuminate\Foundation\Http\FormRequest;

class WorkOrderStepReworkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:255'],
            'rework_by' => ['nullable', 'integer', 'exists:users,id'],
            'rework_at' => ['nullable', 'date'],
        ];
    }
}
