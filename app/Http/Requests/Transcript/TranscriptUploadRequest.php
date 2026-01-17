<?php

namespace App\Http\Requests\Transcript;

use Illuminate\Foundation\Http\FormRequest;

class TranscriptUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:json',
                'mimetypes:application/json,text/json,application/octet-stream',
                'max:102400',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'File is required.',
            'file.file' => 'Invalid file upload.',
            'file.mimes' => 'Only JSON files are allowed.',
            'file.mimetypes' => 'Only JSON files are allowed.',
        ];
    }
}
