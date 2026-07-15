<?php

namespace App\Http\Requests\CalibrationMaster;

use Illuminate\Foundation\Http\FormRequest;

class CalibrationHistoryStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'calibration_date' => ['required', 'date'],
            'cert_no'          => ['nullable', 'string', 'max:120'],
            'performed_by'     => ['nullable', 'string', 'max:255'],
            'notes'            => ['nullable', 'string'],
            'cert_file'        => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
    }
}
