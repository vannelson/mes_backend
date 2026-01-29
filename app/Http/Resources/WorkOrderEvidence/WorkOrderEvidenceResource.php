<?php

namespace App\Http\Resources\WorkOrderEvidence;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class WorkOrderEvidenceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $path = $this->image_path;
        $url = null;
        if ($path) {
            $clean = ltrim($path, '/');
            if (str_starts_with($clean, 'images/evidence_image/')) {
                $url = url("/{$clean}");
            } elseif (str_starts_with($clean, 'evidence_image/')) {
                $url = url("/images/{$clean}");
            } else {
                $url = Storage::disk('public')->url($path);
            }
        }

        return [
            'id' => $this->id,
            'work_order_no' => $this->work_order_no,
            'route_name' => $this->route_name,
            'image_path' => $path,
            'image_url' => $url,
            'original_name' => $this->original_name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
