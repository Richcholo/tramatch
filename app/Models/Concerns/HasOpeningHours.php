<?php

namespace App\Models\Concerns;

use DateTimeInterface;

/**
 * The opening-hours rules, shared by everything a traveller can visit.
 *
 * Moved out of `Destination` verbatim so `Place` (a restaurant, a hotel) gets
 * the SAME behaviour rather than a second copy of it. `Destination::HOURS_KINDS`,
 * `Destination::daySlugs()` and `Destination::formatTime()` are read as static
 * calls from the seeder, the crawler, the admin form and the tests, and a trait
 * resolves all three exactly as a class constant or method does -- the class that
 * uses the trait serves its members by name.
 *
 * The method bodies are unchanged, deliberately. `Destination` had 420 tests
 * passing against them, and "extracting shared behaviour" that also edits the
 * behaviour would mean the extraction could not be verified by running the same
 * suite. If a rule here is wrong, change it here once and both models change.
 *
 * A place with no hours data is a NORMAL state and not a broken one. Every
 * method here answers about that honestly: `hoursForDay()` returns null, and
 * `openState()` on the caller decides between "closed" and "unknown" rather than
 * guessing. See `hasAnyHours()`.
 */
trait HasOpeningHours
{
    public const HOURS_OPEN = 'open_hours';

    public const HOURS_ALWAYS_OPEN = 'always_open';

    public const HOURS_REGISTRATION = 'registration_window';

    public const HOURS_RESERVATION = 'reservation_required';

    public const HOURS_ALERT_DEPENDENT = 'alert_dependent';

    public const HOURS_PER_DAY = 'per_day';

    public const HOURS_KINDS = [
        self::HOURS_OPEN,
        self::HOURS_ALWAYS_OPEN,
        self::HOURS_REGISTRATION,
        self::HOURS_RESERVATION,
        self::HOURS_ALERT_DEPENDENT,
        self::HOURS_PER_DAY,
    ];

    public static function daySlugs(): array
    {
        return [
            'monday',
            'tuesday',
            'wednesday',
            'thursday',
            'friday',
            'saturday',
            'sunday',
        ];
    }

    public function closedDayList(): array
    {
        $stored = array_filter(array_map(
            'trim',
            explode(',', (string) $this->closed_days)
        ));

        return array_values(array_intersect(self::daySlugs(), $stored));
    }

    public function hasPerDayHours(): bool
    {
        return $this->normalisedDailyHours() !== [];
    }

    public function normalisedDailyHours(): array
    {
        $stored = $this->daily_hours;

        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        if (!is_array($stored)) {
            return [];
        }

        $hours = [];

        foreach (self::daySlugs() as $day) {
            $window = $stored[$day] ?? null;

            if (is_string($window)) {
                $window = ['open' => $window];
            }

            if (!is_array($window)) {
                continue;
            }

            if (array_key_exists('closed', $window) && $window['closed']) {
                $hours[$day] = null;

                continue;
            }

            $open = $this->formatTime($window['open'] ?? null);
            $close = $this->formatTime($window['close'] ?? null);

            if ($open === null || $close === null || $open === $close) {
                continue;
            }

            $hours[$day] = ['open' => $open, 'close' => $close];
        }

        return $hours;
    }

    public function perDayHoursLabel(): ?string
    {
        $hours = $this->normalisedDailyHours();

        if ($hours === []) {
            return null;
        }

        $weekdays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
        $weekend = ['saturday', 'sunday'];

        $group = static function (array $days) use ($hours): ?string {
            $windows = [];

            foreach ($days as $day) {
                if (!array_key_exists($day, $hours)) {
                    return null;
                }

                $window = $hours[$day];

                $windows[] = $window === null
                    ? 'closed'
                    : $window['open'].'–'.$window['close'];
            }

            $unique = array_values(array_unique($windows));

            return count($unique) === 1 ? $unique[0] : null;
        };

        $parts = [];

        if (($weekdayWindow = $group($weekdays)) !== null) {
            $parts[] = 'Mon–Fri '.$weekdayWindow;
        }

        if (($weekendWindow = $group($weekend)) !== null) {
            $parts[] = 'Sat–Sun '.$weekendWindow;
        }

        if ($parts !== []) {
            return implode(' · ', $parts);
        }

        $labels = [];

        foreach ($hours as $day => $window) {
            $labels[] = ucfirst(substr($day, 0, 3)).' '
                .($window === null ? 'closed' : $window['open'].'–'.$window['close']);
        }

        return implode(' · ', $labels);
    }

    public function hoursForDay(DateTimeInterface $date): ?array
    {
        $day = strtolower($date->format('l'));
        $hours = $this->normalisedDailyHours();

        if (array_key_exists($day, $hours)) {
            return $hours[$day];
        }

        if (in_array($day, $this->closedDayList(), true)) {
            return null;
        }

        $open = $this->formatTime($this->opening_time);
        $close = $this->formatTime($this->closing_time);

        if ($open === null || $close === null) {
            return null;
        }

        return ['open' => $open, 'close' => $close];
    }

