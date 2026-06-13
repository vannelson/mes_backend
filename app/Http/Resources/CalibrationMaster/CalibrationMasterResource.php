<?php

namespace App\Http\Resources\CalibrationMaster;

use App\Support\CalibrationSchedule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalibrationMasterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $today = Carbon::today();
        $nextCalibrationDate = $this->next_calibration_date ? Carbon::parse($this->next_calibration_date) : null;
        $daysUntilDue = $nextCalibrationDate ? $today->diffInDays($nextCalibrationDate, false) : null;

        return [
            'id' => $this->id,
            'sheet_name' => $this->sheet_name,
            'sheet_order' => $this->sheet_order,
            'source_row' => $this->source_row,
            'reference_no' => $this->reference_no,
            'name_type' => $this->name_type,
            'function' => $this->function,
            'image' => $this->image,
            'identification_number' => $this->identification_number,
            'measurement_range' => $this->measurement_range,
            'inherent_accuracy' => $this->inherent_accuracy,
            'usage_accuracy' => $this->usage_accuracy,
            'owner_location' => $this->owner_location,
            'frequency_label' => $this->frequency_label,
            'frequency_interval_months' => $this->frequency_interval_months,
            'last_calibration_date' => $this->last_calibration_date?->toDateString(),
            'next_calibration_date' => $this->next_calibration_date?->toDateString(),
            'days_until_due' => $daysUntilDue,
            'due_state' => CalibrationSchedule::dueState($nextCalibrationDate, $today),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
