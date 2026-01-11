<?php

namespace App\Http\Requests\VirtualScreen;

use Illuminate\Foundation\Http\FormRequest;

class PlaylistItemUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'in:url,widget,image,pdf,video,audio'],
            'content' => ['sometimes', 'array'],
            'duration' => ['sometimes', 'integer', 'min:1', 'max:300'],
            'order' => ['sometimes', 'integer', 'min:0'],
            'schedule_start' => ['nullable', 'date'],
            'schedule_end' => ['nullable', 'date', 'after:schedule_start'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'Item type must be one of: url, widget, image, pdf, video, audio.',
            'content.array' => 'Content must be a valid JSON object.',
            'duration.min' => 'Duration must be at least 1 second.',
            'duration.max' => 'Duration cannot exceed 300 seconds (5 minutes).',
            'schedule_end.after' => 'Schedule end must be after schedule start.',
        ];
    }
}
