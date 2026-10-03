<?php

namespace App\Services;

/**
 * The daily scheduling rules: a fixed window, a lunch block nothing may
 * overlap, and a flat travel time between consecutive stops.
 *
 * ItineraryGenerator lays a whole trip out with these rules, and ItineraryEditor
 * uses the same ones to place a stop the traveller adds by hand and to
 * recompute a day when they ask for a reflow. Keeping a single implementation
 * is what stops a reflowed day from quietly disagreeing with a generated one.
 */
class ItinerarySchedule
{
    public const DAY_START_MINUTES = 8 * 60;

    public const DAY_END_MINUTES = 18 * 60;

    public const LUNCH_START_MINUTES = 12 * 60;

    public const LUNCH_END_MINUTES = 13 * 60;

    public const TRAVEL_MINUTES = 45;

    public const MAX_STOPS_PER_DAY = 3;

    /**
     * The longest single visit a day can hold outside the lunch block. A stop
     * needing more than this can never be scheduled, which is the ceiling the
     * generator checks before it drops a destination.
     */
    public static function longestVisitMinutes(): int
    {
        return max(
            self::LUNCH_START_MINUTES - self::DAY_START_MINUTES,
            self::DAY_END_MINUTES - self::LUNCH_END_MINUTES
        );
    }

    /**
     * Nudge a start time past the lunch block when a visit would otherwise run
     * into it.
     */
    public static function nextStart(int $earliestStart, int $duration): int
    {
        if (
            $earliestStart < self::LUNCH_END_MINUTES
            && $earliestStart + $duration > self::LUNCH_START_MINUTES
        ) {
            return self::LUNCH_END_MINUTES;
        }

        return $earliestStart;
    }

    /**
     * The travel gap the traveller will actually have: the space between the
     * previous stop ending and this one starting, never negative.
     */
    public static function travelBetween(?int $previousEnd, ?int $start): int
    {
        if ($previousEnd === null || $start === null) {
            return 0;
        }

        return max(0, $start - $previousEnd);
    }

    /**
     * Recompute start and end for an ordered list of stops, keeping the
     * duration each one was given.
     *
     * The day starts at DAY_START_MINUTES whatever times the traveller had
     * typed: reflowing means laying the day out again from the durations. Each
     * stop then begins TRAVEL_MINUTES after the one before it ends, exactly as
     * the generator spaces them, so a reflowed day and a generated day read the
     * same.
     *
     * A day can end up running past DAY_END_MINUTES when the traveller allots
     * more time than fits. That is reported rather than refused: the generator
     * refuses impossible destinations because the traveller never chose them,
     * whereas here they typed the durations themselves.
     *
     * @param  array<int, array{id: int, duration: int}>  $stops
     * @return array<int, array{start_time: string, end_time: string, travel_minutes_from_previous: int}>
     */
    public static function reflow(array $stops): array
    {
        $result = [];
        $earliest = self::DAY_START_MINUTES;
        $previousEnd = null;

        foreach ($stops as $stop) {
            $duration = max(1, (int) ($stop['duration'] ?? 0));

            $start = self::nextStart($earliest, $duration);
            $end = $start + $duration;

            $result[$stop['id']] = [
                'start_time' => self::format($start),
                'end_time' => self::format($end),
                'travel_minutes_from_previous' => self::travelBetween(
                    $previousEnd,
                    $start
                ),
            ];

            $previousEnd = $end;
            $earliest = $end + self::TRAVEL_MINUTES;
        }

        return $result;
    }

    /**
     * Find the next free slot on a day, given the times already placed on it.
     *
     * Always returns a slot. A day that is already full pushes the new stop
     * past the window rather than refusing it, for the reason given on
     * reflow().
     *
     * @param  array<int, array{start_time: ?string, end_time: ?string}>  $placed
     * @return array{start_time: string, end_time: string, travel_minutes_from_previous: int}
     */
    public static function nextSlot(array $placed, int $duration): array
    {
        $duration = max(1, $duration);
        $latestEnd = null;

        // The latest end on the day, not the end of the last stop. After a
        // reorder the two differ: a stop dragged to the front can finish well
        // before one above it, and following it would place the new stop inside
        // a stop that is already there.
        foreach ($placed as $slot) {
            $start = self::parse($slot['start_time'] ?? null) ?? 0;
            $end = self::parse($slot['end_time'] ?? null) ?? $start;

            $latestEnd = max($latestEnd ?? 0, $end);
        }

        $earliest = ($latestEnd ?? null) === null
            ? self::DAY_START_MINUTES
            : $latestEnd + self::TRAVEL_MINUTES;

        $start = self::nextStart($earliest, $duration);

        return [
            'start_time' => self::format($start),
            'end_time' => self::format($start + $duration),
            'travel_minutes_from_previous' => self::travelBetween(
                $latestEnd,
                $start
            ),
        ];
    }

    /**
     * How long a stop runs, taken from the window the traveller typed. Returns
     * null when the pair is unusable so the caller can fall back to the
     * destination's own recommended visit length.
     */
    public static function durationBetween(?string $start, ?string $end): ?int
    {
        $from = self::parse($start);
        $to = self::parse($end);

        if ($from === null || $to === null || $to <= $from) {
            return null;
        }

        return $to - $from;
    }

    /**
     * Minutes past midnight for a stored TIME value. Accepts the HH:MM the
     * browser sends and the HH:MM:SS MySQL hands back.
     */
    public static function parse(?string $time): ?int
    {
        if ($time === null) {
            return null;
        }

        $time = trim($time);

        if (
            $time === ''
            || ! preg_match('/\A(\d{1,2}):(\d{2})(?::\d{2})?\z/', $time, $parts)
        ) {
            return null;
        }

        $hours = (int) $parts[1];
        $minutes = (int) $parts[2];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return $hours * 60 + $minutes;
    }

    public static function format(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}