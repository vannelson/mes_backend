<?php

namespace App\Http\Resources\Packing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\PackingChecklist\PackingChecklistResource;

class PackingResource extends JsonResource
{
    protected function resolvePackingAsset(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        $path = ltrim($value, '/');
        if (str_starts_with($path, 'images/packing/')) {
            return url("/{$path}");
        }
        if (str_starts_with($path, 'packing/')) {
            return url("/images/{$path}");
        }
        return url("/images/packing/{$path}");
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'wd_part_no' => $this->wd_part_no,
            'material' => $this->material,
            'description' => $this->description,
            'batch_number' => $this->batch_number,
            'image' => $this->image ? basename($this->image) : null,
            'image_url' => $this->resolvePackingAsset($this->image),
            'design' => $this->design ? basename($this->design) : null,
            'design_url' => $this->resolvePackingAsset($this->design),
            'shipping_location' => $this->shipping_location,
            'customer_code' => $this->customer_code,
            'customer_name' => $this->customer?->customer_name,
            'packing_checklist' => $this->packingChecklist
                ? (new PackingChecklistResource($this->packingChecklist))->resolve()
                : false,
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
