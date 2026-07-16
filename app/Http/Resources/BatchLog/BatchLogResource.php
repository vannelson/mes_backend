<?php

namespace App\Http\Resources\BatchLog;

use App\Http\Resources\User\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BatchLogResource extends JsonResource
{
    /**
     * Transform resource into array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'batch_no' => $this->batch_no,
            'type' => $this->type,
            'total_rows' => $this->total_rows,
            'inserted_rows' => $this->inserted_rows,
            'updated_rows' => $this->updated_rows,
            'skipped_rows' => $this->skipped_rows,
            'failed_rows' => $this->failed_rows,
            'operator' => $this->operator,
            'sheet' => $this->sheet,
            'user' => UserResource::make($this->whenLoaded('user')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
