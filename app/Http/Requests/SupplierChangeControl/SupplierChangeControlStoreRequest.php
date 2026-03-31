<?php

namespace App\Http\Requests\SupplierChangeControl;

use Illuminate\Foundation\Http\FormRequest;

class SupplierChangeControlStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public static function baseRules(): array
    {
        return [
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'tel_fax' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:100'],
            'current_step' => ['nullable', 'integer', 'min:1', 'max:5'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function rules(): array
    {
        return array_merge(self::baseRules(), [
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

