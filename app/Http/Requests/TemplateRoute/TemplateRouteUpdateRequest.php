<?php

namespace App\Http\Requests\TemplateRoute;

use Illuminate\Foundation\Http\FormRequest;

class TemplateRouteUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'template' => ['sometimes', 'required', 'string', 'max:255'],
            'wod_ref' => ['sometimes', 'nullable', 'string'],
            'user_id' => ['sometimes', 'required', 'exists:users,id'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
