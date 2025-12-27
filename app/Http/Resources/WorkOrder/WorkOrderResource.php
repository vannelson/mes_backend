<?php

namespace App\Http\Resources\WorkOrder;

use App\Http\Resources\Customer\CustomerResource;
use App\Http\Resources\TemplateRoute\TemplateRouteResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'template_route_id' => $this->template_route_id,
            'work_order_no' => $this->work_order_no,
            'batch_number' => $this->batch_number,
            'selected' => $this->selected,
            'mes_batch_no' => $this->mes_batch_no,
            'customer_code' => $this->customer_code,
            'customer_name' => $this->customer_name,
            'material_1_code' => $this->material_1_code,
            'material_2_code' => $this->material_2_code,
            'material_3_code' => $this->material_3_code,
            'material_4_code' => $this->material_4_code,
            'customer_part_number' => $this->customer_part_number,
            'production_due_date' => $this->production_due_date,
            'quantity_to_produce' => $this->quantity_to_produce,
            'quantity_produced' => $this->quantity_produced,
            'forecast_quantity' => $this->forecast_quantity,
            'die_cut' => $this->die_cut,
            'internal_remark' => $this->internal_remark,
            'requested_delivery_date' => $this->requested_delivery_date,
            'no_of_colours' => $this->no_of_colours,
            'sales_person_code' => $this->sales_person_code,
            'order_date' => $this->order_date,
            'production_date_completed' => $this->production_date_completed,
            'production_qty_completed' => $this->production_qty_completed,
            'qr_code' => $this->qr_code,
            'metadata' => $this->metadata,
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'template_route' => TemplateRouteResource::make($this->whenLoaded('templateRoute')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
