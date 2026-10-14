<?php

namespace Database\Seeders\Concerns;

use App\Models\Destination;

/**
 * The mechanical half of reading a catalogue CSV: headers, cells, times, days,
 * statuses, budgets, hours.
 *
 * Shared by `LuzonLocationsCsvSeeder` and `PlacesCsvSeeder`, which grew the same
 * ten private helpers independently — verified token-identical modulo `trim`
 * versus `clean` (equivalent on the single-token values these take) and the
 * const source. A parsing fix here lands in both catalogues at once, which is
 * the failure this prevents: `normalizeDailyHours` once had to be written a
 * second time because the first copy lived behind the wrong seeder.
 *
 * DELIBERATELY NOT IN HERE, because the two catalogues honestly differ:
 *
 *   - number parsing. Luzon averages every number in a cell (`60–120` minutes
 *     becomes 90); Places needs a single figure or null. Sharing one would
 *     invent prices or corrupt durations.
 *   - `normalizeHoursKind`'s const source reads `Destination::HOURS_KINDS`.
 *     `Place::HOURS_KINDS` holds the same strings through `HasOpeningHours`,
 *     so this is one list, not two that must agree.
 *   - everything row-shaped: required columns, rejection rules, placeholder
 *     costs, verification stamps. That is each seeder's honesty policy, not
 *     mechanics.
 */
trait ReadsCatalogueRows
{
    private function normalizeHeader(?string $header): string
    {
        $header = trim((string) $header);
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);

        return \Illuminate\Support\Str::slug($header);
    }

    private function clean(mixed $value): string
    {
        return preg_replace('/\s+/', ' ', trim((string) $value));
    }

    private function cleanOrNull(mixed $value): ?string
    {
        $value = $this->clean($value);

        return $value === '' ? null : $value;
    }

    private function cleanTime(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return preg_match('/\A(\d{1,2}):(\d{2})\z/', $value, $match) === 1
            ? sprintf('%02d:%02d', (int) $match[1], (int) $match[2])
            : null;
    }

    private function normalizeClosedDays(mixed $value): array
    {
        $days = array_map(
            fn ($day) => strtolower($this->clean($day)),
            is_array($value) ? $value : explode(',', (string) $value)
        );

        return array_values(array_unique(array_intersect(
            Destination::daySlugs(),
            $days
        )));
    }

    private function normalizeBudget(mixed $value): string
    {
        $value = strtolower($this->clean($value));
        $value = str_replace(['–', '—', ' ', '-'], '-', $value);

        return in_array($value, ['economy', 'mid-range', 'premium'], true)
            ? $value
            : 'economy';
    }

    private function normalizeHoursKind(
        mixed $value,
        ?array $dailyHours,
        ?string $openingTime,
        ?string $closingTime
    ): ?string {
        $value = strtolower($this->clean($value));

        if (in_array($value, Destination::HOURS_KINDS, true)) {
            return $value;
        }

        if ($dailyHours !== null) {
            return Destination::HOURS_PER_DAY;
        }

        if ($openingTime === '00:00' && $closingTime === '23:59') {
            return Destination::HOURS_ALWAYS_OPEN;
        }

        return null;
    }

    private function normalizeOperatingStatus(?string $value): string
    {
        $value = strtolower($this->clean($value));
        $value = str_replace([' ', '-'], '_', $value);

        return in_array(
            $value,
            ['open', 'temporarily_closed', 'permanently_closed', 'unknown'],
            true
        )
            ? $value
            : 'unknown';
    }

    /**
     * A per-day window map, or null when there is nothing usable.
     *
     * A day whose window will not parse, or whose open and close are the same,
     * is DISCARDED rather than stored: one broken day must not take the other
     * six with it.
     */
    private function normalizeDailyHours(mixed $value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode(trim($value), true);

            if (! is_array($decoded)) {
                return null;
            }

            $value = $decoded;
        }

        if (! is_array($value)) {
            return null;
        }

        $hours = [];

        foreach (Destination::daySlugs() as $day) {
            $window = $value[$day] ?? null;

            // A curator writing "09:00-21:00" in one cell rather than JSON.
            if (is_string($window)) {
                $window = $this->parseTimeRange($window);
            }

            if (! is_array($window)) {
                continue;
            }

            if (($window['closed'] ?? false) === true) {
                $hours[$day] = ['closed' => true];

                continue;
            }

            $open = $this->cleanTime($window['open'] ?? null);
            $close = $this->cleanTime($window['close'] ?? null);

            if ($open === null || $close === null || $open === $close) {
                continue;
            }

            $hours[$day] = ['open' => $open, 'close' => $close];
        }

        return $hours === [] ? null : $hours;
    }

    /**
     * `09:00-21:00`, `09:00–21:00` and `09:00 to 21:00` are all a curator's way
     * of writing a window into one CSV cell.
     */
    private function parseTimeRange(string $value): ?array
    {
        if (preg_match(
            '/\A(\d{1,2}:\d{2})\s*(?:-|–|to)\s*(\d{1,2}:\d{2})\z/i',
            trim($value),
            $match
        ) !== 1) {
            return null;
        }

        return ['open' => $match[1], 'close' => $match[2]];
    }
}
