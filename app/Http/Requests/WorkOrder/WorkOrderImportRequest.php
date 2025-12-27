<?php

namespace App\Http\Requests\WorkOrder;

use Illuminate\Foundation\Http\FormRequest;

class WorkOrderImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sheet' => ['required', 'string'],
            'file' => ['required', 'file', 'mimes:xls,xlsx,xlsm'],
        ];
    }
}
