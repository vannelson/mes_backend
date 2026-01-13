<?php

namespace App\Http\Requests\WorkOrderComment;

use Illuminate\Foundation\Http\FormRequest;

class WorkOrderCommentUpdateRequest extends FormRequest
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
            'thread_id' => ['sometimes', 'nullable', 'integer', 'exists:work_order_comments,id'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:work_order_comments,id'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'type' => ['sometimes', 'nullable', 'string', 'max:30'],
            'visibility' => ['sometimes', 'nullable', 'string', 'max:20'],
            'title' => ['sometimes', 'nullable', 'string', 'max:150'],
            'body' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'nullable', 'string', 'max:30'],
            'attachments' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
