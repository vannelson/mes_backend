<?php

namespace App\Http\Requests\OperationTrigger;

use Illuminate\Foundation\Http\FormRequest;

class OperationTriggerSimulateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'work_order_id' => ['nullable', 'integer', 'exists:work_orders,id'],
            'work_order_no' => ['nullable', 'string', 'max:255'],
            'changes' => ['nullable', 'array'],
        ];
    }
}
