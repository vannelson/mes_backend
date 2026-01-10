<?php

namespace App\Http\Requests\VirtualScreen;

use Illuminate\Foundation\Http\FormRequest;

class VirtualScreenUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'orientation' => ['sometimes', 'in:landscape,portrait'],
            'aspect_ratio' => ['sometimes', 'string', 'max:10'],
            'timezone' => ['sometimes', 'string', 'max:50'],
            'refresh_interval' => ['sometimes', 'integer', 'min:30', 'max:3600'],
            'is_active' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'settings.transition' => ['nullable', 'string', 'in:none,fade,slide'],
            'settings.transition_duration' => ['nullable', 'integer', 'min:100', 'max:5000'],
            'settings.loop' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'orientation.in' => 'Orientation must be either landscape or portrait.',
            'refresh_interval.min' => 'Refresh interval must be at least 30 seconds.',
            'refresh_interval.max' => 'Refresh interval cannot exceed 3600 seconds (1 hour).',
        ];
    }
}
