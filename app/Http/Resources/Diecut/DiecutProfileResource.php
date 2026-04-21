<?php

namespace App\Http\Resources\Diecut;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiecutProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'profile_code' => $this->profile_code,
            'normalized_code' => $this->normalized_code,
            'base_normalized_code' => $this->base_normalized_code,
            'diecut_type' => $this->diecut_type,
            'height_mm' => $this->height_mm,
            'width_mm' => $this->width_mm,
            'interval_ud_mm' => $this->interval_ud_mm,
            'interval_lr_mm' => $this->interval_lr_mm,
            'column_count' => $this->column_count,
            'no_of_ups' => $this->no_of_ups,
            'default_tool_life_pcs' => $this->default_tool_life_pcs,
            'default_tool_life_press' => $this->default_tool_life_press,
            'rev' => $this->rev,
            'status' => $this->status,
            'source_sheet' => $this->source_sheet,
            'source_batch' => $this->source_batch,
            'metadata' => $this->metadata,
            'aliases' => $this->whenLoaded('aliases', function () {
                return $this->aliases->map(fn ($alias) => [
                    'id' => $alias->id,
                    'alias_code' => $alias->alias_code,
                    'normalized_alias' => $alias->normalized_alias,
                    'base_normalized_alias' => $alias->base_normalized_alias,
                    'alias_type' => $alias->alias_type,
                    'confidence_score' => $alias->confidence_score,
                ])->values();
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
