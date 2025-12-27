<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

class WorkOrderImportService
{
    private array $headerMap = [];
    private string $activeSheetLabel = '';

    public function __construct()
    {
        $this->headerMap = Arr::get(config('work_order_import', []), 'header_map', []);
    }

    public function import(UploadedFile $file, string $sheetIdentifier): array
    {

        $spreadsheet = $this->loadSpreadsheet($file);
        $worksheet = $this->resolveWorksheet($spreadsheet, $sheetIdentifier);
        $this->activeSheetLabel = trim($worksheet->getTitle()) !== '' ? $worksheet->getTitle() : 'Sheet ' . ($spreadsheet->getIndex($worksheet) + 1);

        $rows = $this->readWorksheet($worksheet);
        if (count($rows) === 0) {
            throw new RuntimeException('The provided sheet is empty.');
        }

        $columnMap = [];
        $headerRowNumber = 0;
        while (count($rows) > 0 && empty($columnMap)) {
            $header = array_shift($rows);
            $headerRowNumber++;

            if ($this->isRowEmpty($header)) {
                continue;
            }

            $columnMap = $this->buildColumnMap($header);
        }

        if (empty($columnMap)) {
            throw new RuntimeException('Unable to detect the header row. Please ensure the sheet contains the expected column titles.');
        }

        $rowNumberBase = $headerRowNumber + 1;

        $currentRowNumber = $rowNumberBase;
        $rowsData = [];

        foreach ($rows as $row) {
            $absoluteRowNumber = $currentRowNumber;
            $currentRowNumber++;

            if ($this->isRowEmpty($row)) {
                continue;
            }

            $payload = $this->mapRowToPayload($row, $columnMap);

            if (empty($payload)) {
                continue;
            }

            $rowsData[] = [
                'row_number' => $absoluteRowNumber,
                'data' => $payload,
            ];
        }

        if (empty($rowsData)) {
            throw new RuntimeException('No rows were detected on the selected sheet.');
        }

        return [
            'sheet' => [
                'name' => $this->activeSheetLabel,
                'index' => $spreadsheet->getIndex($worksheet),
                'total' => $spreadsheet->getSheetCount(),
            ],
            'columns' => array_values(array_unique($columnMap)),
            'row_count' => count($rowsData),
            'rows' => $rowsData,
        ];
    }

    private function resolveWorksheet(Spreadsheet $spreadsheet, string $sheetIdentifier): Worksheet
    {
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

    private function buildColumnMap(array $headerRow): array
    {
        $map = [];

        foreach ($headerRow as $column => $label) {
            $key = trim((string) $label);
            if ($key === '') {
                continue;
            }

            if (isset($this->headerMap[$key])) {
                $map[$column] = $this->headerMap[$key];
            }
        }

        if (empty($map)) {
            throw new RuntimeException('Header row did not match the expected template.');
        }

        return $map;
    }

    private function mapRowToPayload(array $row, array $columnMap): array
    {
        $payload = [];

        foreach ($columnMap as $column => $field) {
            $payload[$field] = $this->transformValue($field, $row[$column] ?? null);
        }

        return $payload;
    }

    private function transformValue(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        return match ($field) {
            'selected' => $this->toBoolean($value),
            'production_due_date',
            'requested_delivery_date',
            'order_date',
            'production_date_completed' => $this->toDateString($value),
            'quantity_to_produce',
            'quantity_produced',
            'forecast_quantity',
            'no_of_colours',
            'production_qty_completed' => $this->toStringValue($value),
            default => $value === '' ? null : $value,
        };
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower((string) $value);

        return in_array($value, ['yes', 'y', 'true', '1'], true);
    }

    private function toDateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function toInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $clean = str_replace([',', ' '], '', (string) $value);

        return (int) round((float) $clean);
    }

    private function toStringValue(mixed $value, int $length = 100): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === '') {
            return null;
        }

        $stringValue = (string) $value;

        if (function_exists('mb_substr')) {
            return mb_substr($stringValue, 0, $length);
        }

        return substr($stringValue, 0, $length);
    }

    private function errorMessage(int $rowNumber, string $message): string
    {
        $label = $this->activeSheetLabel ?: 'Selected Sheet';

        return "[{$label}] Row {$rowNumber}: {$message}";
    }

    private function isRowEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (!is_null($value) && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
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

                if (method_exists($reader, 'setReadFilter')) {
                    $reader->setReadFilter(new WorkOrderColumnReadFilter('V'));
                }
            }

            return $reader->load($file->getRealPath());
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to read the uploaded spreadsheet. Please ensure it is a valid Excel file.', 0, $e);
        }
    }

    private function readWorksheet(Worksheet $worksheet): array
    {
        $rows = [];
        $highestColumn = $worksheet->getHighestColumn();

        foreach ($worksheet->getRowIterator() as $row) {
            $rowData = [];
            $cellIterator = $row->getCellIterator('A', $highestColumn);
            $cellIterator->setIterateOnlyExistingCells(false);

            foreach ($cellIterator as $cell) {
                $rowData[$cell->getColumn()] = $this->extractCellValue($cell);
            }

            $rows[] = $rowData;
        }

        return $rows;
    }

    private function extractCellValue(Cell $cell): mixed
    {
        if ($cell->isFormula()) {
            try {
                $calculated = $cell->getCalculatedValue();
            } catch (Throwable $e) {
                $calculated = $cell->getOldCalculatedValue();
            }

            if ($calculated !== null && $calculated !== '') {
                return $calculated;
            }
        }

        return $cell->getValue();
    }
}

class WorkOrderColumnReadFilter implements IReadFilter
{
    private int $endColumnIndex;

    public function __construct(string $endColumn = 'V')
    {
        $this->endColumnIndex = Coordinate::columnIndexFromString(strtoupper($endColumn));
    }

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        $columnIndex = Coordinate::columnIndexFromString($columnAddress);

        return $columnIndex <= $this->endColumnIndex;
    }
}
