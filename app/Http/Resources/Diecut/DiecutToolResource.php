<?php

namespace App\Http\Resources\Diecut;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiecutToolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'diecut_profile_id' => $this->diecut_profile_id,
            'tool_code' => $this->tool_code,
            'normalized_tool_code' => $this->normalized_tool_code,
            'base_normalized_tool_code' => $this->base_normalized_tool_code,
            'cavity' => $this->cavity,
            'tool_life_pcs' => $this->tool_life_pcs,
            'tool_life_press' => $this->tool_life_press,
            'status' => $this->status,
            'is_active' => $this->is_active,
            'received_date' => $this->received_date,
            'start_date' => $this->start_date,
            'return_date' => $this->return_date,
            'source_sheet' => $this->source_sheet,
            'source_batch' => $this->source_batch,
            'remarks' => $this->remarks,
            'metadata' => $this->metadata,
            'profile' => new DiecutProfileResource($this->whenLoaded('profile')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
