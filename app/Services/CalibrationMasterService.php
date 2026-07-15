<?php

namespace App\Services;

use App\Http\Resources\CalibrationMaster\CalibrationMasterHistoryResource;
use App\Http\Resources\CalibrationMaster\CalibrationMasterImageResource;
use App\Http\Resources\CalibrationMaster\CalibrationMasterResource;
use App\Models\CalibrationMaster;
use App\Models\CalibrationMasterImage;
use App\Repositories\Contracts\CalibrationMasterRepositoryInterface;
use App\Services\Contracts\CalibrationMasterServiceInterface;
use App\Services\CalibrationSlackAlertService;
use App\Support\CalibrationSchedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CalibrationMasterService implements CalibrationMasterServiceInterface
{
    public function __construct(
        protected CalibrationMasterRepositoryInterface $calibrationMasterRepository
    ) {}

    public function getList(array $filters = [], array $order = [], int $limit = 10, int $page = 1): array
    {
        $paginator = $this->calibrationMasterRepository->listing($filters, $order, $limit, $page);

        return [
            'data' => CalibrationMasterResource::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
            'insights'       => $this->insights(),
            'filter_options' => [
                'sheet_names'      => CalibrationMaster::query()->whereNotNull('sheet_name')->distinct()->orderBy('sheet_name')->pluck('sheet_name')->values(),
                'functions'        => CalibrationMaster::query()->whereNotNull('function')->distinct()->orderBy('function')->pluck('function')->values(),
                'owners'           => CalibrationMaster::query()->whereNotNull('owner_location')->distinct()->orderBy('owner_location')->pluck('owner_location')->values(),
                'frequency_labels' => CalibrationMaster::query()->whereNotNull('frequency_label')->distinct()->orderBy('frequency_label')->pluck('frequency_label')->values(),
            ],
        ];
    }

    public function detail(int $id): array
    {
        $record = $this->calibrationMasterRepository->findById($id)
            ->load(['images'])
            ->loadCount('images');

        return (new CalibrationMasterResource($record))->response()->getData(true);
    }

    public function create(array $data): array
    {
        $record = $this->calibrationMasterRepository->create($this->normalizePayload($data));

        return (new CalibrationMasterResource(
            $record->load(['images'])->loadCount('images')
        ))->response()->getData(true);
    }

    public function update(int $id, array $data): array
    {
        $updated = (bool) $this->calibrationMasterRepository->update($id, $this->normalizePayload($data));

        if (! $updated) {
            return [];
        }

        $record = $this->calibrationMasterRepository->findById($id)
            ->load(['images'])
            ->loadCount('images');

        return (new CalibrationMasterResource($record))->response()->getData(true);
    }

    public function delete(int $id): bool
    {
        return $this->calibrationMasterRepository->delete($id);
    }

    public function insights(int $nearDays = 30): array
    {
        return $this->calibrationMasterRepository->insights($nearDays);
    }

    // ── Status-only patch ─────────────────────────────────────────────────────

    public function updateStatus(int $id, ?string $calStatus): array
    {
        $record = CalibrationMaster::findOrFail($id);

        $metadata = is_array($record->metadata) ? $record->metadata : [];
        if ($calStatus === null) {
            unset($metadata['cal_status']);
        } else {
            $metadata['cal_status'] = $calStatus;
        }

        $record->update(['metadata' => $metadata]);

        $fresh = $record->fresh()->load(['images'])->loadCount('images');

        CalibrationSlackAlertService::statusChanged(
            $fresh,
            $calStatus,
            CalibrationSlackAlertService::resolveUserName()
        );

        return (new CalibrationMasterResource($fresh))->response()->getData(true);
    }

    // ── History ───────────────────────────────────────────────────────────────

    public function getHistory(int $id): array
    {
        $record = CalibrationMaster::findOrFail($id);

        $history = $record->histories()->with('loggedByUser')->get();

        return CalibrationMasterHistoryResource::collection($history)->resolve();
    }

    public function addHistory(int $id, array $data, ?UploadedFile $certFile, ?int $loggedBy): array
    {
        $record = CalibrationMaster::findOrFail($id);

        $certFilePath = null;
        $certFileName = null;
        $certMimeType = null;

        if ($certFile) {
            $certFileName = $certFile->getClientOriginalName();
            $certMimeType = $certFile->getMimeType();

            $destDir = public_path('images/calibration-certs');
            if (! File::isDirectory($destDir)) {
                File::makeDirectory($destDir, 0755, true);
            }
            $fileName = Str::uuid() . '.' . ($certFile->getClientOriginalExtension() ?: 'pdf');
            $certFile->move($destDir, $fileName);
            $certFilePath = '/images/calibration-certs/' . $fileName;
        }

        $entry = $record->histories()->create([
            'calibration_date' => $data['calibration_date'],
            'cert_no'          => $data['cert_no']      ?? null,
            'performed_by'     => $data['performed_by'] ?? null,
            'notes'            => $data['notes']        ?? null,
            'cert_file_path'   => $certFilePath,
            'cert_file_name'   => $certFileName,
            'cert_mime_type'   => $certMimeType,
            'logged_by'        => $loggedBy,
        ]);

        // Update parent record: new last_calibration_date → recalculate next due → clear cal_status
        $newLastDate    = CalibrationSchedule::parseDate($data['calibration_date']);
        $nextDate       = CalibrationSchedule::computeNextCalibrationDate(
            $newLastDate,
            $record->frequency_interval_months
        );

        $metadata = is_array($record->metadata) ? $record->metadata : [];
        unset($metadata['cal_status']);

        $record->update([
            'last_calibration_date' => $newLastDate?->toDateString(),
            'next_calibration_date' => $nextDate?->toDateString(),
            'metadata'              => $metadata,
        ]);

        CalibrationSlackAlertService::calibrationRecorded(
            $record->fresh(),
            CalibrationSlackAlertService::resolveUserName(),
            $newLastDate?->format('j M Y')
        );

        return (new CalibrationMasterHistoryResource(
            $entry->load('loggedByUser')
        ))->resolve();
    }

    // ── Images ────────────────────────────────────────────────────────────────

    public function uploadImage(int $id, UploadedFile $file): array
    {
        $record = CalibrationMaster::findOrFail($id);

        $width  = null;
        $height = null;

        if (str_starts_with((string) ($file->getMimeType() ?? ''), 'image/')) {
            try {
                [$width, $height] = getimagesize($file->getPathname()) ?: [null, null];
            } catch (\Throwable) {}
        }

        // Store in public/images/calibration-masters/ — same location as existing imported images
        $destDir = public_path('images/calibration-masters');
        if (! File::isDirectory($destDir)) {
            File::makeDirectory($destDir, 0755, true);
        }

        $ext      = $file->getClientOriginalExtension() ?: 'jpg';
        $fileName = Str::uuid() . '.' . $ext;
        $file->move($destDir, $fileName);

        $filePath  = '/images/calibration-masters/' . $fileName;
        $sortOrder = ($record->images()->max('sort_order') ?? 0) + 1;

        $image = $record->images()->create([
            'file_name'  => $file->getClientOriginalName(),
            'file_path'  => $filePath,
            'mime_type'  => $file->getMimeType(),
            'width'      => $width,
            'height'     => $height,
            'sort_order' => $sortOrder,
        ]);

        return (new CalibrationMasterImageResource($image))->resolve();
    }

    public function deleteImage(int $id, int $imageId): void
    {
        $image = CalibrationMasterImage::where('calibration_master_id', $id)->findOrFail($imageId);

        if ($image->file_path) {
            $absPath = public_path(ltrim($image->file_path, '/'));
            if (File::exists($absPath)) {
                File::delete($absPath);
            }
        }

        $image->delete();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    protected function normalizePayload(array $data): array
    {
        $frequencyLabel          = CalibrationSchedule::clean($data['frequency_label'] ?? null);
        $frequencyIntervalMonths = CalibrationSchedule::parseFrequencyIntervalMonths($frequencyLabel);
        $lastCalibrationDate     = CalibrationSchedule::parseDate($data['last_calibration_date'] ?? null);
        $nextCalibrationDate     = CalibrationSchedule::computeNextCalibrationDate($lastCalibrationDate, $frequencyIntervalMonths);

        $payload = [
            'sheet_name'                => CalibrationSchedule::clean($data['sheet_name']            ?? null),
            'sheet_order'               => $data['sheet_order']  ?? 0,
            'source_row'                => $data['source_row']   ?? null,
            'reference_no'              => CalibrationSchedule::clean($data['reference_no']          ?? null),
            'name_type'                 => CalibrationSchedule::clean($data['name_type']             ?? null),
            'function'                  => CalibrationSchedule::clean($data['function']              ?? null),
            'identification_number'     => CalibrationSchedule::clean($data['identification_number'] ?? null),
            'measurement_range'         => CalibrationSchedule::clean($data['measurement_range']     ?? null),
            'inherent_accuracy'         => CalibrationSchedule::clean($data['inherent_accuracy']     ?? null),
            'usage_accuracy'            => CalibrationSchedule::clean($data['usage_accuracy']        ?? null),
            'owner_location'            => CalibrationSchedule::clean($data['owner_location']        ?? null),
            'frequency_label'           => $frequencyLabel,
            'frequency_interval_months' => $frequencyIntervalMonths,
            'last_calibration_date'     => $lastCalibrationDate?->toDateString(),
            'next_calibration_date'     => $nextCalibrationDate?->toDateString(),
            'metadata'                  => is_array($data['metadata'] ?? null) ? $data['metadata'] : null,
        ];

        if (array_key_exists('image', $data)) {
            $payload['image'] = CalibrationSchedule::clean($data['image'] ?? null);
        }

        return $payload;
    }
}
