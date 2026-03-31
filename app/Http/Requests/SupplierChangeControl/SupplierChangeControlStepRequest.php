<?php

namespace App\Http\Requests\SupplierChangeControl;

use Illuminate\Foundation\Http\FormRequest;

class SupplierChangeControlStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_step' => ['required', 'integer', 'min:1', 'max:5'],
            'status' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string'],
        ];
    }
}

