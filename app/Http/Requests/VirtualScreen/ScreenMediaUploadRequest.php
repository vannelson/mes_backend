<?php

namespace App\Http\Requests\VirtualScreen;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
                'mimes:jpeg,jpg,png,gif,webp,pdf,mp3,wav,ogg,aac,webm,mp4,m4a,flac,avi,mov,mkv,mpeg',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if (!$this->hasFile('file')) {
                return;
            }

            $file = $this->file('file');
            $mime = $file->getMimeType();
            $sizeKb = $file->getSize() / 1024;

            if (str_starts_with($mime, 'video/') && $sizeKb > 102400) {
                $validator->errors()->add('file', 'Video files cannot exceed 100MB.');
            }

            if (str_starts_with($mime, 'audio/') && $sizeKb > 25600) {
                $validator->errors()->add('file', 'Audio files cannot exceed 25MB.');
            }

            if (
                !str_starts_with($mime, 'video/') &&
                !str_starts_with($mime, 'audio/') &&
                $sizeKb > 10240
            ) {
                $validator->errors()->add('file', 'File size cannot exceed 10MB.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'file.required' => 'File is required.',
            'file.file' => 'Invalid file upload.',
            'file.mimes' => 'Unsupported file type.',
        ];
    }
}
