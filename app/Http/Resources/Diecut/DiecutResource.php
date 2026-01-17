<?php

namespace App\Http\Resources\Diecut;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiecutResource extends JsonResource
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
            'diecut_no' => $this->diecut_no,
            'diecut_type' => $this->diecut_type,
            'width' => $this->width,
            'length' => $this->length,
            'no_of_ups' => $this->no_of_ups,
            'rev' => $this->rev,
            'radius' => $this->radius,
            'perforate' => $this->perforate,
            'int_ud' => $this->int_ud,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
