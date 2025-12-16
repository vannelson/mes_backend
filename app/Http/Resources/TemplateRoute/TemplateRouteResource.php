<?php

namespace App\Http\Resources\TemplateRoute;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateRouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'template' => $this->template,
            'user_id' => $this->user_id,
            'metadata' => $this->metadata,
            'manager' => $this->whenLoaded('manager'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
