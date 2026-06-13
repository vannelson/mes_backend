<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;

class CalibrationSchedule
{
    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(str_replace("\xc2\xa0", ' ', str_replace(["\r\n", "\r"], "\n", $value)));
        $value = preg_replace("/[ \t]+/", ' ', $value);
        $value = preg_replace("/ *\n */", "\n", $value ?? '');
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public static function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }

        if (is_numeric($value) && (float) $value > 0) {
            try {
                return Carbon::instance(SpreadsheetDate::excelToDateTimeObject((float) $value))->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        $clean = self::clean(is_scalar($value) ? (string) $value : null);
        if (! $clean) {
            return null;
        }

        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'd.m.Y', 'j/n/Y', 'j-m-Y', 'd/m/y'];
        foreach ($formats as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $clean);
                if ($parsed !== false) {
                    return $parsed->startOfDay();
                }
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($clean)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function parseFrequencyIntervalMonths(?string $label): ?int
    {
        $clean = strtolower((string) self::clean($label));
        if ($clean === '') {
            return null;
        }

        $clean = str_replace(["\n", "\r"], ' ', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean);

        if (preg_match('/(\d+)\s*\/\s*(\d+)\s*year/', $clean, $matches)) {
            $numerator = (int) $matches[1];
            $denominator = max(1, (int) $matches[2]);
            return (int) round(($numerator / $denominator) * 12);
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*year/', $clean, $matches)) {
            return (int) round(((float) $matches[1]) * 12);
        }

        if (str_contains($clean, 'year')) {
            return 12;
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*month/', $clean, $matches)) {
            return (int) round((float) $matches[1]);
        }

        if (str_contains($clean, 'month')) {
            return 1;
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*week/', $clean, $matches)) {
            return max(1, (int) round(((float) $matches[1] * 7) / 30));
        }

        if (str_contains($clean, 'week')) {
            return 1;
        }

        return null;
    }

    public static function computeNextCalibrationDate(mixed $lastCalibrationDate, ?int $frequencyIntervalMonths): ?Carbon
    {
        $last = $lastCalibrationDate instanceof Carbon
            ? $lastCalibrationDate->copy()->startOfDay()
            : self::parseDate($lastCalibrationDate);

        if (! $last || ! $frequencyIntervalMonths || $frequencyIntervalMonths < 1) {
            return null;
        }

        return $last->copy()->addMonthsNoOverflow($frequencyIntervalMonths)->startOfDay();
    }

    public static function dueState(?Carbon $nextCalibrationDate, ?Carbon $today = null): string
    {
        if (! $nextCalibrationDate) {
            return 'unscheduled';
        }

        $today ??= Carbon::today();
        $days = $today->diffInDays($nextCalibrationDate, false);

        if ($days < 0) {
            return 'overdue';
        }

        if ($days === 0) {
            return 'due_today';
        }

        if ($days <= 30) {
            return 'due_soon';
        }

        return 'scheduled';
    }
}
