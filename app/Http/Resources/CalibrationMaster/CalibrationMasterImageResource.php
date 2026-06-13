<?php

namespace App\Http\Resources\CalibrationMaster;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalibrationMasterImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sort_order' => $this->sort_order,
            'file_name' => $this->file_name,
            'file_path' => $this->file_path,
            'mime_type' => $this->mime_type,
            'width' => $this->width,
            'height' => $this->height,
            'source_sheet_name' => $this->source_sheet_name,
            'source_row' => $this->source_row,
            'source_col' => $this->source_col,
            'metadata' => $this->metadata,
        ];
    }
}
