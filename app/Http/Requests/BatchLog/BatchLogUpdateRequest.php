<?php

namespace App\Http\Requests\BatchLog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BatchLogUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = (int) $this->route('id');

        return [
            'user_id' => ['sometimes', 'exists:users,id'],
            'batch_no' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('batch_logs', 'batch_no')->ignore($id),
            ],
            'type' => ['sometimes', 'in:work_order,template_route,bom,packing'],
            'total_rows' => ['sometimes', 'integer', 'min:0'],
            'operator' => ['sometimes', 'string', 'max:120'],
            'sheet' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
