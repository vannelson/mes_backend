<?php

namespace App\Http\Requests\HistoricalWorkOrder;

use Illuminate\Foundation\Http\FormRequest;

class HistoricalWorkOrderImportRequest extends FormRequest
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
        ];
    }
}
