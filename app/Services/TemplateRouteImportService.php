<?php

namespace App\Services;

use App\Models\TemplateRoute;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

class TemplateRouteImportService
{
    private const HEADER_LOOKAHEAD = 120;

    private array $headerKeys = [
        'customer_part_number' => [
            'customer part number',
            'customer part no.',
            'customer part no',
            'cust part number',
            'cust part no.',
            'cust part no',
            'part number',
            'part no.',
            'part no',
        ],
        'work_order_line_no' => [
            'work order line no.',
            'work order line number',
            'work order line no',
            'wo line no.',
            'wo line no',
        ],
        'wo_journal_line_no' => [
            'wo journal line no.',
            'wo journal line number',
            'wo journal line no',
            'wo journal line',
        ],
        'machine_type' => [
            'machine type',
            'process type',
        ],
        'machine_code' => [
            'machine code',
            'machine no',
            'machine no.',
            'machine number',
        ],
        'machine_name' => [
            'machine name',
        ],
        'process_no' => [
            'process no.',
            'process no',
            'process number',
        ],
        'posted' => [
            'posted',
        ],
        'no_of_press' => [
            'no. of press',
            'no of press',
            'number of press',
        ],
        'no_of_ups' => [
            'no. of ups',
            'no of ups',
            'number of ups',
        ],
        'printed_quantity' => [
            'printed quantity',
            'printed qty',
        ],
        'qc_approved_quantity' => [
            'qc approved quantity',
            'qc approved qty',
        ],
        'date_completed' => [
            'date completed',
        ],
        'remarks' => [
            'label remark',
            'remarks',
            'remark',
        ],
    ];

    private array $requiredColumns = [
        'customer_part_number',
        'machine_type',
        'wo_journal_line_no',
    ];

    private array $fallbackHeaderKeys = [
        'customer_part_number' => [
            'item code',
            'item no.',
            'item no',
            'item',
        ],
    ];

