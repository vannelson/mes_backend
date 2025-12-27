<?php

namespace App\Http\Resources\BatchLog;

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
            'batch_no' => $this->batch_no,
            'total_rows' => $this->total_rows,
            'operator' => $this->operator,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
