<?php

namespace App\Http\Resources\WorkOrderComment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_order_id' => $this->work_order_id,
            'thread_id' => $this->thread_id,
            'parent_id' => $this->parent_id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user?->id,
                'firstname' => $this->user?->firstname,
                'lastname' => $this->user?->lastname,
                'email' => $this->user?->email,
            ]),
            'type' => $this->type,
            'visibility' => $this->visibility,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
            'attachments' => $this->attachments,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
