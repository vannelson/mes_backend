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
            'priority_type' => $this->priority_type,
            'date' => $this->date,
            'posted_date' => $this->posted_date,
            'document_date' => $this->document_date,
            'due_date' => $this->due_date,
            'date_printed' => $this->date_printed,
            'document_type' => $this->document_type,
            'printed_by' => $this->printed_by,
            'qr_code' => $this->qr_code,
            'size' => $this->size,
            'websize' => $this->websize,
            'material' => $this->material,
            'colours' => $this->colours,
            'butt' => $this->butt,
            'interval' => $this->interval,
            'radius' => $this->radius,
            'material_not_number' => $this->material_not_number,
            'operator_code' => $this->operator_code,
            'number_of_used_up' => $this->number_of_used_up,
            'number_of_process' => $this->number_of_process,
            'meta_remarks' => $this->meta_remarks,
            'metadata' => $this->metadata,
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'template_route' => TemplateRouteResource::make($this->whenLoaded('templateRoute')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
