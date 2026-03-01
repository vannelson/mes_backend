<?php

namespace App\Http\Requests\OperationTrigger;

use Illuminate\Foundation\Http\FormRequest;

class OperationTriggerStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,published,paused,disabled'],
            'tags' => ['nullable', 'array'],
            'rule' => ['required', 'array'],
            'schedule' => ['nullable', 'array'],
            'actions' => ['required', 'array'],
            'cooldown' => ['nullable', 'array'],
            'debounce' => ['nullable', 'array'],
            'version' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
