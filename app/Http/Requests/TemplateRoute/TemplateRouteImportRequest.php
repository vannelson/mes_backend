<?php

namespace App\Http\Requests\TemplateRoute;

use Illuminate\Foundation\Http\FormRequest;

class TemplateRouteImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'templates' => ['required', 'array', 'min:1'],
            'templates.*.template' => ['nullable', 'string', 'max:255'],
            'templates.*.wod_ref' => ['nullable', 'string'],
            'templates.*.metadata' => ['required', 'array', 'min:1'],
            'templates.*.work_orders' => ['sometimes', 'array'],
            'templates.*.work_orders.*' => ['string'],
            'templates.*.sequence' => ['sometimes', 'string'],
        ];
    }
}
