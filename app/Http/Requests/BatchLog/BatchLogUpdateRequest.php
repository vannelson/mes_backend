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
            'batch_no' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('batch_logs', 'batch_no')->ignore($id),
            ],
            'total_rows' => ['sometimes', 'integer', 'min:0'],
            'operator' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
