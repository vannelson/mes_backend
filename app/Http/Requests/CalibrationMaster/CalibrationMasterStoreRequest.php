<?php

namespace App\Http\Requests\CalibrationMaster;

use Illuminate\Foundation\Http\FormRequest;

class CalibrationMasterStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sheet_name' => ['nullable', 'string', 'max:120'],
            'sheet_order' => ['nullable', 'integer', 'min:0'],
            'source_row' => ['nullable', 'integer', 'min:1'],
            'reference_no' => ['nullable', 'string', 'max:120'],
            'name_type' => ['required', 'string'],
            'function' => ['nullable', 'string'],
            'image' => ['nullable', 'string'],
            'identification_number' => ['nullable', 'string'],
            'measurement_range' => ['nullable', 'string'],
            'inherent_accuracy' => ['nullable', 'string'],
            'usage_accuracy' => ['nullable', 'string'],
            'owner_location' => ['nullable', 'string'],
            'frequency_label' => ['nullable', 'string', 'max:120'],
            'last_calibration_date' => ['nullable'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
