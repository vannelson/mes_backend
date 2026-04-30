<?php

namespace App\Http\Requests\Packing;

use Illuminate\Foundation\Http\FormRequest;

class PackingBatchStoreRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $packings = $this->input('packings');

        if (is_string($packings)) {
            $decoded = json_decode($packings, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $packings = $decoded;
            }
        }

        if (is_array($packings)) {
            $this->merge([
                'packings' => array_map(
                    fn ($packing) => is_array($packing)
                        ? $this->normalizePackingPayload($packing)
                        : $packing,
                    $packings
                ),
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $packingRules = PackingStoreRequest::baseRules();

        $rules = [
            'packings' => ['required', 'array', 'min:1'],
            'batch_number' => ['nullable', 'string', 'max:255'],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1048576'],
            'designs' => ['nullable', 'array'],
            'designs.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1048576'],
        ];

        foreach ($packingRules as $field => $fieldRules) {
            $rules["packings.*.{$field}"] = $this->makeOptional($fieldRules);
        }

        $rules['packings.*.image'] = ['nullable', 'string', 'max:255'];
        $rules['packings.*.design'] = ['nullable', 'string', 'max:255'];

        return $rules;
    }

    protected function makeOptional(array $rules): array
    {
        return array_values(array_filter($rules, static fn ($rule) => $rule !== 'required'));
    }

    protected function normalizePackingPayload(array $packing): array
    {
        $stringFields = [
            'wd_part_no',
            'material',
            'description',
            'batch_number',
            'image',
            'design',
            'shipping_location',
            'customer_code',
            'box_size',
            'qty_per_box',
            'qty_per_roll',
            'rolls_per_box',
            'core_label_left',
            'core_label_right',
            'hm_no',
            'ul_label_no',
            'cas',
            'important',
            'code_1',
            'underline_code',
            'colour_code',
            'wd_revision',
            'revised_by_pic',
            'remarks',
        ];

        foreach ($stringFields as $field) {
            if (!array_key_exists($field, $packing)) {
                continue;
            }

            if ($packing[$field] === null || $packing[$field] === '') {
                continue;
            }

            if (is_bool($packing[$field])) {
                $packing[$field] = $packing[$field] ? '1' : '0';
                continue;
            }

            if (is_scalar($packing[$field])) {
                $packing[$field] = trim((string) $packing[$field]);
            }
        }

        return $packing;
    }
}
