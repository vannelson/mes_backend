<?php

namespace App\Http\Requests\SupplierChangeControl;

use Illuminate\Foundation\Http\FormRequest;

class SupplierChangeControlUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge(SupplierChangeControlStoreRequest::baseRules(), [
            'attachment' => [
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
                'max:10240',
            ],
            'event_note' => ['nullable', 'string'],
        ]);
    }
}

