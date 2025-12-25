<?php

namespace App\Http\Requests\Machine;

use Illuminate\Foundation\Http\FormRequest;

class MachineStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'production_area' => ['nullable', 'string', 'max:120'],
            'machine_type' => ['required', 'string', 'max:120'],
            'printing_type' => ['nullable', 'string', 'max:120'],
            'machine_no' => ['nullable', 'string', 'max:50'],
            'cost_center_old' => ['nullable', 'string', 'max:50'],
            'cost_center_new' => ['nullable', 'string', 'max:50'],
            'average_speed' => ['nullable', 'string', 'max:120'],
            'max_colors' => ['nullable', 'string', 'max:50'],
            'die_cut_stations' => ['nullable', 'string', 'max:50'],
            'max_die_cut_width_mm' => ['nullable', 'string', 'max:50'],
            'max_repeat_length_mm' => ['nullable', 'string', 'max:50'],
            'max_material_width_mm' => ['nullable', 'string', 'max:50'],
            'diecut_type' => ['nullable', 'string', 'max:120'],
            'rotary' => ['nullable', 'string', 'max:50'],
            'varnish' => ['nullable', 'string', 'max:50'],
            'inline_slitting' => ['nullable', 'string', 'max:50'],
            'auto_inspection' => ['nullable', 'string', 'max:50'],
            'auto_inspection_slitting' => ['nullable', 'string', 'max:50'],
            'manual_inspection' => ['nullable', 'string', 'max:50'],
            'machine_counting' => ['nullable', 'string', 'max:50'],
            'manual_counting' => ['nullable', 'string', 'max:50'],
            'manual_slitting' => ['nullable', 'string', 'max:50'],
            'packing' => ['nullable', 'string', 'max:50'],
            'lamination' => ['nullable', 'string', 'max:50'],
            'units' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
