<?php

namespace App\Http\Resources\TemplateRoute;

use App\Http\Resources\WorkOrder\WorkOrderSummaryResource;
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
            'wod_ref' => $this->wod_ref,
            'batch_number' => $this->batch_number,
            'sheet' => $this->sheet,
            'user_id' => $this->user_id,
            'metadata' => $this->metadata,
            'manager' => $this->whenLoaded('manager'),
            'work_orders_count' => $this->when(isset($this->work_orders_count), (int) $this->work_orders_count),
            'work_orders' => WorkOrderSummaryResource::collection($this->whenLoaded('workOrders')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
