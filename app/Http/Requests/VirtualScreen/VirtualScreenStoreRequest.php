<?php

namespace App\Http\Requests\VirtualScreen;

use Illuminate\Foundation\Http\FormRequest;

class VirtualScreenStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'orientation' => ['nullable', 'in:landscape,portrait'],
            'aspect_ratio' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'refresh_interval' => ['nullable', 'integer', 'min:30', 'max:3600'],
            'is_active' => ['nullable', 'boolean'],
            'settings' => ['nullable', 'array'],
            'settings.transition' => ['nullable', 'string', 'in:none,fade,slide'],
            'settings.transition_duration' => ['nullable', 'integer', 'min:100', 'max:5000'],
            'settings.loop' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Screen name is required.',
            'orientation.in' => 'Orientation must be either landscape or portrait.',
            'refresh_interval.min' => 'Refresh interval must be at least 30 seconds.',
            'refresh_interval.max' => 'Refresh interval cannot exceed 3600 seconds (1 hour).',
        ];
    }
}
