<?php

namespace App\Http\Resources\WorkOrderNotification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'action_url' => $this->action_url,
            'data' => $this->data ?? [],
            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'actor' => $this->whenLoaded('actor', fn () => [
                'id' => $this->actor?->id,
                'firstname' => $this->actor?->firstname,
                'lastname' => $this->actor?->lastname,
                'email' => $this->actor?->email,
                'user_type' => $this->actor?->user_type,
                'picture_url' => $this->actor?->picture_url,
            ]),
            'work_order' => $this->whenLoaded('workOrder', fn () => [
                'id' => $this->workOrder?->id,
                'work_order_no' => $this->workOrder?->work_order_no,
            ]),
        ];
    }
}