    /**
     * Is this destination closed on a given date?
     *
     * CAREFUL: this returns TRUE for a row that has never published hours at
     * all, because `hoursForDay()` answers null for two different things —
     * "closed that weekday" and "we have no hours for this row" — and null is
     * the only way it can say "nothing". A caller that treats it as "closed"
     * without first checking `hasAnyHours()` will exclude every destination
     * whose hours are blank.
     *
     * That is not hypothetical: the itinerary generator shipped exactly that
     * guard and silently generated nothing for every dated trip.
     */
    public function isClosedOn(DateTimeInterface $date): bool
    {
        return $this->hoursForDay($date) === null;
    }

    /**
     * Is this weekday one the place says it is CLOSED on? The precise question.
     *
     * `isClosedOn()` cannot answer it, because it really asks "is there no window
     * on this day" and that is null for a row which has never published hours at
     * all. Callers that guard it with `hasAnyHours()` are still wrong in one
     * shape: `hasAnyHours()` counts `closed_days` as hours data, so a place that
     * publishes **"closed Mondays" and nothing else** passes the guard and then
     * reads as closed on *every* day.
     *
     * That is not a theoretical row — it is what a catalogue entry looks like
     * when somebody knows the gate is shut on Mondays and has not published a
     * clock time. It shipped because the generator dropped a destination the
     * first time it was refused and never asked again, so "closed" and "closed
     * forever" were indistinguishable from the outside. Asking this question
     * directly cannot confuse them.
     */
    public function isClosedOnWeekday(DateTimeInterface $date): bool
    {
        return in_array(
            strtolower($date->format('l')),
            $this->closedDayList(),
            true
        );
    }

    public function closedDaysLabel(): ?string
    {
        $days = $this->closedDayList();

        if ($days !== []) {
            if (count($days) === 7) {
                return 'Closed every day of the week';
            }

            return 'Closed on '.ucfirst(implode(', ', $days));
        }

        $hours = $this->normalisedDailyHours();

        if ($hours === []) {
            return null;
        }

        $closed = array_keys(array_filter(
            $hours,
            fn ($window) => $window === null
        ));

        if ($closed === []) {
            return null;
        }

        if (count($closed) === 7) {
            return 'Closed every day of the week';
        }

        return 'Closed on '.ucfirst(implode(', ', $closed));
    }

    public function hasOpeningHours(): bool
    {
        return trim((string) $this->opening_time) !== ''
            && trim((string) $this->closing_time) !== '';
    }

    public function hasAnyHours(): bool
    {
        return $this->hasOpeningHours()
            || $this->hasPerDayHours()
            || $this->closedDayList() !== [];
    }

    /**
     * Reduce a clock time to `HH:MM`, or null if it is not one.
     *
     * Static because it is a pure function of its argument -- it reads nothing
     * from the model. That matters now that the admin form and the controller
     * both call it on a bare class name, where there is no instance to call it
     * on. Existing `$destination->formatTime(...)` callers keep working:
     * PHP permits calling a static method through an instance.
     *
     * Returns null rather than throwing for anything unparseable, including
     * out-of-range values like `99:99`. Callers that must not silently discard a
     * bad value are expected to check for null themselves -- see
     * DestinationController::normaliseTime.
     */
    public static function formatTime(mixed $time): ?string
    {
        $time = trim((string) $time);

        if (preg_match('/\A(\d{1,2}):(\d{2})(?::(\d{2}))?\z/', $time, $match) !== 1) {
            return null;
        }

        $hour = (int) $match[1];
        $minute = (int) $match[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    public function formatHours(): ?string
    {
        $opens = $this->formatTime($this->opening_time);
        $closes = $this->formatTime($this->closing_time);

        if ($opens === null || $closes === null) {
            return null;
        }

        return $opens.'–'.$closes;
    }

    public function hoursKindLabel(): ?string
    {
        if ($this->hoursKindIsAdvisory()) {
            return match ((string) $this->hours_kind) {
                self::HOURS_REGISTRATION => 'This is a registration window, not opening hours',
                self::HOURS_RESERVATION => 'Reservation required — walk-ins are refused',
                self::HOURS_ALERT_DEPENDENT => 'Access depends on the current hazard alert level',
                default => null,
            };
        }

        return match ((string) $this->hours_kind) {
            self::HOURS_ALWAYS_OPEN => 'Open 24 hours — there is no gate',
            self::HOURS_PER_DAY => 'Hours differ by day — see the week below',
            default => null,
        };
    }

    public function hoursKindIsAdvisory(): bool
    {
        return in_array((string) $this->hours_kind, [
            self::HOURS_REGISTRATION,
            self::HOURS_RESERVATION,
            self::HOURS_ALERT_DEPENDENT,
        ], true);
    }

    public function hoursKindIsInformational(): bool
    {
        return in_array((string) $this->hours_kind, [
            self::HOURS_ALWAYS_OPEN,
            self::HOURS_PER_DAY,
        ], true);
    }
}