    public function import(UploadedFile $file, ?string $sheetIdentifier, int $userId, bool $dryRun, ?string $batchNumber = null): array
    {
        $this->extendExecutionLimits();
        $spreadsheet = $this->loadSpreadsheet($file);
        $worksheet = $this->resolveWorksheet($spreadsheet, $sheetIdentifier);
        $header = $this->detectHeader($worksheet);
        if (empty($header['map'])) {
            throw new RuntimeException('Unable to locate the header row.');
        }

        $missing = array_values(array_filter(
            $this->requiredColumns,
            fn ($required) => !array_key_exists($required, $header['map'])
        ));
        if (!empty($missing)) {
            throw ValidationException::withMessages([
                'columns' => ['Missing required columns: ' . implode(', ', $missing)],
            ]);
        }

        $rowsTotal = 0;
        $validRows = [];
        $errors = [];
        $headerRowNumber = $header['row_number'];
        $highestRow = $worksheet->getHighestRow();

        for ($rowNumber = $headerRowNumber + 1; $rowNumber <= $highestRow; $rowNumber++) {
            if ($this->isWorksheetRowEmpty($worksheet, $rowNumber, $header['map'])) {
                continue;
            }
            $rowsTotal++;

            $payload = $this->mapWorksheetRowToPayload($worksheet, $rowNumber, $header['map']);
            $partNumber = $this->normalizePartNumber($payload['customer_part_number'] ?? null);
            $machineTypeRaw = $this->sanitizeText($payload['machine_type'] ?? null);

            if ($partNumber === '' || $machineTypeRaw === '') {
                $errors[] = [
                    'row_number' => $rowNumber,
                    'message' => 'Missing customer part number or machine type.',
                    'columns' => ['customer_part_number', 'machine_type'],
                ];
                continue;
            }

            $machineType = $this->normalizeMachineType($machineTypeRaw);
            if ($machineType === null) {
                $errors[] = [
                    'row_number' => $rowNumber,
                    'message' => 'Unknown machine type.',
                    'columns' => ['machine_type'],
                ];
                continue;
            }

            $woJournalLineNo = $this->toNumber($payload['wo_journal_line_no'] ?? null);
            if ($woJournalLineNo === null) {
                $errors[] = [
                    'row_number' => $rowNumber,
                    'message' => 'Invalid WO Journal Line No.',
                    'columns' => ['wo_journal_line_no'],
                ];
                continue;
            }

            $workOrderLineNo = $this->toNumber($payload['work_order_line_no'] ?? null);
            $validRows[] = [
                'row_number' => $rowNumber,
                'customer_part_number' => $partNumber,
                'work_order_line_no' => $workOrderLineNo,
                'wo_journal_line_no' => $woJournalLineNo,
                'machine_type' => $machineType,
                'machine_code' => $this->sanitizeText($payload['machine_code'] ?? null),
                'machine_name' => $this->sanitizeText($payload['machine_name'] ?? null),
                'process_no' => $this->toNumber($payload['process_no'] ?? null),
                'posted' => $this->toBoolean($payload['posted'] ?? null),
                'no_of_press' => $this->sanitizeText($payload['no_of_press'] ?? null),
                'no_of_ups' => $this->sanitizeText($payload['no_of_ups'] ?? null),
                'printed_quantity' => $this->sanitizeText($payload['printed_quantity'] ?? null),
                'qc_approved_quantity' => $this->sanitizeText($payload['qc_approved_quantity'] ?? null),
                'date_completed' => $this->normalizeDate($payload['date_completed'] ?? null),
                'remarks' => $this->sanitizeText($payload['remarks'] ?? null),
            ];
        }

        $built = $this->buildTemplatesFromRows($validRows, !$dryRun);

        if (!$dryRun) {
            $sheetLabel = $worksheet->getTitle() ?: null;
            $this->replaceTemplates($built['records'], $userId, $batchNumber, $sheetLabel);
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return [
            'summary' => [
                'rows_total' => $rowsTotal,
                'rows_valid' => count($validRows),
                'unique_parts' => count($built['parts']),
                'templates_count' => count($built['templates']),
            ],
            'templates' => $built['templates'],
            'errors' => $errors,
        ];
    }

    public function buildTemplatesFromRows(array $rows, bool $buildRecords = true): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['customer_part_number']][] = $row;
        }

        $parts = [];
        foreach ($grouped as $partNumber => $partRows) {
            usort($partRows, static function ($a, $b) {
                $cmp = ($a['wo_journal_line_no'] ?? 0) <=> ($b['wo_journal_line_no'] ?? 0);
                if ($cmp !== 0) {
                    return $cmp;
                }
                $aLine = $a['work_order_line_no'] ?? PHP_INT_MAX;
                $bLine = $b['work_order_line_no'] ?? PHP_INT_MAX;
                return $aLine <=> $bLine;
            });

            $steps = [];
            $seenTypes = [];
            foreach ($partRows as $row) {
                $type = $row['machine_type'];
                if (isset($seenTypes[$type])) {
                    continue;
                }
                $seenTypes[$type] = true;
            $steps[] = [
                'index' => count($steps) + 1,
                'type' => $type,
                'machine_code' => $row['machine_code'],
                'machine_name' => $row['machine_name'],
                'woJournalLineNo' => $row['wo_journal_line_no'],
                'processNo' => $row['process_no'],
                'posted' => $row['posted'],
                'no_of_press' => $row['no_of_press'] ?? null,
                'no_of_ups' => $row['no_of_ups'] ?? null,
                'printed_quantity' => $row['printed_quantity'] ?? null,
                'qc_approved_quantity' => $row['qc_approved_quantity'] ?? null,
                'date_completed' => $row['date_completed'] ?? null,
                'remarks' => $row['remarks'] ?? null,
            ];
        }

            if (empty($steps)) {
                continue;
            }

            $workOrderLineNo = null;
            foreach ($partRows as $row) {
                if ($row['work_order_line_no'] !== null) {
                    $workOrderLineNo = $row['work_order_line_no'];
                    break;
                }
            }

            $routeTypes = $this->buildRouteTypes($steps);
            $routeWithMachines = $this->buildRouteWithMachines($steps);

            $parts[] = [
                'customer_part_number' => $partNumber,
                'workOrderLineNo' => $workOrderLineNo,
                'route_types' => $routeTypes,
                'route_with_machines' => $routeWithMachines,
                'steps' => $steps,
            ];
        }

        $templatesMap = [];
        foreach ($parts as $part) {
            $sequence = $part['route_types'];
            if ($sequence === '') {
                continue;
            }
            if (!isset($templatesMap[$sequence])) {
                $templatesMap[$sequence] = [
                    'sequence' => $sequence,
                    'parts' => [],
                    'lines' => [],
                ];
            }
            $templatesMap[$sequence]['parts'][] = $part['customer_part_number'];
            $templatesMap[$sequence]['lines'][] = $part;
        }

        $templates = [];
        $records = [];
        foreach ($templatesMap as $sequence => $data) {
            $customerParts = array_values(array_unique($data['parts']));
            sort($customerParts, SORT_STRING);
            $lines = $data['lines'];
            $canonicalSteps = $this->buildCanonicalSteps($lines);
            $stepCount = count($canonicalSteps);
            $routeSequenceWithMachines = $this->buildRouteWithMachines($canonicalSteps);

            $templates[] = [
                'template_name' => $sequence,
                'template' => $sequence,
                'sequence' => $sequence,
                'route_sequence_with_machines' => $routeSequenceWithMachines,
                'step_count' => $stepCount,
                'parts_count' => count($customerParts),
                'customer_part_numbers' => $customerParts,
                'lines' => $this->formatCanonicalLineForResponse($lines, $canonicalSteps),
            ];

            if ($buildRecords) {
                $records[] = [
                    'template' => $sequence,
                    'sequence' => $sequence,
                    'route_sequence_with_machines' => $routeSequenceWithMachines,
                    'customer_part_numbers' => $customerParts,
                    'metadata' => $this->formatLinesForMetadata($lines, $canonicalSteps),
                ];
            }
        }

        return [
            'templates' => $templates,
            'records' => $records,
            'parts' => array_keys($grouped),
        ];
    }

    private function buildCanonicalSteps(array $lines): array
    {
        if (empty($lines)) {
            return [];
        }

        $firstLine = $lines[0];
        $steps = $firstLine['steps'] ?? [];
        $canonical = [];

        foreach ($steps as $index => $baseStep) {
            $type = $baseStep['type'] ?? null;
            if ($type === null) {
                continue;
            }
            $counts = [];
            $stepByName = [];

            foreach ($lines as $line) {
                $step = $line['steps'][$index] ?? null;
                if (!is_array($step)) {
                    continue;
                }
                $name = $this->sanitizeText($step['machine_name']);
                if ($name === '') {
                    continue;
                }
                $counts[$name] = ($counts[$name] ?? 0) + 1;
                if (!isset($stepByName[$name])) {
                    $stepByName[$name] = $step;
                }
            }

            $canonicalName = $this->pickCanonicalMachine($counts);
            $selected = $canonicalName !== '' ? ($stepByName[$canonicalName] ?? null) : null;
            $chosen = $selected ?? $baseStep;
            if (!is_array($chosen)) {
                continue;
            }

            $canonical[] = [
                'index' => $index + 1,
                'type' => $type,
                'machine_code' => $chosen['machine_code'] ?? null,
                'machine_name' => $canonicalName !== '' ? $canonicalName : ($chosen['machine_name'] ?? null),
                'woJournalLineNo' => $chosen['woJournalLineNo'] ?? null,
                'processNo' => $chosen['processNo'] ?? null,
                'posted' => $chosen['posted'] ?? null,
                'no_of_press' => $chosen['no_of_press'] ?? null,
                'no_of_ups' => $chosen['no_of_ups'] ?? null,
                'printed_quantity' => $chosen['printed_quantity'] ?? null,
                'qc_approved_quantity' => $chosen['qc_approved_quantity'] ?? null,
                'date_completed' => $chosen['date_completed'] ?? null,
                'remarks' => $chosen['remarks'] ?? null,
            ];
        }

        return $canonical;
    }

    private function formatCanonicalLineForResponse(array $lines, array $canonicalSteps): array
    {
        if (empty($canonicalSteps)) {
            return [];
        }

        $workOrderLineNo = $lines[0]['workOrderLineNo'] ?? null;
        return [[
            'workOrderLineNo' => $workOrderLineNo,
            'route_types' => $this->buildRouteTypes($canonicalSteps),
            'route_with_machines' => $this->buildRouteWithMachines($canonicalSteps),
            'steps' => array_map(static function ($step) {
                return [
                    'index' => $step['index'],
                    'type' => $step['type'],
                    'machine_code' => $step['machine_code'],
                    'machine_name' => $step['machine_name'],
                    'woJournalLineNo' => $step['woJournalLineNo'],
                ];
            }, $canonicalSteps),
        ]];
    }

    private function formatLinesForMetadata(array $lines, array $canonicalSteps): array
    {
        if (empty($canonicalSteps)) {
            return [];
        }

        $routes = [];
        foreach ($canonicalSteps as $stepIndex => $step) {
            $routes[] = $this->buildRouteMetadata($step, $stepIndex);
        }

        return [[
            'workOrderLineNo' => $lines[0]['workOrderLineNo'] ?? null,
            'order_seq' => 1,
            'routes' => $routes,
        ]];
    }

    private function buildRouteMetadata(array $step, int $stepIndex): array
    {
        $machine = $this->buildMachinePayload($step);
        $paramIds = $this->buildParameterIds($step['type']);
        $layout = [
            'mode' => 'stack',
            'items' => array_map(static function ($paramId) {
                return [
                    'paramId' => $paramId,
                    'w' => 12,
                ];
            }, array_values($paramIds)),
        ];

        return [
            'order_seq' => $stepIndex + 1,
            'route' => sprintf('R%02d', $stepIndex + 1),
            'name' => $step['type'],
            'notes' => null,
            'layout' => $layout,
            'machine' => $machine,
            'metadata' => [
                'machine' => $machine,
                'processNo' => $step['processNo'],
                'woJournalLineNo' => $step['woJournalLineNo'],
                'posted' => $step['posted'],
            ],
            'parameters' => $this->buildParameters($paramIds, $step),
        ];
    }

    private function buildParameterIds(string $type): array
    {
        $slug = $this->slugifyType($type);
        return [
            'press' => $this->buildParameterId($slug, 'press'),
            'date' => $this->buildParameterId($slug, 'date'),
            'ups' => $this->buildParameterId($slug, 'ups'),
            'printed' => $this->buildParameterId($slug, 'printed'),
            'qc' => $this->buildParameterId($slug, 'qc'),
            'rem' => $this->buildParameterId($slug, 'rem'),
        ];
    }

    private function buildParameterId(string $slug, string $suffix): string
    {
        return sprintf('%s_%s_%s', $slug, $suffix, Str::lower(Str::random(8)));
    }

    private function buildParameters(array $paramIds, array $step): array
    {
        $noOfPress = $this->normalizeParameterValue($step['no_of_press'] ?? null);
        $noOfUps = $this->normalizeParameterValue($step['no_of_ups'] ?? null);
        $printedQty = $this->normalizeParameterValue($step['printed_quantity'] ?? null);
        $qcApproved = $this->normalizeParameterValue($step['qc_approved_quantity'] ?? null);
        $remarks = $this->normalizeParameterValue($step['remarks'] ?? null);

        return [
            [
                'label' => 'No. of Press',
                'name' => $paramIds['press'],
                'isRequired' => false,
                'unit' => null,
                'instructions' => null,
                'default_value' => $noOfPress,
                'current_value' => $noOfPress,
                'input' => [
                    'type' => 'text',
                    'length' => 255,
                ],
            ],
            [
                'label' => 'Date Completed',
                'name' => $paramIds['date'],
                'isRequired' => false,
                'unit' => null,
                'instructions' => null,
                'default_value' => $step['date_completed'] ?? null,
                'current_value' => $step['date_completed'] ?? null,
                'input' => [
                    'type' => 'date',
                ],
            ],
            [
                'label' => 'No. of Ups',
                'name' => $paramIds['ups'],
                'isRequired' => false,
                'unit' => null,
                'instructions' => null,
                'default_value' => $noOfUps,
                'current_value' => $noOfUps,
                'input' => [
                    'type' => 'text',
                    'length' => 255,
                ],
            ],
            [
                'label' => 'Printed Quantity',
                'name' => $paramIds['printed'],
                'isRequired' => false,
                'unit' => null,
                'instructions' => null,
                'default_value' => $printedQty,
                'current_value' => $printedQty,
                'input' => [
                    'type' => 'text',
                    'length' => 255,
                ],
            ],
            [
                'label' => 'QC Approved Quantity',
                'name' => $paramIds['qc'],
                'isRequired' => false,
                'unit' => null,
                'instructions' => null,
                'default_value' => $qcApproved,
                'current_value' => $qcApproved,
                'input' => [
                    'type' => 'text',
                    'length' => 255,
                ],
            ],
            [
                'label' => 'Remarks',
                'name' => $paramIds['rem'],
                'isRequired' => false,
                'unit' => null,
                'instructions' => null,
                'default_value' => $remarks,
                'current_value' => $remarks,
                'input' => [
                    'type' => 'text',
                    'length' => 255,
                ],
            ],
        ];
    }

    private function slugifyType(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $value));
        $slug = trim(preg_replace('/_+/', '_', $slug), '_');
        return $slug !== '' ? $slug : 'step';
    }

    private function normalizeParameterValue(mixed $value): mixed
    {
        $text = $this->sanitizeText($value);
        return $text !== '' ? $text : null;
    }

    private function replaceTemplates(array $records, int $userId, ?string $batchNumber, ?string $sheetLabel): void
    {
        DB::transaction(function () use ($records, $userId, $batchNumber, $sheetLabel) {
            $batchNumber = $batchNumber !== '' ? $batchNumber : null;
            $sheetLabel = $sheetLabel !== '' ? $sheetLabel : null;

            if ($batchNumber && $sheetLabel) {
                $existingCount = TemplateRoute::query()
                    ->where('batch_number', $batchNumber)
                    ->where(function ($query) use ($sheetLabel) {
                        $query->where('sheet', $sheetLabel)
                            ->orWhereNull('sheet')
                            ->orWhere('sheet', '');
                    })
                    ->count();

                if ($existingCount > 0) {
                    TemplateRoute::query()
                        ->where('batch_number', $batchNumber)
                        ->where(function ($query) use ($sheetLabel) {
                            $query->where('sheet', $sheetLabel)
                                ->orWhereNull('sheet')
                                ->orWhere('sheet', '');
                        })
                        ->delete();
                } else {
                    TemplateRoute::query()
                        ->where(function ($query) use ($sheetLabel) {
                            $query->where('sheet', $sheetLabel)
                                ->orWhereNull('sheet')
                                ->orWhere('sheet', '');
                        })
                        ->delete();
                }
            } elseif ($batchNumber) {
                TemplateRoute::query()
                    ->where('batch_number', $batchNumber)
                    ->delete();
            } elseif ($sheetLabel) {
                TemplateRoute::query()
                    ->where(function ($query) use ($sheetLabel) {
                        $query->where('sheet', $sheetLabel)
                            ->orWhereNull('sheet')
                            ->orWhere('sheet', '');
                    })
                    ->delete();
            } else {
                TemplateRoute::query()->delete();
            }

            foreach ($records as $record) {
                $customerParts = $record['customer_part_numbers'] ?? [];
                $customerPartNumberRef = !empty($customerParts) ? implode(', ', $customerParts) : null;

                TemplateRoute::create([
                    'uuid' => (string) Str::uuid(),
                    'template' => $record['sequence'],
                    'wod_ref' => null,
                    'customer_part_number_ref' => $customerPartNumberRef,
                    'batch_number' => $batchNumber,
                    'sheet' => $sheetLabel,
                    'user_id' => $userId,
                    'metadata' => $record['metadata'],
                ]);
            }
        });
    }

    private function buildRouteTypes(array $steps): string
    {
        return implode('->', array_map(
            static fn ($step) => $step['type'],
            $steps
        ));
    }

    private function buildRouteWithMachines(array $steps): string
    {
        $parts = [];
        foreach ($steps as $step) {
            $name = $this->sanitizeText($step['machine_name']);
            $parts[] = $name !== '' ? "{$step['type']}[{$name}]" : $step['type'];
        }
        return implode('->', $parts);
    }


    private function buildMachinePayload(array $step): array
    {
        $code = $this->sanitizeText($step['machine_code']);
        $name = $this->sanitizeText($step['machine_name']);
        $type = $step['type'];
        $labelParts = array_values(array_filter([$code, $name], static fn ($value) => $value !== ''));
        $label = !empty($labelParts) ? implode(' - ', $labelParts) : $type;

        return [
            'code' => $code !== '' ? $code : null,
            'name' => $name !== '' ? $name : null,
            'type' => $type,
            'label' => $label,
        ];
    }

    private function normalizeMachineType(string $value): ?string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9()]+/i', ' ', $value));
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized));

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/DIE\s*CUT\s*\(\s*D\s*\)/', $normalized)
            || preg_match('/DIE\s*CUT\s+\bD\b/', $normalized)) {
            return 'DIE-CUT (D)';
        }
        if (preg_match('/DIE\s*CUT\s*\(\s*L\s*\)/', $normalized)
            || preg_match('/DIE\s*CUT\s+\bL\b/', $normalized)) {
            return 'DIE-CUT (L)';
        }
        if (preg_match('/DIE\s*CUT/', $normalized)) {
            return 'DIE-CUT';
        }
        if (preg_match('/\bFLEXO\b/', $normalized)) {
            return 'FLEXO';
        }
        if (preg_match('/\bDIGITAL\b/', $normalized)) {
            return 'DIGITAL';
        }
        if (preg_match('/\bLP\b/', $normalized)) {
            return 'LP';
        }
        if (preg_match('/\bAOI\b/', $normalized)) {
            return 'AOI';
        }
        if (preg_match('/\bSLITTING\b/', $normalized)) {
            return 'SLITTING';
        }
        if (preg_match('/\bINSPECTION\b/', $normalized)) {
            return 'INSPECTION';
        }

        return $normalized;
    }

    private function pickCanonicalMachine(array $counts): string
    {
        if (empty($counts)) {
            return '';
        }
        $max = max($counts);
        $candidates = array_keys(array_filter(
            $counts,
            static fn ($count) => $count === $max
        ));
        if (empty($candidates)) {
            return '';
        }
        sort($candidates, SORT_STRING);
        return $candidates[0];
    }

    private function detectHeader(Worksheet $worksheet): array
    {
        $limit = min(self::HEADER_LOOKAHEAD, $worksheet->getHighestRow());
        for ($rowNumber = 1; $rowNumber <= $limit; $rowNumber++) {
            $map = $this->buildColumnMapFromWorksheetRow($worksheet, $rowNumber);
            if (!empty($map) && $this->hasRequiredColumns($map)) {
                return [
                    'row_number' => $rowNumber,
                    'map' => $map,
                ];
            }
        }

        return [
            'row_number' => null,
            'map' => [],
        ];
    }

    private function hasRequiredColumns(array $map): bool
    {
        foreach ($this->requiredColumns as $required) {
            if (!array_key_exists($required, $map)) {
                return false;
            }
        }
        return true;
    }

    private function buildColumnMapFromWorksheetRow(Worksheet $worksheet, int $rowNumber): array
    {
        $map = [];
        $mapPriority = [];
        $row = $worksheet->getRowIterator($rowNumber, $rowNumber)->current();
        if (!$row) {
            return $map;
        }
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(true);
        foreach ($cellIterator as $cell) {
            $column = $cell->getColumn();
            $value = $this->extractCellValue($cell);
            $normalized = $this->normalizeHeader($value);
            if ($normalized === '') {
                continue;
            }
            foreach ($this->headerKeys as $key => $options) {
                foreach ($options as $option) {
                    $normalizedOption = $this->normalizeHeader($option);
                    if ($normalizedOption === '') {
                        continue;
                    }
                    if ($normalized === $normalizedOption
                        || str_contains($normalized, $normalizedOption)
                        || str_contains($normalizedOption, $normalized)) {
                        $isFallback = $this->isFallbackHeaderOption($key, $normalizedOption);
                        $priority = $isFallback ? 0 : 1;
                        if (!isset($map[$key]) || ($mapPriority[$key] ?? -1) < $priority) {
                            $map[$key] = $column;
                            $mapPriority[$key] = $priority;
                        }
                        break;
                    }
                }
            }
        }
        return $map;
    }

    private function isFallbackHeaderOption(string $key, string $normalizedOption): bool
    {
        $fallbacks = $this->fallbackHeaderKeys[$key] ?? [];
        foreach ($fallbacks as $fallback) {
            if ($normalizedOption === $this->normalizeHeader($fallback)) {
                return true;
            }
        }
        return false;
    }

    private function extendExecutionLimits(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        @ini_set('max_execution_time', '300');
    }

    private function mapWorksheetRowToPayload(Worksheet $worksheet, int $rowNumber, array $columnMap): array
    {
        $payload = [];
        foreach ($columnMap as $key => $column) {
            $payload[$key] = $this->extractCellValue($worksheet->getCell($column . $rowNumber));
        }
        return $payload;
    }

    private function normalizeHeader(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        $string = is_string($value) ? $value : (string) $value;
        $string = strtolower(trim($string));
        $string = preg_replace('/[^a-z0-9]+/i', ' ', $string ?? '');
        $string = preg_replace('/\s+/', ' ', $string ?? '');
        return $string ?? '';
    }

    private function normalizePartNumber(mixed $value): string
    {
        $text = $this->sanitizeText($value);
        return $text !== '' ? strtoupper($text) : '';
    }

    private function sanitizeText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_numeric($value)) {
            return trim((string) $value);
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '-' ? '' : $trimmed;
        }
        return '';
    }

    private function toNumber(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) round((float) $value);
        }
        $clean = str_replace([',', ' '], '', (string) $value);
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }
        return (int) round((float) $clean);
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $text = strtolower(trim((string) $value));
        return in_array($text, ['yes', 'y', 'true', '1', 'posted'], true);
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_numeric($value)) {
            try {
                $date = Date::excelToDateTimeObject($value);
                return $date->format('Y-m-d');
            } catch (Throwable) {
                return null;
            }
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        $timestamp = strtotime($text);
        if ($timestamp === false) {
            return null;
        }
        return date('Y-m-d', $timestamp);
    }

    private function isWorksheetRowEmpty(Worksheet $worksheet, int $rowNumber, array $columnMap): bool
    {
        foreach ($columnMap as $column) {
            $value = $this->extractCellValue($worksheet->getCell($column . $rowNumber));
            if ($value !== null && trim((string) $value) !== '') {
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
                    $reader->setReadFilter(new TemplateRouteColumnReadFilter());
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

class TemplateRouteColumnReadFilter implements IReadFilter
{
    private ?int $endColumnIndex = null;

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        if ($this->endColumnIndex === null) {
            $this->endColumnIndex = Coordinate::columnIndexFromString('XFD');
        }
        $columnIndex = Coordinate::columnIndexFromString($columnAddress);
        return $columnIndex <= $this->endColumnIndex;
    }
}
