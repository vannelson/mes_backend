<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

class HistoricalWorkOrderImportService
{
    private const HEADER_LOOKAHEAD = 60;
    private const INSERT_CHUNK_SIZE = 500;

    private const RAW_HEADER_MAP = [
        'Work Order No.' => 'work_order_no',
        'Work Order Line No.' => 'work_order_line_no',
        'WO Journal Line No.' => 'wo_journal_line_no',
        'Add Date' => 'add_date',
        'Add User' => 'add_user',
        'Add Time' => 'add_time',
        'Material Batch No.' => 'material_batch_no',
        'Die Cut' => 'die_cut',
        'Process No.' => 'process_no',
        'Posted' => 'posted',
        'Machine Code' => 'machine_code',
        'Machine Name' => 'machine_name',
        'Machine Type' => 'machine_type',
        'Staff Code' => 'staff_code',
        'No. of Press' => 'no_of_press',
        'Date Started' => 'date_started',
        'Time Started' => 'time_started',
        'Date Completed' => 'date_completed',
        'Time Completed' => 'time_completed',
        'No. of Ups' => 'no_of_ups',
        'Printed Quantity' => 'printed_quantity',
        'Journal Type' => 'journal_type',
        'Item Code' => 'item_code',
        'Quantity' => 'quantity',
        'QC Approved Quantity' => 'qc_approved_quantity',
        'Rejected Quantity' => 'rejected_quantity',
        'Roll' => 'roll',
        'Length' => 'length',
        'Width' => 'width',
        'Length UOM' => 'length_uom',
        'Width UOM' => 'width_uom',
        'RM Quantity' => 'rm_quantity',
        'Material Code' => 'material_code',
        'Variant Code' => 'variant_code',
        'Request Delivery Date' => 'request_delivery_date',
        'Non QC Record' => 'non_qc_record',
        'Link to Line No.' => 'link_to_line_no',
        'Related' => 'related',
        'Expire Date' => 'expire_date',
        'Label Remark' => 'label_remark',
        'Customer Part Number' => 'customer_part_number',
        'Customer Code' => 'customer_code',
        'ddmm' => 'ddmm',
        'Label Packing Quantity 1' => 'label_packing_quantity_1',
        'Label Quantity 1' => 'label_quantity_1',
        'Label Packing Quantity 2' => 'label_packing_quantity_2',
        'Label Quantity 2' => 'label_quantity_2',
        'Label Packing Quantity 3' => 'label_packing_quantity_3',
        'Label Quantity 3' => 'label_quantity_3',
        'UOM for Label Printing' => 'uom_for_label_printing',
        'QC Inspector' => 'qc_inspector',
        'Summarised Period' => 'summarised_period',
        'Summarised' => 'summarised',
        'Posted Work Order No.' => 'posted_work_order_no',
        'Colour' => 'colour',
        'Currency' => 'currency',
        'Ref Customer' => 'ref_customer',
        'Group' => 'group',
        'PO' => 'po',
        'Source Doc. No.' => 'source_doc_no',
    ];

    private array $headerMap = [];

    private array $dateFields = [
        'add_date',
        'date_started',
        'date_completed',
        'request_delivery_date',
        'expire_date',
    ];

    private array $timeFields = [
        'add_time',
        'time_started',
        'time_completed',
    ];

    public function __construct()
    {
        $this->headerMap = $this->buildHeaderMap();
    }

