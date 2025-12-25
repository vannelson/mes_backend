<?php

namespace App\Http\Requests\Machine;

use Illuminate\Foundation\Http\FormRequest;

class MachineUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'production_area' => ['sometimes', 'nullable', 'string', 'max:120'],
            'machine_type' => ['sometimes', 'required', 'string', 'max:120'],
            'printing_type' => ['sometimes', 'nullable', 'string', 'max:120'],
            'machine_no' => ['sometimes', 'nullable', 'string', 'max:50'],
            'cost_center_old' => ['sometimes', 'nullable', 'string', 'max:50'],
            'cost_center_new' => ['sometimes', 'nullable', 'string', 'max:50'],
            'average_speed' => ['sometimes', 'nullable', 'string', 'max:120'],
            'max_colors' => ['sometimes', 'nullable', 'string', 'max:50'],
            'die_cut_stations' => ['sometimes', 'nullable', 'string', 'max:50'],
            'max_die_cut_width_mm' => ['sometimes', 'nullable', 'string', 'max:50'],
            'max_repeat_length_mm' => ['sometimes', 'nullable', 'string', 'max:50'],
            'max_material_width_mm' => ['sometimes', 'nullable', 'string', 'max:50'],
            'diecut_type' => ['sometimes', 'nullable', 'string', 'max:120'],
            'rotary' => ['sometimes', 'nullable', 'string', 'max:50'],
            'varnish' => ['sometimes', 'nullable', 'string', 'max:50'],
            'inline_slitting' => ['sometimes', 'nullable', 'string', 'max:50'],
            'auto_inspection' => ['sometimes', 'nullable', 'string', 'max:50'],
            'auto_inspection_slitting' => ['sometimes', 'nullable', 'string', 'max:50'],
            'manual_inspection' => ['sometimes', 'nullable', 'string', 'max:50'],
            'machine_counting' => ['sometimes', 'nullable', 'string', 'max:50'],
            'manual_counting' => ['sometimes', 'nullable', 'string', 'max:50'],
            'manual_slitting' => ['sometimes', 'nullable', 'string', 'max:50'],
            'packing' => ['sometimes', 'nullable', 'string', 'max:50'],
            'lamination' => ['sometimes', 'nullable', 'string', 'max:50'],
            'units' => ['sometimes', 'nullable', 'string', 'max:120'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
