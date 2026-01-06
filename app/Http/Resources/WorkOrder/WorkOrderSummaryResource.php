<?php

namespace App\Http\Resources\WorkOrder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_route_id' => $this->template_route_id,
            'work_order_no' => $this->work_order_no,
            'customer_code' => $this->customer_code,
            'customer_name' => $this->customer_name,
            'sheet' => $this->sheet,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
