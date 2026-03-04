<?php

namespace App\Http\Requests\OperationTrigger;

use Illuminate\Foundation\Http\FormRequest;

class OperationTriggerUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:draft,published,paused,disabled'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'rule' => ['sometimes', 'array'],
            'loop' => ['sometimes', 'nullable', 'array'],
            'schedule' => ['sometimes', 'nullable', 'array'],
            'actions' => ['sometimes', 'array'],
            'flow' => ['sometimes', 'array'],
            'flow.nodes' => ['sometimes', 'array', 'min:1'],
            'flow.edges' => ['sometimes', 'array'],
            'cooldown' => ['sometimes', 'nullable', 'array'],
            'debounce' => ['sometimes', 'nullable', 'array'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
