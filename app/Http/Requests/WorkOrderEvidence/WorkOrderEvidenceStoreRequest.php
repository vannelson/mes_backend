<?php

namespace App\Http\Requests\WorkOrderEvidence;

use Illuminate\Foundation\Http\FormRequest;

class WorkOrderEvidenceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'work_order_no' => ['required', 'string', 'max:255'],
            'route_name' => ['required', 'string', 'max:255'],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
