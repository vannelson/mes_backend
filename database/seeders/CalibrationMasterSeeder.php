<?php

namespace Database\Seeders;

use App\Models\CalibrationMaster;
use App\Models\CalibrationMasterImage;
use App\Support\CalibrationSchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\BaseDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CalibrationMasterSeeder extends Seeder
{
    protected const HEADER_ROW = 5;

    public function run(): void
    {
        $filePath = base_path('../Calibration Master List.xlsx');
        if (! is_file($filePath)) {
            $this->command?->warn("Calibration workbook not found: {$filePath}");
            return;
        }

        $spreadsheet = IOFactory::load($filePath);
        $imageTargetDir = public_path('images/calibration-masters');
        if (File::isDirectory($imageTargetDir)) {
            foreach (File::files($imageTargetDir) as $file) {
                File::delete($file->getPathname());
            }
        } else {
            File::makeDirectory($imageTargetDir, 0755, true, true);
        }

        DB::transaction(function () use ($spreadsheet, $imageTargetDir): void {
            CalibrationMasterImage::query()->delete();
            CalibrationMaster::query()->delete();

            foreach ($spreadsheet->getWorksheetIterator() as $sheetIndex => $worksheet) {
                $this->seedWorksheet($worksheet, $sheetIndex + 1, $imageTargetDir);
            }
        });
    }

    protected function seedWorksheet(Worksheet $worksheet, int $sheetOrder, string $imageTargetDir): void
    {
        $highestRow = $worksheet->getHighestRow();
        $rowImages = $this->extractWorksheetImages($worksheet, $sheetOrder, $imageTargetDir);
        $context = [
            'name_type' => null,
            'function' => null,
            'owner_location' => null,
            'frequency_label' => null,
            'last_calibration_date' => null,
        ];

        for ($row = self::HEADER_ROW + 1; $row <= $highestRow; $row++) {
            $cells = [
                'name_type' => CalibrationSchedule::clean($worksheet->getCell("A{$row}")->getFormattedValue()),
                'function' => CalibrationSchedule::clean($worksheet->getCell("B{$row}")->getFormattedValue()),
                'image' => CalibrationSchedule::clean($worksheet->getCell("C{$row}")->getFormattedValue()),
                'identification_number' => CalibrationSchedule::clean($worksheet->getCell("D{$row}")->getFormattedValue()),
                'measurement_range' => CalibrationSchedule::clean($worksheet->getCell("E{$row}")->getFormattedValue()),
                'inherent_accuracy' => CalibrationSchedule::clean($worksheet->getCell("F{$row}")->getFormattedValue()),
                'usage_accuracy' => CalibrationSchedule::clean($worksheet->getCell("G{$row}")->getFormattedValue()),
                'owner_location' => CalibrationSchedule::clean($worksheet->getCell("H{$row}")->getFormattedValue()),
                'frequency_label' => CalibrationSchedule::clean($worksheet->getCell("I{$row}")->getFormattedValue()),
                'last_calibration_source' => $worksheet->getCell("J{$row}")->getValue(),
                'reference_no' => CalibrationSchedule::clean($worksheet->getCell("L{$row}")->getFormattedValue()),
            ];

            $anchoredImages = $rowImages[$row] ?? [];
            $hasMeaningfulData = collect($cells)
                ->except(['image', 'last_calibration_source'])
                ->filter(fn ($value) => $value !== null)
                ->isNotEmpty()
                || CalibrationSchedule::parseDate($cells['last_calibration_source']) !== null
                || ! empty($anchoredImages);

            if (! $hasMeaningfulData) {
                continue;
            }

            foreach (['name_type', 'function', 'owner_location', 'frequency_label'] as $carryField) {
                if ($cells[$carryField]) {
                    $context[$carryField] = $cells[$carryField];
                } elseif ($context[$carryField]) {
                    $cells[$carryField] = $context[$carryField];
                }
            }

            $lastCalibrationDate = CalibrationSchedule::parseDate($cells['last_calibration_source']);
            if ($lastCalibrationDate) {
                $context['last_calibration_date'] = $lastCalibrationDate->toDateString();
            } elseif ($context['last_calibration_date']) {
                $lastCalibrationDate = CalibrationSchedule::parseDate($context['last_calibration_date']);
            }

            if (! $cells['name_type'] && ! $cells['identification_number'] && ! $cells['measurement_range'] && empty($anchoredImages)) {
                continue;
            }

            $frequencyIntervalMonths = CalibrationSchedule::parseFrequencyIntervalMonths($cells['frequency_label']);
            $nextCalibrationDate = CalibrationSchedule::computeNextCalibrationDate($lastCalibrationDate, $frequencyIntervalMonths);
            $primaryImagePath = $anchoredImages[0]['file_path'] ?? CalibrationSchedule::clean($cells['image']);

            $record = CalibrationMaster::query()->create([
                'sheet_name' => $worksheet->getTitle(),
                'sheet_order' => $sheetOrder,
                'source_row' => $row,
                'reference_no' => $cells['reference_no'],
                'name_type' => $cells['name_type'],
                'function' => $cells['function'],
                'image' => $primaryImagePath,
                'identification_number' => $cells['identification_number'],
                'measurement_range' => $cells['measurement_range'],
                'inherent_accuracy' => $cells['inherent_accuracy'],
                'usage_accuracy' => $cells['usage_accuracy'],
                'owner_location' => $cells['owner_location'],
                'frequency_label' => $cells['frequency_label'],
                'frequency_interval_months' => $frequencyIntervalMonths,
                'last_calibration_date' => $lastCalibrationDate?->toDateString(),
                'next_calibration_date' => $nextCalibrationDate?->toDateString(),
                'metadata' => [
                    'workbook' => 'Calibration Master List.xlsx',
                    'sheet_title' => $worksheet->getTitle(),
                    'image_count' => count($anchoredImages),
                ],
            ]);

            foreach ($anchoredImages as $index => $image) {
                $record->images()->create([
                    'sort_order' => $index + 1,
                    'file_name' => $image['file_name'],
                    'file_path' => $image['file_path'],
                    'mime_type' => $image['mime_type'],
                    'width' => $image['width'],
                    'height' => $image['height'],
                    'source_sheet_name' => $worksheet->getTitle(),
                    'source_row' => $row,
                    'source_col' => $image['source_col'],
                    'metadata' => [
                        'anchor_coordinate' => $image['anchor_coordinate'],
                    ],
                ]);
            }
        }
    }

    protected function extractWorksheetImages(Worksheet $worksheet, int $sheetOrder, string $imageTargetDir): array
    {
        $imagesByRow = [];
        $drawingCounter = 0;

        foreach ($worksheet->getDrawingCollection() as $drawing) {
            $drawingCounter++;
            $coordinate = $drawing->getCoordinates();
            [$column] = Coordinate::coordinateFromString($coordinate);
            [, $row] = Coordinate::indexesFromString($coordinate);

            $savedImage = $this->saveDrawing($drawing, $imageTargetDir, $worksheet->getTitle(), $sheetOrder, $row, $drawingCounter);
            if (! $savedImage) {
                continue;
            }

            $savedImage['source_col'] = Coordinate::columnIndexFromString($column);
            $savedImage['anchor_coordinate'] = $coordinate;
            $imagesByRow[$row] ??= [];
            $imagesByRow[$row][] = $savedImage;
        }

        return $imagesByRow;
    }

    protected function saveDrawing(
        BaseDrawing $drawing,
        string $imageTargetDir,
        string $sheetTitle,
        int $sheetOrder,
        int $row,
        int $drawingCounter
    ): ?array {
        $binary = null;
        $extension = null;
        $mimeType = null;

        if ($drawing instanceof MemoryDrawing) {
            $binary = $this->renderMemoryDrawing($drawing);
            $mimeType = $drawing->getMimeType();
            $extension = match ($mimeType) {
                MemoryDrawing::MIMETYPE_PNG => 'png',
                MemoryDrawing::MIMETYPE_GIF => 'gif',
                default => 'jpg',
            };
        } elseif ($drawing instanceof Drawing) {
            $path = $drawing->getPath();
            if ($path && (is_file($path) || str_starts_with($path, 'zip://'))) {
                $binary = file_get_contents($path) ?: null;
                $sourceName = str_contains($path, '#') ? substr($path, (int) strrpos($path, '#') + 1) : $path;
                $extension = strtolower(pathinfo($sourceName, PATHINFO_EXTENSION)) ?: 'jpg';
                $mimeType = $this->resolveMimeTypeFromExtension($extension);
            }
        }

        if (! $binary) {
            return null;
        }

        $safeSheet = str($sheetTitle)->slug()->value() ?: 'sheet';
        $fileName = sprintf('calibration_%s_r%s_%s.%s', $safeSheet, $row, $drawingCounter, $extension);
        File::put($imageTargetDir . DIRECTORY_SEPARATOR . $fileName, $binary);

        return [
            'file_name' => $fileName,
            'file_path' => '/images/calibration-masters/' . $fileName,
            'mime_type' => $mimeType,
            'width' => (int) $drawing->getWidth(),
            'height' => (int) $drawing->getHeight(),
        ];
    }

    protected function renderMemoryDrawing(MemoryDrawing $drawing): ?string
    {
        $resource = $drawing->getImageResource();
        if (! $resource) {
            return null;
        }

        ob_start();
        call_user_func($drawing->getRenderingFunction(), $resource);
        return ob_get_clean() ?: null;
    }

    protected function resolveMimeTypeFromExtension(string $extension): ?string
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'webp' => 'image/webp',
            default => null,
        };
    }
}
