<?php

namespace App\Http\Requests\WorkOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkOrderTimeTrackerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['start', 'pause', 'stop', 'progress'])],
            'route_key' => ['required_without_all:route_index,order_seq', 'string', 'max:120'],
            'route_index' => ['required_without_all:route_key,order_seq', 'integer', 'min:0'],
            'order_seq' => ['required_without_all:route_key,route_index', 'integer', 'min:1'],
            'operator_id' => ['nullable', 'integer', 'exists:users,id'],
            'source' => ['nullable', 'string', 'max:30'],
            'printed_qty' => ['nullable', 'numeric', 'min:0'],
            'operator_progress_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'route_progress_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'total_printed_qty' => ['nullable', 'numeric', 'min:0'],
            'target_printed_qty' => ['nullable', 'numeric', 'min:0'],
            'pause_reason' => ['required_if:action,pause', 'string', 'max:120'],
            'pause_reason_key' => ['nullable', 'string', 'max:60'],
            'pause_note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
