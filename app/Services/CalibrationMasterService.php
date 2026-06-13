<?php

namespace App\Services;

use App\Http\Resources\CalibrationMaster\CalibrationMasterResource;
use App\Models\CalibrationMaster;
use App\Repositories\Contracts\CalibrationMasterRepositoryInterface;
use App\Services\Contracts\CalibrationMasterServiceInterface;
use App\Support\CalibrationSchedule;

class CalibrationMasterService implements CalibrationMasterServiceInterface
{
    public function __construct(
        protected CalibrationMasterRepositoryInterface $calibrationMasterRepository
    ) {
    }

    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        $paginator = $this->calibrationMasterRepository->listing($filters, $order, $limit, $page);

        return [
            'data' => CalibrationMasterResource::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'insights' => $this->insights(),
            'filter_options' => [
                'sheet_names' => CalibrationMaster::query()->whereNotNull('sheet_name')->distinct()->orderBy('sheet_name')->pluck('sheet_name')->values(),
                'functions' => CalibrationMaster::query()->whereNotNull('function')->distinct()->orderBy('function')->pluck('function')->values(),
                'owners' => CalibrationMaster::query()->whereNotNull('owner_location')->distinct()->orderBy('owner_location')->pluck('owner_location')->values(),
                'frequency_labels' => CalibrationMaster::query()->whereNotNull('frequency_label')->distinct()->orderBy('frequency_label')->pluck('frequency_label')->values(),
            ],
        ];
    }

    public function detail(int $id): array
    {
        return (new CalibrationMasterResource(
            $this->calibrationMasterRepository->findById($id)
        ))->response()->getData(true);
    }

    public function create(array $data): array
    {
        $record = $this->calibrationMasterRepository->create($this->normalizePayload($data));

        return (new CalibrationMasterResource($record))->response()->getData(true);
    }

    public function update(int $id, array $data): array
    {
        $updated = (bool) $this->calibrationMasterRepository->update($id, $this->normalizePayload($data));

        if (! $updated) {
            return [];
        }

        return (new CalibrationMasterResource(
            $this->calibrationMasterRepository->findById($id)
        ))->response()->getData(true);
    }

    public function delete(int $id): bool
    {
        return $this->calibrationMasterRepository->delete($id);
    }

    public function insights(int $nearDays = 30): array
    {
        return $this->calibrationMasterRepository->insights($nearDays);
    }

    protected function normalizePayload(array $data): array
    {
        $frequencyLabel = CalibrationSchedule::clean($data['frequency_label'] ?? null);
        $frequencyIntervalMonths = CalibrationSchedule::parseFrequencyIntervalMonths($frequencyLabel);
        $lastCalibrationDate = CalibrationSchedule::parseDate($data['last_calibration_date'] ?? null);
        $nextCalibrationDate = CalibrationSchedule::computeNextCalibrationDate($lastCalibrationDate, $frequencyIntervalMonths);

        return [
            'sheet_name' => CalibrationSchedule::clean($data['sheet_name'] ?? null),
            'sheet_order' => $data['sheet_order'] ?? 0,
            'source_row' => $data['source_row'] ?? null,
            'reference_no' => CalibrationSchedule::clean($data['reference_no'] ?? null),
            'name_type' => CalibrationSchedule::clean($data['name_type'] ?? null),
            'function' => CalibrationSchedule::clean($data['function'] ?? null),
            'image' => CalibrationSchedule::clean($data['image'] ?? null),
            'identification_number' => CalibrationSchedule::clean($data['identification_number'] ?? null),
            'measurement_range' => CalibrationSchedule::clean($data['measurement_range'] ?? null),
            'inherent_accuracy' => CalibrationSchedule::clean($data['inherent_accuracy'] ?? null),
            'usage_accuracy' => CalibrationSchedule::clean($data['usage_accuracy'] ?? null),
            'owner_location' => CalibrationSchedule::clean($data['owner_location'] ?? null),
            'frequency_label' => $frequencyLabel,
            'frequency_interval_months' => $frequencyIntervalMonths,
            'last_calibration_date' => $lastCalibrationDate?->toDateString(),
            'next_calibration_date' => $nextCalibrationDate?->toDateString(),
            'metadata' => is_array($data['metadata'] ?? null) ? $data['metadata'] : null,
        ];
    }
}
