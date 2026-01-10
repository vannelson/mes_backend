<?php

namespace App\Http\Requests\VirtualScreen;

use Illuminate\Foundation\Http\FormRequest;

class PlaylistItemReorderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:playlist_items,id'],
            'items.*.order' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Items array is required.',
            'items.array' => 'Items must be an array.',
            'items.min' => 'At least one item is required.',
            'items.*.id.required' => 'Item ID is required.',
            'items.*.id.exists' => 'One or more items do not exist.',
            'items.*.order.required' => 'Order is required for each item.',
            'items.*.order.min' => 'Order must be a non-negative integer.',
        ];
    }
}
