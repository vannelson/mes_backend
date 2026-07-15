<?php

namespace App\Http\Resources\CalibrationMaster;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalibrationMasterHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'calibration_date' => $this->calibration_date?->toDateString(),
            'cert_no'          => $this->cert_no,
            'performed_by'     => $this->performed_by,
            'notes'            => $this->notes,
            'cert_file_name'   => $this->cert_file_name,
            'cert_file_url'    => $this->cert_file_path
                ? asset('storage/' . $this->cert_file_path)
                : null,
            'logged_by'        => $this->logged_by,
            'logged_by_name'   => $this->whenLoaded(
                'loggedByUser',
                fn () => $this->loggedByUser?->name
            ),
            'created_at'       => $this->created_at?->toISOString(),
        ];
    }
}
