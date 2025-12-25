<?php

namespace App\Http\Resources\Machine;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MachineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'production_area' => $this->production_area,
            'machine_type' => $this->machine_type,
            'printing_type' => $this->printing_type,
            'machine_no' => $this->machine_no,
            'cost_center_old' => $this->cost_center_old,
            'cost_center_new' => $this->cost_center_new,
            'average_speed' => $this->average_speed,
            'max_colors' => $this->max_colors,
            'die_cut_stations' => $this->die_cut_stations,
            'max_die_cut_width_mm' => $this->max_die_cut_width_mm,
            'max_repeat_length_mm' => $this->max_repeat_length_mm,
            'max_material_width_mm' => $this->max_material_width_mm,
            'diecut_type' => $this->diecut_type,
            'rotary' => $this->rotary,
            'varnish' => $this->varnish,
            'inline_slitting' => $this->inline_slitting,
            'auto_inspection' => $this->auto_inspection,
            'auto_inspection_slitting' => $this->auto_inspection_slitting,
            'manual_inspection' => $this->manual_inspection,
            'machine_counting' => $this->machine_counting,
            'manual_counting' => $this->manual_counting,
            'manual_slitting' => $this->manual_slitting,
            'packing' => $this->packing,
            'lamination' => $this->lamination,
            'units' => $this->units,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