    public function import(UploadedFile $file, ?string $sheetIdentifier = null): array
    {
        $this->extendExecutionLimits();

        $spreadsheet = $this->loadSpreadsheet($file);
        $worksheet = $this->resolveWorksheet($spreadsheet, $sheetIdentifier);

        $header = $this->detectHeaderRow($worksheet);
        if (empty($header['map'])) {
            throw new RuntimeException('Unable to locate the completed work order header row.');
        }

        $columnMap = $header['map'];
        $headerRowNumber = $header['row_number'];
        $highestRow = $worksheet->getHighestRow();

        $rowsTotal = 0;
        $rowsInserted = 0;
        $batch = [];
        $now = now();

        for ($rowNumber = $headerRowNumber + 1; $rowNumber <= $highestRow; $rowNumber++) {
            $payload = [];
            $hasValue = false;

            foreach ($columnMap as $column => $field) {
                $cell = $worksheet->getCell($column . $rowNumber);
                $value = $this->normalizeCellValue($field, $cell);
                if ($value !== null && $value !== '') {
                    $hasValue = true;
                }
                $payload[$field] = $value;
            }

            if (!$hasValue) {
                continue;
            }

            $payload['created_at'] = $now;
            $payload['updated_at'] = $now;
            $batch[] = $payload;
            $rowsTotal++;

            if (count($batch) >= self::INSERT_CHUNK_SIZE) {
                DB::table('historical_work_orders')->insert($batch);
                $rowsInserted += count($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            DB::table('historical_work_orders')->insert($batch);
            $rowsInserted += count($batch);
        }

        return [
            'summary' => [
                'rows_total' => $rowsTotal,
                'rows_inserted' => $rowsInserted,
                'sheet' => [
                    'name' => $worksheet->getTitle(),
                    'index' => $spreadsheet->getIndex($worksheet),
                    'total' => $spreadsheet->getSheetCount(),
                ],
            ],
            'columns' => array_values(array_unique($columnMap)),
        ];
    }

    private function extendExecutionLimits(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        @ini_set('max_execution_time', '300');
    }

    private function buildHeaderMap(): array
    {
        $map = [];
        foreach (self::RAW_HEADER_MAP as $label => $field) {
            $normalized = $this->normalizeHeader($label);
            if ($normalized !== '') {
                $map[$normalized] = $field;
            }
        }
        return $map;
    }

    private function detectHeaderRow(Worksheet $worksheet): array
    {
        $limit = min(self::HEADER_LOOKAHEAD, $worksheet->getHighestRow());
        $bestRow = null;
        $bestMap = [];
        $bestMatches = 0;

        for ($rowNumber = 1; $rowNumber <= $limit; $rowNumber++) {
            $map = $this->buildColumnMap($worksheet, $rowNumber);
            $matches = count($map);
            if ($matches > $bestMatches) {
                $bestMatches = $matches;
                $bestMap = $map;
                $bestRow = $rowNumber;
            }
        }

        if ($bestMatches === 0) {
            return [
                'row_number' => null,
                'map' => [],
            ];
        }

        return [
            'row_number' => $bestRow,
            'map' => $bestMap,
        ];
    }

    private function buildColumnMap(Worksheet $worksheet, int $rowNumber): array
    {
        $map = [];
        $row = $worksheet->getRowIterator($rowNumber, $rowNumber)->current();
        if (!$row) {
            return $map;
        }
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(true);

        foreach ($cellIterator as $cell) {
            $value = $this->extractCellValue($cell);
            $normalized = $this->normalizeHeader($value);
            if ($normalized === '') {
                continue;
            }
            if (array_key_exists($normalized, $this->headerMap)) {
                $map[$cell->getColumn()] = $this->headerMap[$normalized];
            }
        }

        return $map;
    }

    private function normalizeHeader(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        $text = strtolower(trim((string) $value));
        $text = preg_replace('/[^a-z0-9]+/i', '', $text ?? '');
        return $text ?? '';
    }

    private function normalizeCellValue(string $field, Cell $cell): ?string
    {
        $value = $this->extractCellValue($cell);
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $this->formatDateTimeValue($field, $value);
        }

        if (is_numeric($value)) {
            if (in_array($field, $this->dateFields, true)) {
                return $this->formatExcelDate($value);
            }
            if (in_array($field, $this->timeFields, true)) {
                return $this->formatExcelTime($value);
            }
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            if (in_array($field, $this->dateFields, true)) {
                try {
                    return Carbon::parse($trimmed)->format('Y-m-d H:i:s');
                } catch (Throwable) {
                    return $trimmed;
                }
            }
            if (in_array($field, $this->timeFields, true)) {
                try {
                    return Carbon::parse($trimmed)->format('H:i:s');
                } catch (Throwable) {
                    return $trimmed;
                }
            }
            return $trimmed;
        }

        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function formatDateTimeValue(string $field, \DateTimeInterface $value): string
    {
        if (in_array($field, $this->timeFields, true)) {
            return $value->format('H:i:s');
        }
        return $value->format('Y-m-d H:i:s');
    }

    private function formatExcelDate(mixed $value): ?string
    {
        try {
            return Date::excelToDateTimeObject((float) $value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function formatExcelTime(mixed $value): ?string
    {
        try {
            return Date::excelToDateTimeObject((float) $value)->format('H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function extractCellValue(Cell $cell): mixed
    {
        if ($cell->isFormula()) {
            try {
                $calculated = $cell->getCalculatedValue();
            } catch (Throwable) {
                $calculated = $cell->getOldCalculatedValue();
            }

            if ($calculated !== null && $calculated !== '') {
                return $calculated;
            }
        }

        return $cell->getValue();
    }

    private function loadSpreadsheet(UploadedFile $file): Spreadsheet
    {
        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            if ($reader instanceof IReader) {
                if (method_exists($reader, 'setReadDataOnly')) {
                    $reader->setReadDataOnly(true);
                }
                if (method_exists($reader, 'setReadEmptyCells')) {
                    $reader->setReadEmptyCells(false);
                }
            }
            return $reader->load($file->getRealPath());
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to read the uploaded spreadsheet.', 0, $e);
        }
    }

    private function resolveWorksheet(Spreadsheet $spreadsheet, ?string $sheetIdentifier): Worksheet
    {
        if ($sheetIdentifier === null || $sheetIdentifier === '') {
            return $spreadsheet->getSheet(0);
        }

        $worksheet = $spreadsheet->getSheetByName($sheetIdentifier);
        if (!$worksheet) {
            foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
                if (strcasecmp($sheet->getTitle(), $sheetIdentifier) === 0) {
                    $worksheet = $sheet;
                    break;
                }
            }
        }

        if (!$worksheet && is_numeric($sheetIdentifier)) {
            $index = max(0, (int) $sheetIdentifier);
            if ($index > 0) {
                $index--;
            }
            if ($index >= $spreadsheet->getSheetCount()) {
                throw new RuntimeException("Sheet index {$sheetIdentifier} is out of bounds.");
            }
            $worksheet = $spreadsheet->getSheet($index);
        }

        if (!$worksheet) {
            throw new RuntimeException("Sheet '{$sheetIdentifier}' was not found in the workbook.");
        }

        return $worksheet;
    }
}
