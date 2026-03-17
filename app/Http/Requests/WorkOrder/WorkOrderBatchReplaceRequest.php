<?php

namespace App\Http\Requests\WorkOrder;

use App\Http\Requests\WorkOrder\Concerns\NormalizesWorkOrderDates;
use Illuminate\Foundation\Http\FormRequest;

class WorkOrderBatchReplaceRequest extends FormRequest
{
    use NormalizesWorkOrderDates;

    protected function prepareForValidation(): void
    {
        $workOrders = $this->input('work_orders');
        if (!is_array($workOrders)) {
            return;
        }

        $dateFields = [
            'production_due_date',
            'requested_delivery_date',
            'order_date',
            'production_start_date',
            'production_date_completed',
        ];

        $workOrders = $this->normalizeWorkOrderDates($workOrders, $dateFields);

        $this->merge([
            'work_orders' => $workOrders,
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $workOrderRules = WorkOrderStoreRequest::baseRules();

        $rules = [
            'batch_number' => ['required', 'string', 'max:255'],
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
