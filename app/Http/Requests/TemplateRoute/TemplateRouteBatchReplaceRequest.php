<?php

namespace App\Http\Requests\TemplateRoute;

use Illuminate\Foundation\Http\FormRequest;

class TemplateRouteBatchReplaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'batch_number' => ['required', 'string', 'max:100'],
            'templates' => ['required', 'array', 'min:1'],
        ];

        $rules['templates.*.template'] = ['required', 'string', 'max:255'];
        $rules['templates.*.wod_ref'] = ['nullable', 'string'];
        $rules['templates.*.customer_part_number_ref'] = ['nullable', 'string'];
        $rules['templates.*.sheet'] = ['nullable', 'string', 'max:255'];
        $rules['templates.*.user_id'] = ['required', 'exists:users,id'];
        $rules['templates.*.metadata'] = ['nullable', 'array'];

        return $rules;
    }
}
