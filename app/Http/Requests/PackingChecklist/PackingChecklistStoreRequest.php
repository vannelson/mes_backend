<?php

namespace App\Http\Requests\PackingChecklist;

use Illuminate\Foundation\Http\FormRequest;

class PackingChecklistStoreRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['double_bag_checklist', 'quantity_verification', 'carton_label_data'] as $field) {
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
            'work_order_no' => ['required', 'string', 'max:100'],
            'wd_part_no' => ['required', 'string', 'max:100'],
            'double_bag_checklist' => ['nullable', 'array'],
            'double_bag_checklist.inner_bag_sealed' => ['nullable', 'boolean'],
            'double_bag_checklist.outer_bag_sealed' => ['nullable', 'boolean'],
            'quantity_verification' => ['nullable', 'array'],
            'quantity_verification.part_number_matches' => ['nullable', 'boolean'],
            'quantity_verification.part_code_matches' => ['nullable', 'boolean'],
            'quantity_verification.design_matches' => ['nullable', 'boolean'],
            'quantity_verification.feed_direction_correct' => ['nullable', 'boolean'],
            'quantity_verification.expected_qty' => ['nullable', 'string', 'max:255'],
            'quantity_verification.actual_qty' => ['nullable', 'string', 'max:255'],
            'quantity_verification.verified' => ['nullable', 'boolean'],
            'quantity_verification.inspector_name' => ['nullable', 'string', 'max:255'],
            'quantity_verification.sign_off_name' => ['nullable', 'string', 'max:255'],
            'roll_per_box' => ['nullable', 'boolean'],
            'ul_label_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1048576'],
            'product_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1048576'],
            'core_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1048576'],
            'carton_label_data' => ['nullable', 'array'],
            'carton_label_data.wo_no' => ['nullable', 'string', 'max:255'],
            'carton_label_data.part_no' => ['nullable', 'string', 'max:255'],
            'carton_label_data.mfg_date' => ['nullable', 'date'],
            'carton_label_data.exp_date' => ['nullable', 'date'],
            'carton_label_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1048576'],
            'no_of_cartons' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer'],
        ];
    }
}
