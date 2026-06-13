<?php

namespace Database\Seeders;

use App\Models\CalibrationMaster;
use App\Support\CalibrationSchedule;
use Illuminate\Database\Seeder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CalibrationMasterSeeder extends Seeder
{
    protected const HEADER_ROW = 4;

    public function run(): void
    {
        $filePath = base_path('../Calibration Master List.xlsx');
        if (! is_file($filePath)) {
            $this->command?->warn("Calibration workbook not found: {$filePath}");
            return;
        }

        $spreadsheet = IOFactory::load($filePath);
        $rows = [];

        foreach ($spreadsheet->getWorksheetIterator() as $sheetIndex => $worksheet) {
            $rows = [...$rows, ...$this->extractSheetRows($worksheet, $sheetIndex + 1)];
        }

        CalibrationMaster::query()->truncate();

        foreach (array_chunk($rows, 200) as $chunk) {
            CalibrationMaster::query()->insert($chunk);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function extractSheetRows(Worksheet $worksheet, int $sheetOrder): array
    {
        $highestRow = $worksheet->getHighestRow();
        $context = [
            'name_type' => null,
            'function' => null,
            'image' => null,
            'owner_location' => null,
            'frequency_label' => null,
            'last_calibration_date' => null,
        ];

        $rows = [];

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

            $hasMeaningfulData = collect($cells)
                ->except(['image', 'last_calibration_source'])
                ->filter(fn ($value) => $value !== null)
                ->isNotEmpty()
                || CalibrationSchedule::parseDate($cells['last_calibration_source']) !== null;

            if (! $hasMeaningfulData) {
                continue;
            }

            foreach (['name_type', 'function', 'image', 'owner_location', 'frequency_label'] as $carryField) {
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

            if (! $cells['name_type'] && ! $cells['identification_number'] && ! $cells['measurement_range']) {
                continue;
            }

            $frequencyIntervalMonths = CalibrationSchedule::parseFrequencyIntervalMonths($cells['frequency_label']);
            $nextCalibrationDate = CalibrationSchedule::computeNextCalibrationDate($lastCalibrationDate, $frequencyIntervalMonths);

            $rows[] = [
                'sheet_name' => $worksheet->getTitle(),
                'sheet_order' => $sheetOrder,
                'source_row' => $row,
                'reference_no' => $cells['reference_no'],
                'name_type' => $cells['name_type'],
                'function' => $cells['function'],
                'image' => $cells['image'],
                'identification_number' => $cells['identification_number'],
                'measurement_range' => $cells['measurement_range'],
                'inherent_accuracy' => $cells['inherent_accuracy'],
                'usage_accuracy' => $cells['usage_accuracy'],
                'owner_location' => $cells['owner_location'],
                'frequency_label' => $cells['frequency_label'],
                'frequency_interval_months' => $frequencyIntervalMonths,
                'last_calibration_date' => $lastCalibrationDate?->toDateString(),
                'next_calibration_date' => $nextCalibrationDate?->toDateString(),
                'metadata' => json_encode([
                    'workbook' => 'Calibration Master List.xlsx',
                    'sheet_title' => $worksheet->getTitle(),
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }
}
