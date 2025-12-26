<?php

namespace App\Http\Requests\WorkOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkOrderDetailRequest extends FormRequest
{
    private const DETAIL_COLUMNS = [
        'customer_id',
        'template_route_id',
        'work_order_no',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $column = $this->input('column');
        $valueRules = ['required'];

        if (in_array($column, ['customer_id', 'template_route_id'], true)) {
            $valueRules[] = 'integer';
        } else {
            $valueRules[] = 'string';
        }

        return [
            'column' => ['required', 'string', Rule::in(self::DETAIL_COLUMNS)],
            'value' => $valueRules,
        ];
    }
}
