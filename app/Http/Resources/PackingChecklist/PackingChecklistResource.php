<?php

namespace App\Http\Resources\PackingChecklist;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PackingChecklistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $buildUrl = function (?string $path): ?string {
            if (!$path) return null;
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                return $path;
            }
            $clean = ltrim($path, '/');
            if (str_starts_with($clean, 'packingChecklist/')) {
                return url('/images/' . $clean);
            }
            if (str_starts_with($clean, 'images/packingChecklist/')) {
                return url('/' . $clean);
            }
            if (str_starts_with($clean, 'packing_checklists/')) {
                return Storage::disk('public')->url($clean);
            }
            return url('/images/packingChecklist/' . basename($clean));
        };

        return [
            'id' => $this->id,
            'work_order_no' => $this->work_order_no,
            'wd_part_no' => $this->wd_part_no,
            'double_bag_checklist' => $this->double_bag_checklist,
            'quantity_verification' => $this->quantity_verification,
            'roll_per_box' => $this->roll_per_box,
            'ul_label_image' => $this->ul_label_image ? basename($this->ul_label_image) : null,
            'ul_label_image_url' => $buildUrl($this->ul_label_image),
            'product_image' => $this->product_image ? basename($this->product_image) : null,
            'product_image_url' => $buildUrl($this->product_image),
            'core_image' => $this->core_image ? basename($this->core_image) : null,
            'core_image_url' => $buildUrl($this->core_image),
            'carton_label_data' => $this->carton_label_data,
            'carton_label_image' => $this->carton_label_image ? basename($this->carton_label_image) : null,
            'carton_label_image_url' => $buildUrl($this->carton_label_image),
            'no_of_cartons' => $this->no_of_cartons,
            'user_id' => $this->user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
