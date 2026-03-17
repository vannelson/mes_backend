<?php

namespace App\Http\Requests\WorkOrder\Concerns;

use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

trait NormalizesWorkOrderDates
{
    protected function normalizeWorkOrderDates(array $workOrders, array $dateFields): array
    {
        foreach ($workOrders as $index => $payload) {
            if (!is_array($payload)) {
                continue;
            }

            foreach ($dateFields as $field) {
                if (!array_key_exists($field, $payload)) {
                    continue;
                }

                $value = $payload[$field];
                $normalized = $this->normalizeDateValue($value);

                if ($normalized !== null) {
                    $payload[$field] = $normalized;
                    continue;
                }

                if ($value === null) {
                    $payload[$field] = null;
                    continue;
                }

                if (is_string($value) && trim($value) === '') {
                    $payload[$field] = null;
                }
            }

            $workOrders[$index] = $payload;
        }

        return $workOrders;
    }

    protected function normalizeDateValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }

            $lower = strtolower($value);
            if (in_array($lower, ['no', 'n/a', 'na', 'none', 'null', '-', '--', 'tbd', 'tba'], true)) {
                return null;
            }

            $parsed = $this->parseDateString($value);
            if ($parsed !== null) {
                return $parsed;
            }

            if (is_numeric($value)) {
                return $this->normalizeNumericDate((float) $value);
            }

            try {
                return Carbon::parse($value)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        if (is_numeric($value)) {
            $numeric = (float) $value;
            if ($numeric <= 0) {
                return null;
            }
            return $this->normalizeNumericDate($numeric);
        }

        return null;
    }

    private function normalizeNumericDate(float $numeric): ?string
    {
        if ($numeric >= 1000000000) {
            $timestamp = $numeric > 1000000000000
                ? (int) round($numeric / 1000)
                : (int) round($numeric);

            try {
                return Carbon::createFromTimestampUTC($timestamp)->format('Y-m-d');
            } catch (\Throwable) {
                // Fall through to Excel parser.
            }
        }

        try {
            return ExcelDate::excelToDateTimeObject($numeric)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseDateString(string $value): ?string
    {
        if (preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})(?:[T\\s].*)?$/', $value, $matches)) {
            return $this->formatDateParts((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        if (preg_match('/^(\\d{4})\\/(\\d{2})\\/(\\d{2})(?:\\s.*)?$/', $value, $matches)) {
            return $this->formatDateParts((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        if (preg_match('/^(\\d{4})\\.(\\d{2})\\.(\\d{2})(?:\\s.*)?$/', $value, $matches)) {
            return $this->formatDateParts((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        if (preg_match('/^(\\d{4})(\\d{2})(\\d{2})$/', $value, $matches)) {
            return $this->formatDateParts((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        if (preg_match('/^(\\d{1,2})[\\/\\-.](\\d{1,2})[\\/\\-.](\\d{2,4})(?:\\s.*)?$/', $value, $matches)) {
            $first = (int) $matches[1];
            $second = (int) $matches[2];
            $year = $this->normalizeYear((int) $matches[3]);

            if ($first > 12 && $second <= 12) {
                $day = $first;
                $month = $second;
            } elseif ($second > 12 && $first <= 12) {
                $day = $second;
                $month = $first;
            } else {
                $day = $first;
                $month = $second;
            }

            return $this->formatDateParts($year, $month, $day);
        }

        $parsed = $this->parseWithFormats($value, [
            'd M Y',
            'd-M-Y',
            'd M y',
            'd-M-y',
            'j M Y',
            'j M y',
            'd F Y',
            'd F y',
            'j F Y',
            'j F y',
            'M d Y',
            'M d, Y',
            'M d y',
            'M d, y',
            'M j Y',
            'M j, Y',
            'F d Y',
            'F d, Y',
            'F j Y',
            'F j, Y',
        ]);

        return $parsed;
    }

    private function parseWithFormats(string $value, array $formats): ?string
    {
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if (!$date) {
                continue;
            }
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }
            return $date->format('Y-m-d');
        }

        return null;
    }

    private function formatDateParts(int $year, int $month, int $day): ?string
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function normalizeYear(int $year): int
    {
        if ($year < 100) {
            return $year >= 70 ? 1900 + $year : 2000 + $year;
        }

        return $year;
    }
}
