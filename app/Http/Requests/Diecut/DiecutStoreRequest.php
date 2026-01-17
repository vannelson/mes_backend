<?php

namespace App\Http\Requests\Diecut;

use Illuminate\Foundation\Http\FormRequest;

class DiecutStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public static function baseRules(): array
    {
        return [
            'batch_number' => ['nullable', 'string'],
            'sheet' => ['nullable', 'string'],
            'diecut_no' => ['required', 'string'],
            'diecut_type' => ['nullable', 'string'],
            'width' => ['nullable', 'string'],
            'length' => ['nullable', 'string'],
            'no_of_ups' => ['nullable', 'string'],
            'rev' => ['nullable', 'string'],
            'radius' => ['nullable', 'string'],
            'perforate' => ['nullable', 'string'],
            'int_ud' => ['nullable', 'string'],
        ];
    }

    public function rules(): array
    {
        return self::baseRules();
    }
}
