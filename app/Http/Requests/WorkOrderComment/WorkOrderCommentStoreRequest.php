<?php

namespace App\Http\Requests\WorkOrderComment;

use Illuminate\Foundation\Http\FormRequest;

class WorkOrderCommentStoreRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['attachments', 'metadata'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->merge([$field => $decoded]);
                }
            }
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'work_order_id' => ['required', 'integer', 'exists:work_orders,id'],
            'thread_id' => ['nullable', 'integer', 'exists:work_order_comments,id'],
            'parent_id' => ['nullable', 'integer', 'exists:work_order_comments,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'type' => ['nullable', 'string', 'max:30'],
            'visibility' => ['nullable', 'string', 'max:20'],
            'title' => ['nullable', 'string', 'max:150'],
            'body' => ['required', 'string'],
            'status' => ['nullable', 'string', 'max:30'],
            'attachments' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
