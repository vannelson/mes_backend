<?php

namespace App\Http\Resources\Packing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PackingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'wd_part_no' => $this->wd_part_no,
            'material' => $this->material,
            'description' => $this->description,
            'batch_number' => $this->batch_number,
            'image' => $this->image ? basename($this->image) : null,
            'image_url' => $this->image ? Storage::disk('public')->url($this->image) : null,
            'design' => $this->design ? basename($this->design) : null,
            'design_url' => $this->design ? Storage::disk('public')->url($this->design) : null,
            'shipping_location' => $this->shipping_location,
            'customer_code' => $this->customer_code,
            'box_size' => $this->box_size,
            'qty_per_box' => $this->qty_per_box,
            'rolls_per_box' => $this->rolls_per_box,
            'core_label_left' => $this->core_label_left,
            'core_label_right' => $this->core_label_right,
            'hm_no' => $this->hm_no,
            'ul_label_no' => $this->ul_label_no,
            'cas' => $this->cas,
            'important' => $this->important,
            'code_1' => $this->code_1,
            'underline_code' => $this->underline_code,
            'colour_code' => $this->colour_code,
            'wd_revision' => $this->wd_revision,
            'revised_by_pic' => $this->revised_by_pic,
            'date_of_revised' => $this->date_of_revised?->toDateString(),
            'remarks' => $this->remarks,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
