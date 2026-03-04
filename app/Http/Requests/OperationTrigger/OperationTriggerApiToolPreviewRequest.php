<?php

namespace App\Http\Requests\OperationTrigger;

use Illuminate\Foundation\Http\FormRequest;

class OperationTriggerApiToolPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'node_id' => ['required', 'string', 'max:120'],
            'node' => ['nullable', 'array'],
            'work_order_id' => ['nullable', 'integer', 'exists:work_orders,id'],
            'work_order_no' => ['nullable', 'string', 'max:255'],
            'changes' => ['nullable', 'array'],
            'data' => ['nullable'],
        ];
    }
}
