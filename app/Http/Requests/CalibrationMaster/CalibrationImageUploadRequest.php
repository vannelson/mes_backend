<?php

namespace App\Http\Requests\CalibrationMaster;

use Illuminate\Foundation\Http\FormRequest;

class CalibrationImageUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
        ];
    }
}
