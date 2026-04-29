<?php

namespace App\Http\Resources\TemplateRoute;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateRouteOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template' => $this->template,
            'customer_part_no' => $this->customer_part_no,
            'template_route_version' => (int) ($this->template_route_version ?? 1),
            'is_active' => (bool) ($this->is_active ?? true),
            'batch_number' => $this->batch_number,
            'sheet' => $this->sheet,
            'route_name_sequence_key' => $this->route_name_sequence_key,
            'route_sequence_with_machines' => $this->route_sequence_with_machines,
            'work_orders_count' => $this->when(isset($this->work_orders_count), (int) $this->work_orders_count),
        ];
    }
}
