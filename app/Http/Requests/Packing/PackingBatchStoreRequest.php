<?php

namespace App\Http\Requests\Packing;

use Illuminate\Foundation\Http\FormRequest;

class PackingBatchStoreRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $packings = $this->input('packings');

        if (is_string($packings)) {
            $decoded = json_decode($packings, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->merge(['packings' => $decoded]);
            }
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $packingRules = PackingStoreRequest::baseRules();

        $rules = [
            'packings' => ['required', 'array', 'min:1'],
            'batch_number' => ['nullable', 'string', 'max:255'],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1048576'],
            'designs' => ['nullable', 'array'],
            'designs.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1048576'],
        ];

        foreach ($packingRules as $field => $fieldRules) {
            $rules["packings.*.{$field}"] = $this->makeOptional($fieldRules);
        }

        $rules['packings.*.image'] = ['nullable', 'string', 'max:255'];
        $rules['packings.*.design'] = ['nullable', 'string', 'max:255'];

        return $rules;
    }

    protected function makeOptional(array $rules): array
    {
        return array_values(array_filter($rules, static fn ($rule) => $rule !== 'required'));
    }
}
