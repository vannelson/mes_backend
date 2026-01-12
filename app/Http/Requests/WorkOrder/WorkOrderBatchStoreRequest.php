<?php

namespace App\Http\Requests\WorkOrder;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function () use ($validator): void {
            if ($validator->errors()->isEmpty()) {
                return;
            }

            $invalidItems = [];

            foreach ($validator->errors()->getMessages() as $field => $messages) {
                if (!str_starts_with($field, 'work_orders.')) {
                    continue;
                }

                $segments = explode('.', $field);
                $index = isset($segments[1]) && is_numeric($segments[1])
                    ? (int) $segments[1]
                    : null;

                if (is_null($index)) {
                    continue;
                }

                if (!isset($invalidItems[$index])) {
                    $invalidItems[$index] = [
                        'index' => $index,
                        'payload' => $this->input("work_orders.$index"),
                        'errors' => [],
                    ];
                }

                $invalidItems[$index]['errors'][] = [
                    'field' => $segments[2] ?? null,
                    'messages' => $messages,
                ];
            }

            if ($invalidItems !== []) {
                Log::warning('Invalid work order payload detected during batch import.', [
                    'invalid_items' => array_values($invalidItems),
                ]);
            }
        });
    }

    protected function makeOptional(array $rules): array
    {
        return array_values(array_filter($rules, static fn ($rule) => $rule !== 'required'));
    }
}
