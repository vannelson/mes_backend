<?php

namespace App\Http\Resources\Bom;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BomResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_number' => $this->batch_number,
            'sheet' => $this->sheet,
            'customer_code' => $this->customer_code,
            'part_no' => $this->part_no,
            'description' => $this->description,
            'material_1_code' => $this->material_1_code,
            'material_1_desc' => $this->material_1_desc,
            'material_2_code' => $this->material_2_code,
            'material_2_desc' => $this->material_2_desc,
            'material_3_code' => $this->material_3_code,
            'material_3_desc' => $this->material_3_desc,
            'material_4_code' => $this->material_4_code,
            'material_4_desc' => $this->material_4_desc,
            'colour_code_1' => $this->colour_code_1,
            'colour_code_2' => $this->colour_code_2,
            'colour_code_3' => $this->colour_code_3,
            'colour_code_4' => $this->colour_code_4,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
