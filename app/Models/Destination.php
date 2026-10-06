<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
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

    /**
     * What each budget tier means in pesos, and the boundary that decides it.
     *
     * `budget_level` is not a ranking and not a filter on a score. It is an
     * **exact match** against the traveller's own profile:
     * `SwipeDeckService`, `RecommendationService`, `ItineraryGenerator` and
     * `SwipeDiscoveryController` all do `where('budget_level', $profile->
     * budget_level)`. So a destination filed under the wrong tier does not rank
     * lower, it becomes **invisible** to everyone in the other two tiers. That
     * is why the admin form shows these ranges instead of leaving the choice to
     * judgement.
     *
     * The boundaries are half-open and do not overlap. An earlier draft of this
     * guideline read "Mid 501-1000" alongside "Premium 1000 and up", which
     * double-counts exactly 1000; mid-range therefore stops at 999.
     *
     * `max` is null for the open-ended top tier. Order matters and is relied on
     * by budgetTierForCost().
     *
     * @var array<string, array{label: string, min: int, max: int|null}>
     */
    public const BUDGET_TIERS = [
        'economy' => ['label' => 'Economy', 'min' => 0, 'max' => 500],
        'mid-range' => ['label' => 'Mid', 'min' => 501, 'max' => 999],
        'premium' => ['label' => 'Premium', 'min' => 1000, 'max' => null],
    ];

    /**
     * The tier a peso amount falls into.
     *
     * Shared with the browser: the admin form auto-fills the select from this,
     * and the same boundaries are what the guideline on screen claims. Deriving
     * both from one constant is the point -- a JS copy of these numbers would be
     * free to drift from the labels above it, and nothing would notice.
     *
     * Negative and non-numeric input clamp to the bottom tier rather than
     * throwing, because it is fed straight from a number input an admin is
     * still typing into.
     */
    public static function budgetTierForCost(mixed $cost): string
    {
        $cost = is_numeric($cost) ? (float) $cost : 0.0;

        if ($cost < 0) {
            $cost = 0.0;
        }

        foreach (self::BUDGET_TIERS as $key => $tier) {
            if ($tier['max'] === null || $cost <= $tier['max']) {
                return $key;
            }
        }

        return array_key_last(self::BUDGET_TIERS);
    }

    /**
     * The guideline as readable text, for the admin form.
     *
     * @return array<string, string>
     */
    public static function budgetTierRanges(): array
    {
        $ranges = [];

        foreach (self::BUDGET_TIERS as $key => $tier) {
            $ranges[$key] = $tier['max'] === null
                ? '₱'.number_format($tier['min']).' and up'
                : ($tier['min'] === 0
                    ? 'Free – ₱'.number_format($tier['max'])
                    : '₱'.number_format($tier['min']).' – ₱'.number_format($tier['max']));
        }

        return $ranges;
    }

    /**
     * Where an admin-uploaded photo lands on the `public` disk.
     *
     * `public`, not `local`: the image has to be web-reachable, and `local`
     * roots at storage/app/private precisely so crawl snapshots are not.
     */
    public const IMAGE_DIRECTORY = 'destination-images';

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
        'sort_order',
        'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'entrance_fee' => 'decimal:2',
            'estimated_cost' => 'decimal:2',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
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

    /**
     * Where an admin-uploaded photo lives, relative to the `public` disk.
     *
     * Returns null when image_url is a third-party URL, which is the case for
     * every row the CSV seeder creates and for anything predating the upload
     * field. Callers use that to decide whether a file is ours to delete --
     * resolving the path component of an external URL would delete a path on
     * our own disk that has nothing to do with it.
     *
     * Lives here rather than in the controller because both the controller and
     * the tests need exactly this decision, and they must not disagree about
     * which URLs count as local.
     */
    public function imagePath(): ?string
    {
        if (! $this->image_url) {
            return null;
        }

        // rtrimmed, and built from a single url() call. Calling url('') and
        // url('destination-images') separately and concatenating gave
        // "/storage//destination-images/" whenever the disk URL carried a
        // trailing slash, so every local path resolved to null and no uploaded
        // photo was ever deleted.
        $base = rtrim(Storage::disk('public')->url(''), '/');
        $prefix = $base.'/'.self::IMAGE_DIRECTORY.'/';

        if (! str_starts_with($this->image_url, $prefix)) {
            return null;
        }

        return substr($this->image_url, strlen($base) + 1);
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

    /**
     * The carousel photos on this destination's page.
     *
     * Always ordered, because the order is the carousel's order and every read
     * of this relation is a render rather than a lookup.
     *
     * The thumbnail is not in here -- it stays on `image_url`, which is what the
     * index, the swipe deck and the map popup all render. See DestinationImage.
     */
    public function images(): HasMany
    {
        return $this->hasMany(DestinationImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Whether this destination has room for another carousel photo.
     */
    public function hasGalleryRoom(): bool
    {
        return $this->images()->count() < DestinationImage::MAX_PER_DESTINATION;
    }

    /**
     * The photographs the destinations stage can show for this destination.
     *
     * Hero photograph first, then the uploads in `sort_order`. The same order
     * `DestinationCarousel::photographsFor()` builds its slides in, and it exists
     * here too so the stage and any future single-destination stage cannot
     * disagree about what a destination's set of photographs is.
     *
     * @return array<int, string>
     */
    public function stagePhotographs(): array
    {
        $photographs = [];

        $hero = trim((string) ($this->image_url ?? ''));

        if ($hero !== '') {
            $photographs[] = $hero;
        }

        foreach ($this->images as $image) {
            $path = trim((string) $image->path);

            if ($path !== '') {
                $photographs[] = $path;
            }
        }

        return array_values(array_unique($photographs));
    }

    /**
     * The one-sentence editorial line the stage caption prints.
     *
     * THIS IS THE FIRST SENTENCE OF THE DESCRIPTION, AND `bodyDescription()` IS
     * WHAT IS LEFT AFTERWARDS. The two are a pair, and the reason they exist
     * together is that a standfirst and a body that both start with the same
     * sentence print that sentence twice within one screenful. Splitting them
     * means every word appears exactly once on the page: the opening sentence over
     * the photographs, the rest in the prose block.
     *
     * EMPTY WHEN THERE IS NO REST. A one-sentence description has nothing to
     * split, and returning it here would empty the body for the sake of a caption.
     * The caption's sentence element is conditional on this being non-empty, so
     * the stage simply has no standfirst for that destination rather than
     * duplicating the body.
     *
     * Not a `Str::limit()`. Truncating mid-word produces a line that reads as a
     * mistake on a 20px caption, where it is most noticeable.
     */
    public function standfirst(): string
    {
        $description = trim((string) $this->description);

        if ($description === '') {
            return '';
        }

        if (! preg_match('/^(?<lead>.+?[.!?])(?:\s+|\z)/su', $description, $matches)) {
            return '';
        }

        $lead = trim($matches['lead']);

        return trim(substr($description, strlen($lead))) === '' ? '' : $lead;
    }

    /**
     * The description with its standfirst removed -- i.e. the prose block.
     *
     * Falls back to the whole description when there is no standfirst to remove,
     * which is what keeps a one-sentence description on the page.
     */
    public function bodyDescription(): string
    {
        $description = trim((string) $this->description);
        $standfirst = $this->standfirst();

        return $standfirst === '' ? $description : trim(substr($description, strlen($standfirst)));
    }

    /**
     * The gold kicker on a stage panel and in the stage caption.
     *
     * The alphabetically first interest tag. "First" has to mean something
     * specific, because `destination_tag` is a bare composite primary key with no
     * ordering column, so the database has no opinion and would return tags in an
     * arbitrary order that could differ between two requests. Sorting by name
     * makes the kicker deterministic, which is what stops the caption flicking
     * between two labels for the same destination.
     *
     * Empty is a normal answer: a destination with no tags simply has no kicker,
     * and the caption omits the label rather than inventing one.
     */
    public function kicker(): string
    {
        return (string) ($this->tags->sortBy('name')->first()?->name ?? '');
    }

    /**
     * Forget the cached stage order whenever a destination changes.
     *
     * Without this, archiving a destination leaves it in both fans for up to ten
     * minutes -- and `show` 404s it, so a visitor could click straight from a
     * stage panel onto a 404. That is the whole reason this hook exists.
     *
     * `images` is loaded too, because a destination's photographs ARE its slides:
     * adding or deleting an upload changes the stage and has to invalidate it.
     *
     * Registered here rather than in an Observer because this project has none,
     * so an observer would mean a new directory plus a registration in
     * AppServiceProvider for one pair of listeners.
     */
    protected static function booted(): void
    {
        $forget = function (): void {
            app(\App\Domain\Destinations\DestinationCarousel::class)->forget();
        };

        static::saved($forget);
        static::deleted($forget);
    }
}
