<?php

namespace App\Http\Resources\PackingChecklist;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PackingChecklistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_order_no' => $this->work_order_no,
            'wd_part_no' => $this->wd_part_no,
            'double_bag_checklist' => $this->double_bag_checklist,
            'roll_per_box' => $this->roll_per_box,
            'ul_label_image' => $this->ul_label_image ? basename($this->ul_label_image) : null,
            'ul_label_image_url' => $this->ul_label_image ? Storage::disk('public')->url($this->ul_label_image) : null,
            'carton_label_data' => $this->carton_label_data,
            'carton_label_image' => $this->carton_label_image ? basename($this->carton_label_image) : null,
            'carton_label_image_url' => $this->carton_label_image ? Storage::disk('public')->url($this->carton_label_image) : null,
            'no_of_cartons' => $this->no_of_cartons,
            'user_id' => $this->user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
