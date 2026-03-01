<?php

namespace App\Http\Requests\OperationTrigger;

use Illuminate\Foundation\Http\FormRequest;

class OperationTriggerExecuteRequest extends FormRequest
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
            'execution_id' => ['nullable', 'string', 'max:120'],
            'event_id' => ['nullable', 'string', 'max:120'],
            'changes' => ['nullable', 'array'],
        ];
    }
}
