<?php

namespace App\Http\Requests\Packing;

use Illuminate\Foundation\Http\FormRequest;

class PackingStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public static function baseRules(): array
    {
        return [
            'wd_part_no' => ['nullable', 'string', 'max:255'],
            'material' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'batch_number' => ['nullable', 'string', 'max:255'],
            'design' => ['nullable', 'string', 'max:255'],
            'shipping_location' => ['nullable', 'string', 'max:255'],
            'customer_code' => ['nullable', 'string', 'max:255'],
            'box_size' => ['nullable', 'string', 'max:255'],
            'qty_per_box' => ['nullable', 'string', 'max:255'],
            'qty_per_roll' => ['nullable', 'string', 'max:255'],
            'rolls_per_box' => ['nullable', 'string', 'max:255'],
            'core_label_left' => ['nullable', 'string', 'max:255'],
            'core_label_right' => ['nullable', 'string', 'max:255'],
            'hm_no' => ['nullable', 'string', 'max:255'],
            'ul_label_no' => ['nullable', 'string', 'max:255'],
            'cas' => ['nullable', 'string', 'max:255'],
            'important' => ['nullable', 'string', 'max:255'],
            'code_1' => ['nullable', 'string', 'max:255'],
            'underline_code' => ['nullable', 'string', 'max:255'],
            'colour_code' => ['nullable', 'string', 'max:255'],
            'wd_revision' => ['nullable', 'string', 'max:255'],
            'revised_by_pic' => ['nullable', 'string', 'max:255'],
            'date_of_revised' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    public function rules(): array
    {
        return array_merge(self::baseRules(), [
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1048576'],
            'design' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1048576'],
        ]);
    }
}
