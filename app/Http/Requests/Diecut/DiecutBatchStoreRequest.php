<?php

namespace App\Http\Requests\Diecut;

use Illuminate\Foundation\Http\FormRequest;

class DiecutBatchStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $diecutRules = DiecutStoreRequest::baseRules();

        $rules = [
            'diecuts' => ['required', 'array', 'min:1'],
        ];

        foreach ($diecutRules as $field => $fieldRules) {
            $rules["diecuts.*.{$field}"] = $this->makeOptional($fieldRules);
        }

        return $rules;
    }

    protected function makeOptional(array $rules): array
    {
        return array_values(array_filter($rules, static fn ($rule) => $rule !== 'required'));
    }
}
