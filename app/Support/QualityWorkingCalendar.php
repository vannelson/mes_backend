<?php

namespace App\Support;

use App\Models\HolidayCalendar;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class QualityWorkingCalendar
{
    protected static ?Collection $holidays = null;

    public static function addWorkingDays(Carbon $start, int $days): Carbon
    {
        $date = $start->copy();
        $remaining = max(0, $days);

        while ($remaining > 0) {
            $date->addDay();
            if (static::isWorkingDay($date)) {
                $remaining--;
            }
        }

        return $date;
    }

    public static function workingDaysBetween(?Carbon $from, ?Carbon $to): ?int
    {
        if (! $from || ! $to) {
            return null;
        }

        $start = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        $negative = false;
        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
            $negative = true;
        }

        $count = 0;
        while ($start->lt($end)) {
            $start->addDay();
            if (static::isWorkingDay($start)) {
                $count++;
            }
        }

        return $negative ? -$count : $count;
    }

    public static function isWorkingDay(Carbon $date): bool
    {
        if ($date->isWeekend()) {
            return false;
        }

        $holiday = static::holidayMap()->get($date->toDateString());
        if (! $holiday) {
            return true;
        }

        return (bool) $holiday['is_working_day'];
    }

    public static function buildEightDDeadlines(?Carbon $issueDate): array
    {
        if (! $issueDate) {
            return [];
        }

        $base = $issueDate->copy()->startOfDay();

        return [
            'ack_due_at' => $base->copy()->addHours(24),
            'd3_due_at' => $base->copy()->addHours(48),
            'd4_due_at' => static::addWorkingDays($base, 3)->endOfDay(),
            'd5_due_at' => $base->copy()->addDays(7)->endOfDay(),
            'd8_due_at' => $base->copy()->addDays(12)->endOfDay(),
            'closure_due_at' => static::addWorkingDays($base, 30)->endOfDay(),
        ];
    }

    protected static function holidayMap(): Collection
    {
        if (static::$holidays !== null) {
            return static::$holidays;
        }

        static::$holidays = HolidayCalendar::query()
            ->get(['holiday_date', 'is_working_day'])
            ->mapWithKeys(fn (HolidayCalendar $holiday) => [
                $holiday->holiday_date?->toDateString() => [
                    'is_working_day' => (bool) $holiday->is_working_day,
                ],
            ]);

        return static::$holidays;
    }

    public static function clearCache(): void
    {
        static::$holidays = null;
    }
}
