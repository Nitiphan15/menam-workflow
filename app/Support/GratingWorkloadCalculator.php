<?php

namespace App\Support;

use Carbon\Carbon;

class GratingWorkloadCalculator
{
    public static function uniqueMinutes(iterable $intervals, array $breaks = []): int
    {
        $ranges = collect($intervals)
            ->map(function ($interval) {
                $startedAt = Carbon::parse(data_get($interval, 'started_at'));
                $finishedAt = Carbon::parse(data_get($interval, 'finished_at'));

                if ($finishedAt->lte($startedAt)) {
                    return null;
                }

                return [$startedAt, $finishedAt];
            })
            ->filter()
            ->sortBy(fn ($range) => $range[0]->getTimestamp())
            ->values();

        if ($ranges->isEmpty()) {
            return 0;
        }

        $merged = [];
        foreach ($ranges as [$startedAt, $finishedAt]) {
            $lastIndex = count($merged) - 1;

            if ($lastIndex < 0 || $startedAt->gt($merged[$lastIndex][1])) {
                $merged[] = [$startedAt->copy(), $finishedAt->copy()];
                continue;
            }

            if ($finishedAt->gt($merged[$lastIndex][1])) {
                $merged[$lastIndex][1] = $finishedAt->copy();
            }
        }

        return (int) collect($merged)->sum(
            fn ($range) => self::netMinutes($range[0], $range[1], $breaks)
        );
    }

    public static function netMinutes(Carbon $startedAt, Carbon $finishedAt, array $breaks = []): int
    {
        $grossMinutes = max(0, $startedAt->diffInMinutes($finishedAt));
        if ($grossMinutes === 0) {
            return 0;
        }

        $breakMinutes = 0;
        $day = $startedAt->copy()->startOfDay();
        $lastDay = $finishedAt->copy()->startOfDay();

        while ($day->lte($lastDay)) {
            foreach ($breaks as [$breakStartTime, $breakEndTime]) {
                $breakStart = Carbon::parse($day->toDateString() . ' ' . $breakStartTime);
                $breakEnd = Carbon::parse($day->toDateString() . ' ' . $breakEndTime);
                $overlapStart = $startedAt->gt($breakStart) ? $startedAt->copy() : $breakStart;
                $overlapEnd = $finishedAt->lt($breakEnd) ? $finishedAt->copy() : $breakEnd;

                if ($overlapEnd->gt($overlapStart)) {
                    $breakMinutes += $overlapStart->diffInMinutes($overlapEnd);
                }
            }

            $day->addDay();
        }

        return max(0, $grossMinutes - $breakMinutes);
    }
}
