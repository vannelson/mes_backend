<?php

namespace App\Http\Requests\Bom;

use Illuminate\Foundation\Http\FormRequest;

class BomStoreRequest extends FormRequest
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
            'customer_code' => ['required', 'string'],
            'part_no' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'material_1_code' => ['nullable', 'string'],
            'material_1_desc' => ['nullable', 'string'],
            'material_2_code' => ['nullable', 'string'],
            'material_2_desc' => ['nullable', 'string'],
            'material_3_code' => ['nullable', 'string'],
            'material_3_desc' => ['nullable', 'string'],
            'material_4_code' => ['nullable', 'string'],
            'material_4_desc' => ['nullable', 'string'],
            'colour_code_1' => ['nullable', 'string'],
            'colour_code_2' => ['nullable', 'string'],
            'colour_code_3' => ['nullable', 'string'],
            'colour_code_4' => ['nullable', 'string'],
        ];
    }

    public function rules(): array
    {
        return self::baseRules();
    }
}
