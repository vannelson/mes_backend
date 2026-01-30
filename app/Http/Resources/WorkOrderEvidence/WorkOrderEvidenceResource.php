<?php

namespace App\Http\Resources\WorkOrderEvidence;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderEvidenceResource extends JsonResource
{
    protected function resolveImageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        $clean = ltrim($path, '/');
        if (str_starts_with($clean, 'images/')) {
            $clean = substr($clean, strlen('images/'));
        }
        return url("/api/v1/images/{$clean}");
    }

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $path = $this->image_path;
        $url = $this->resolveImageUrl($path);

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
