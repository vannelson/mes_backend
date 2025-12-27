<?php

namespace App\Http\Requests\BatchLog;

use Illuminate\Foundation\Http\FormRequest;

class BatchLogStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'exists:users,id'],
            'batch_no' => ['required', 'string', 'max:100', 'unique:batch_logs,batch_no'],
            'total_rows' => ['required', 'integer', 'min:0'],
            'operator' => ['required', 'string', 'max:120'],
        ];
    }
}
