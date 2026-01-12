<?php

namespace App\Http\Requests\Packing;

use Illuminate\Foundation\Http\FormRequest;

class PackingUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge(PackingStoreRequest::baseRules(), [
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1048576'],
            'design' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1048576'],
        ]);
    }
}
