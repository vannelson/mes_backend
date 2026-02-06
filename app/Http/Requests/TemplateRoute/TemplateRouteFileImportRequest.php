<?php

namespace App\Http\Requests\TemplateRoute;

use Illuminate\Foundation\Http\FormRequest;

class TemplateRouteFileImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,xlsm,csv'],
            'sheet' => ['nullable', 'string', 'max:255'],
            'user_id' => ['required', 'exists:users,id'],
            'dry_run' => ['sometimes', 'boolean'],
            'batch_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}
