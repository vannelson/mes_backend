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
            'customer_part_number_ref' => $this->customer_part_number_ref,
            'customer_part_no' => $this->customer_part_no,
            'template_route_version' => (int) ($this->template_route_version ?? 1),
            'is_active' => (bool) ($this->is_active ?? true),
            'parent_template_route_id' => $this->parent_template_route_id,
            'created_from_template_route_id' => $this->created_from_template_route_id,
            'batch_number' => $this->batch_number,
            'sheet' => $this->sheet,
            'user_id' => $this->user_id,
            'metadata' => $this->metadata,
            'route_name_sequence_key' => $this->route_name_sequence_key,
            'route_sequence_with_machines' => $this->route_sequence_with_machines,
            'manager' => $this->whenLoaded('manager'),
            'work_orders_count' => $this->when(isset($this->work_orders_count), (int) $this->work_orders_count),
            'work_orders' => WorkOrderSummaryResource::collection($this->whenLoaded('workOrders')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
