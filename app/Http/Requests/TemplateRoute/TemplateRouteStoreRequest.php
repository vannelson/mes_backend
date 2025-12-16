<?php

namespace App\Http\Requests\TemplateRoute;

use Illuminate\Foundation\Http\FormRequest;

class TemplateRouteStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'template' => ['required', 'string', 'max:255'],
            'user_id' => ['required', 'exists:users,id'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
