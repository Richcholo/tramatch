<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Destination extends Model
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

    protected $fillable = [
        'name',
        'slug',
        'description',
        'province',
        'municipality',
        'latitude',
        'longitude',
        'budget_level',
        'entrance_fee',
        'entrance_fee_display',
        'local_transportation',
        'typical_food_drinks',
        'activities',
        'estimated_cost',
        'estimated_cost_display',
        'pricing_type',
        'fee_operational_notes',
        'recommended_minutes',
        'recommended_minutes_display',
        'possible_expenses',
        'opening_time',
        'closing_time',
        'closed_days',
        'daily_hours',
        'hours_kind',
        'operating_status',
        'hours_source_url',
        'hours_source_label',
        'hours_note',
        'last_verified_at',
        'price_verified_at',
        'image_url',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'entrance_fee' => 'decimal:2',
            'estimated_cost' => 'decimal:2',
            'is_active' => 'boolean',
            'daily_hours' => 'array',
            'last_verified_at' => 'datetime',
            'price_verified_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

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

    public function isClosedOn(DateTimeInterface $date): bool
    {
        return $this->hoursForDay($date) === null;
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

    public function formatTime(mixed $time): ?string
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

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function swipes(): HasMany
    {
        return $this->hasMany(DestinationSwipe::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(DestinationSource::class);
    }
}
