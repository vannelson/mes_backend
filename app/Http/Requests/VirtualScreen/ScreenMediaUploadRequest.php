<?php

namespace App\Http\Requests\VirtualScreen;

use Illuminate\Foundation\Http\FormRequest;

class ScreenMediaUploadRequest extends FormRequest
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
                'max:10240', // 10MB
                'mimes:jpeg,jpg,png,gif,webp,pdf',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'File is required.',
            'file.file' => 'Invalid file upload.',
            'file.max' => 'File size cannot exceed 10MB.',
            'file.mimes' => 'File must be an image (JPEG, PNG, GIF, WebP) or PDF.',
        ];
    }
}
