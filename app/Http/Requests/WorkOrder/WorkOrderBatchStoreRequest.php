<?php

namespace App\Http\Requests\WorkOrder;

use Illuminate\Foundation\Http\FormRequest;

class WorkOrderBatchStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $workOrderRules = WorkOrderStoreRequest::baseRules();

        $rules = [
            'work_orders' => ['required', 'array', 'min:1'],
        ];

        foreach ($workOrderRules as $field => $fieldRules) {
            $rules["work_orders.*.{$field}"] = $this->makeOptional($fieldRules);
        }

        return $rules;
    }

    protected function makeOptional(array $rules): array
    {
        return array_values(array_filter($rules, static fn ($rule) => $rule !== 'required'));
    }
}
