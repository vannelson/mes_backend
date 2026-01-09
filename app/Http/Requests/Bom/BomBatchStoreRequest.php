<?php

namespace App\Http\Requests\Bom;

use Illuminate\Foundation\Http\FormRequest;

class BomBatchStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $bomRules = BomStoreRequest::baseRules();

        $rules = [
            'boms' => ['required', 'array', 'min:1'],
        ];

        foreach ($bomRules as $field => $fieldRules) {
            $rules["boms.*.{$field}"] = $this->makeOptional($fieldRules);
        }

        return $rules;
    }

    protected function makeOptional(array $rules): array
    {
        return array_values(array_filter($rules, static fn ($rule) => $rule !== 'required'));
    }
}
