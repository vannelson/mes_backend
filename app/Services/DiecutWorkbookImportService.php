<?php

namespace App\Services;

use App\Models\CustomerPartDiecutProfile;
use App\Models\DiecutProfile;
use App\Models\DiecutProfileAlias;
use App\Models\DiecutTool;
use App\Models\DiecutToolUsage;
use App\Models\Machine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DiecutWorkbookImportService
{
    public function __construct(
        protected DiecutIntelligenceService $intelligenceService
    ) {
    }

    public function importRoutingWorkbook(UploadedFile $file, ?string $batchNumber = null): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $batch = $batchNumber ?: now()->format('dmy\THi');
        $summary = [
            'profiles_upserted' => 0,
            'aliases_upserted' => 0,
            'customer_part_mappings_upserted' => 0,
            'machines_updated' => 0,
            'batch_number' => $batch,
            'sheets' => $spreadsheet->getSheetNames(),
        ];

        DB::transaction(function () use ($spreadsheet, $batch, &$summary) {
            if ($sheet = $spreadsheet->getSheetByName('Tooling Summary')) {
                $summary['profiles_upserted'] += $this->importToolingSummarySheet($sheet, $batch, $summary);
            }
            if ($sheet = $spreadsheet->getSheetByName('Item Summary')) {
                $summary['customer_part_mappings_upserted'] += $this->importItemSummarySheet($sheet, $batch, $summary);
            }
            if ($sheet = $spreadsheet->getSheetByName('Machine ')) {
                $summary['machines_updated'] += $this->importMachineSheet($sheet);
            }
        });

        return $summary;
    }

    public function importToolingWorkbook(UploadedFile $file, ?string $batchNumber = null): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $batch = $batchNumber ?: now()->format('dmy\THi');
        $summary = [
            'profiles_upserted' => 0,
            'aliases_upserted' => 0,
            'tools_created' => 0,
            'usage_rows_created' => 0,
            'batch_number' => $batch,
            'sheets' => $spreadsheet->getSheetNames(),
        ];

        DB::transaction(function () use ($spreadsheet, $batch, &$summary) {
            if ($sheet = $spreadsheet->getSheetByName('New')) {
                $summary['profiles_upserted'] += $this->importDefaultToolLifeSheet($sheet, $batch, $summary);
            }
            if ($sheet = $spreadsheet->getSheetByName('Active')) {
                $summary['tools_created'] += $this->importToolStatusSheet($sheet, $batch, 'active');
            }
            if ($sheet = $spreadsheet->getSheetByName('Discontinue')) {
                $summary['tools_created'] += $this->importToolStatusSheet($sheet, $batch, 'discontinue');
            }
            if ($sheet = $spreadsheet->getSheetByName('Diecut ( Usage Master List )')) {
                $summary['usage_rows_created'] += $this->importUsageSheet($sheet, $batch);
            }
        });

        return $summary;
    }

    protected function importToolingSummarySheet(Worksheet $sheet, string $batch, array &$summary): int
    {
        $created = 0;
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $profileCode = trim((string) $sheet->getCell("A{$row}")->getCalculatedValue());
            if ($profileCode === '' || strtolower($profileCode) === 'die cut') {
                continue;
            }

            $profile = $this->upsertProfile([
                'profile_code' => $profileCode,
                'height_mm' => $this->intelligenceService->toFloat($sheet->getCell("B{$row}")->getCalculatedValue()),
                'width_mm' => $this->intelligenceService->toFloat($sheet->getCell("C{$row}")->getCalculatedValue()),
                'interval_ud_mm' => $this->intelligenceService->toFloat($sheet->getCell("D{$row}")->getCalculatedValue()),
                'column_count' => $this->intelligenceService->toFloat($sheet->getCell("E{$row}")->getCalculatedValue()),
                'interval_lr_mm' => $this->intelligenceService->toFloat($sheet->getCell("F{$row}")->getCalculatedValue()),
                'source_sheet' => $sheet->getTitle(),
                'source_batch' => $batch,
                'metadata' => ['formula' => $sheet->getCell("G{$row}")->getValue()],
            ]);

            $this->upsertAlias($profile, $profileCode, 'routing_profile');
            $summary['aliases_upserted']++;
            $created++;
        }

        return $created;
    }

    protected function importItemSummarySheet(Worksheet $sheet, string $batch, array &$summary): int
    {
        $created = 0;
        for ($row = 3; $row <= $sheet->getHighestDataRow(); $row++) {
            $customerCode = trim((string) $sheet->getCell("E{$row}")->getCalculatedValue());
            $customerPartNumber = trim((string) $sheet->getCell("G{$row}")->getCalculatedValue());
            $profileCode = trim((string) $sheet->getCell("H{$row}")->getCalculatedValue());

            if ($customerPartNumber === '') {
                continue;
            }

            $profile = null;
            if ($profileCode !== '') {
                $profile = $this->upsertProfile([
                    'profile_code' => $profileCode,
                    'height_mm' => $this->intelligenceService->toFloat($sheet->getCell("I{$row}")->getCalculatedValue()),
                    'width_mm' => $this->intelligenceService->toFloat($sheet->getCell("J{$row}")->getCalculatedValue()),
                    'interval_ud_mm' => $this->intelligenceService->toFloat($sheet->getCell("K{$row}")->getCalculatedValue()),
                    'column_count' => $this->intelligenceService->toFloat($sheet->getCell("L{$row}")->getCalculatedValue()),
                    'interval_lr_mm' => $this->intelligenceService->toFloat($sheet->getCell("M{$row}")->getCalculatedValue()),
                    'no_of_ups' => $this->intelligenceService->toFloat($sheet->getCell("L{$row}")->getCalculatedValue()),
                    'source_sheet' => $sheet->getTitle(),
                    'source_batch' => $batch,
                ]);
                $this->upsertAlias($profile, $profileCode, 'item_summary');
                $summary['aliases_upserted']++;
            } else {
                $profile = $this->intelligenceService->resolveProfile(null, $customerPartNumber, $customerCode);
            }

            if (!$profile) {
                continue;
            }

            CustomerPartDiecutProfile::query()->updateOrCreate(
                [
                    'normalized_customer_part_number' => $this->intelligenceService->normalizeCustomerPart($customerPartNumber),
                    'diecut_profile_id' => $profile->id,
                ],
                [
                    'customer_code' => $customerCode !== '' ? $customerCode : null,
                    'customer_part_number' => $customerPartNumber,
                    'source_sheet' => $sheet->getTitle(),
                    'source_batch' => $batch,
                    'metadata' => ['row' => $row],
                ]
            );

            $created++;
        }

        return $created;
    }

    protected function importMachineSheet(Worksheet $sheet): int
    {
        $updated = 0;
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $machineNo = trim((string) $sheet->getCell("A{$row}")->getCalculatedValue());
            $machineName = trim((string) $sheet->getCell("B{$row}")->getCalculatedValue());
            $machineType = trim((string) $sheet->getCell("C{$row}")->getCalculatedValue());
            $speed = $this->intelligenceService->toFloat($sheet->getCell("D{$row}")->getCalculatedValue());
            if (($machineNo === '' && $machineName === '') || $speed === null) {
                continue;
            }

            $machine = Machine::query()
                ->where(function ($query) use ($machineNo, $machineName) {
                    if ($machineNo !== '') {
                        $query->where('machine_no', $machineNo);
                    }
                    if ($machineName !== '') {
                        $machineNo !== ''
                            ? $query->orWhere('machine_name', $machineName)
                            : $query->where('machine_name', $machineName);
                    }
                })
                ->first();

            if ($machine) {
                $machine->update([
                    'machine_no' => $machine->machine_no ?: ($machineNo !== '' ? $machineNo : null),
                    'machine_name' => $machine->machine_name ?: ($machineName !== '' ? $machineName : null),
                    'machine_type' => $machine->machine_type ?: ($machineType !== '' ? $machineType : null),
                    'average_speed' => (string) $speed,
                ]);
            } else {
                Machine::query()->create([
                    'production_area' => 'DIECUT',
                    'machine_no' => $machineNo !== '' ? $machineNo : null,
                    'machine_name' => $machineName !== '' ? $machineName : null,
                    'machine_type' => $machineType !== '' ? $machineType : ($machineName !== '' ? $machineName : 'DIECUT'),
                    'average_speed' => (string) $speed,
                    'metadata' => [
                        'source' => 'diecut_routing_workbook',
                        'source_sheet' => $sheet->getTitle(),
                        'source_row' => $row,
                    ],
                ]);
            }

            $updated++;
        }

        return $updated;
    }

    protected function importDefaultToolLifeSheet(Worksheet $sheet, string $batch, array &$summary): int
    {
        $created = 0;
        for ($row = 3; $row <= $sheet->getHighestDataRow(); $row++) {
            $profileCode = trim((string) $sheet->getCell("A{$row}")->getCalculatedValue());
            if ($profileCode === '') {
                continue;
            }

            $profile = $this->upsertProfile([
                'profile_code' => $profileCode,
                'default_tool_life_pcs' => $this->intelligenceService->toFloat($sheet->getCell("C{$row}")->getCalculatedValue()),
                'default_tool_life_press' => $this->intelligenceService->toFloat($sheet->getCell("D{$row}")->getCalculatedValue()),
                'source_sheet' => $sheet->getTitle(),
                'source_batch' => $batch,
                'status' => 'tooling_master',
            ]);
            $this->upsertAlias($profile, $profileCode, 'tooling_master');
            $summary['aliases_upserted']++;
            $created++;
        }

        return $created;
    }

    protected function importToolStatusSheet(Worksheet $sheet, string $batch, string $status): int
    {
        $created = 0;
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $toolCode = trim((string) $sheet->getCell("A{$row}")->getCalculatedValue());
            if ($toolCode === '') {
                continue;
            }

            $profile = $this->intelligenceService->resolveProfile($toolCode, null, null);

            DiecutTool::query()->create([
                'diecut_profile_id' => $profile?->id,
                'tool_code' => $toolCode,
                'normalized_tool_code' => $this->intelligenceService->normalizeCode($toolCode),
                'base_normalized_tool_code' => $this->intelligenceService->normalizeBaseCode($toolCode),
                'cavity' => $this->intelligenceService->toFloat($sheet->getCell("B{$row}")->getCalculatedValue()),
                'tool_life_pcs' => $this->intelligenceService->toFloat($sheet->getCell("C{$row}")->getCalculatedValue()),
                'tool_life_press' => $this->intelligenceService->toFloat($sheet->getCell("D{$row}")->getCalculatedValue()),
                'status' => $status,
                'is_active' => $status === 'active',
                'received_date' => $this->toDate($sheet->getCell("G{$row}")->getCalculatedValue()),
                'start_date' => $this->toDate($sheet->getCell("I{$row}")->getCalculatedValue()),
                'return_date' => $this->toDate($sheet->getCell("J{$row}")->getCalculatedValue()),
                'source_sheet' => $sheet->getTitle(),
                'source_batch' => $batch,
                'remarks' => $this->nullableString($sheet->getCell("K{$row}")->getCalculatedValue()),
                'metadata' => ['row' => $row],
            ]);

            $created++;
        }

        return $created;
    }

    protected function importUsageSheet(Worksheet $sheet, string $batch): int
    {
        $created = 0;
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $toolCode = trim((string) $sheet->getCell("A{$row}")->getCalculatedValue());
            if ($toolCode === '') {
                continue;
            }

            $normalizedToolCode = $this->intelligenceService->normalizeCode($toolCode);
            $baseNormalized = $this->intelligenceService->normalizeBaseCode($toolCode);
            $tool = DiecutTool::query()
                ->where('normalized_tool_code', $normalizedToolCode)
                ->orWhere(function ($query) use ($baseNormalized) {
                    if ($baseNormalized !== '') {
                        $query->where('base_normalized_tool_code', $baseNormalized);
                    }
                })
                ->orderByDesc('is_active')
                ->first();

            $profile = $tool?->profile ?: $this->intelligenceService->resolveProfile($toolCode, null, null);

            DiecutToolUsage::query()->create([
                'diecut_tool_id' => $tool?->id,
                'diecut_profile_id' => $profile?->id,
                'usage_date' => $this->toDate($sheet->getCell("B{$row}")->getCalculatedValue()),
                'machine_no' => $this->nullableString($sheet->getCell("C{$row}")->getCalculatedValue()),
                'customer_code' => $this->nullableString($sheet->getCell("D{$row}")->getCalculatedValue()),
                'work_order_no' => $this->nullableString($sheet->getCell("E{$row}")->getCalculatedValue()),
                'customer_part_number' => $this->nullableString($sheet->getCell("F{$row}")->getCalculatedValue()),
                'cavity' => $this->intelligenceService->toFloat($sheet->getCell("G{$row}")->getCalculatedValue()),
                'printed_qty' => $this->intelligenceService->toFloat($sheet->getCell("H{$row}")->getCalculatedValue()),
                'number_of_press' => $this->intelligenceService->toFloat($sheet->getCell("I{$row}")->getCalculatedValue()),
                'source_sheet' => $sheet->getTitle(),
                'source_batch' => $batch,
                'metadata' => ['row' => $row],
            ]);

            $created++;
        }

        return $created;
    }

    protected function upsertProfile(array $attributes): DiecutProfile
    {
        $profileCode = trim((string) Arr::get($attributes, 'profile_code', ''));
        $normalizedCode = $this->intelligenceService->normalizeCode($profileCode);

        $profile = DiecutProfile::query()->firstOrNew(['normalized_code' => $normalizedCode]);
        $updates = [
            'profile_code' => $profileCode,
            'base_normalized_code' => $this->intelligenceService->normalizeBaseCode($profileCode),
        ];

        foreach ([
            'diecut_type',
            'height_mm',
            'width_mm',
            'interval_ud_mm',
            'interval_lr_mm',
            'column_count',
            'no_of_ups',
            'default_tool_life_pcs',
            'default_tool_life_press',
            'rev',
            'status',
            'source_sheet',
            'source_batch',
            'metadata',
        ] as $key) {
            if (array_key_exists($key, $attributes) && $attributes[$key] !== null) {
                $updates[$key] = $attributes[$key];
            }
        }

        $profile->fill($updates);
        $profile->save();

        return $profile;
    }

    protected function upsertAlias(DiecutProfile $profile, string $aliasCode, string $aliasType): DiecutProfileAlias
    {
        return DiecutProfileAlias::query()->updateOrCreate(
            ['normalized_alias' => $this->intelligenceService->normalizeCode($aliasCode)],
            [
                'diecut_profile_id' => $profile->id,
                'alias_code' => $aliasCode,
                'base_normalized_alias' => $this->intelligenceService->normalizeBaseCode($aliasCode),
                'alias_type' => $aliasType,
                'confidence_score' => 1.0,
            ]
        );
    }

    protected function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text !== '' ? $text : null;
    }

    protected function toDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_numeric($value)) {
            return SpreadsheetDate::excelToDateTimeObject($value)->format('Y-m-d');
        }

        $timestamp = strtotime((string) $value);
        return $timestamp ? date('Y-m-d', $timestamp) : null;
    }
}
