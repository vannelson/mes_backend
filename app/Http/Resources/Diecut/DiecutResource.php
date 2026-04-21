<?php

namespace App\Http\Resources\Diecut;

use App\Services\DiecutIntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiecutResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $context = null;
        if ($request->boolean('include_diecut_context')) {
            try {
                $service = app(DiecutIntelligenceService::class);
                $profile = $service->resolveProfile($this->diecut_no, null, null);
                $context = [
                    'profile' => $profile ? [
                        'id' => $profile->id,
                        'profile_code' => $profile->profile_code,
                        'diecut_type' => $profile->diecut_type,
                        'height_mm' => $profile->height_mm,
                        'width_mm' => $profile->width_mm,
                        'interval_ud_mm' => $profile->interval_ud_mm,
                        'interval_lr_mm' => $profile->interval_lr_mm,
                        'column_count' => $profile->column_count,
                    ] : null,
                    'tooling' => $service->summarizeTooling($profile),
                ];
            } catch (\Throwable) {
                $context = null;
            }
        }

        return [
            'id' => $this->id,
            'batch_number' => $this->batch_number,
            'sheet' => $this->sheet,
            'diecut_no' => $this->diecut_no,
            'diecut_type' => $this->diecut_type,
            'width' => $this->width,
            'length' => $this->length,
            'no_of_ups' => $this->no_of_ups,
            'rev' => $this->rev,
            'radius' => $this->radius,
            'perforate' => $this->perforate,
            'int_ud' => $this->int_ud,
            'diecut_context' => $context,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
