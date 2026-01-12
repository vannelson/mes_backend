<?php

namespace App\Http\Requests\VirtualScreen;

use Illuminate\Foundation\Http\FormRequest;

class PlaylistItemStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'virtual_screen_id' => ['required', 'integer', 'exists:virtual_screens,id'],
            'type' => ['required', 'in:url,widget,image,pdf,video,audio'],
            'content' => ['required', 'array'],
            'duration' => ['nullable', 'integer', 'min:1', 'max:300'],
            'order' => ['nullable', 'integer', 'min:0'],
            'schedule_start' => ['nullable', 'date'],
            'schedule_end' => ['nullable', 'date', 'after:schedule_start'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'virtual_screen_id.required' => 'Virtual screen ID is required.',
            'virtual_screen_id.exists' => 'Virtual screen does not exist.',
            'type.required' => 'Item type is required.',
            'type.in' => 'Item type must be one of: url, widget, image, pdf, video, audio.',
            'content.required' => 'Content is required.',
            'content.array' => 'Content must be a valid JSON object.',
            'duration.min' => 'Duration must be at least 1 second.',
            'duration.max' => 'Duration cannot exceed 300 seconds (5 minutes).',
            'schedule_end.after' => 'Schedule end must be after schedule start.',
        ];
    }

    /**
     * Additional validation after basic rules.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $type = $this->input('type');
            $content = $this->input('content', []);

            // Validate content based on type
            switch ($type) {
                case 'url':
                    if (empty($content['url'])) {
                        $validator->errors()->add('content.url', 'URL is required for URL type items.');
                    } elseif (!filter_var($content['url'], FILTER_VALIDATE_URL)) {
                        $validator->errors()->add('content.url', 'Invalid URL format.');
                    }
                    break;

                case 'widget':
                    if (empty($content['widget_type'])) {
                        $validator->errors()->add('content.widget_type', 'Widget type is required.');
                    } elseif (!in_array($content['widget_type'], ['time', 'date', 'weather', 'ticker'])) {
                        $validator->errors()->add('content.widget_type', 'Invalid widget type.');
                    }
                    break;

                case 'image':
                case 'pdf':
                case 'video':
                case 'audio':
                    if (empty($content['media_id']) && empty($content['url'])) {
                        $validator->errors()->add('content', 'Either media_id or url is required for media items.');
                    }
                    break;
            }
        });
    }
}
