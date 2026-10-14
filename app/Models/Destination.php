<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\HasOpeningHours;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Destination extends Model
{
    /**
     * The opening-hours rules live in HasOpeningHours, not here.
     *
     * They were moved out verbatim so `Place` (a restaurant, a hotel) shares one
     * implementation instead of carrying a second copy that would drift. Every
     * member the trait contributes is still reachable as `Destination::HOURS_*`,
     * `Destination::daySlugs()` and `Destination::formatTime()`, which the
     * seeder, the crawler, the admin form and the tests all call statically.
     *
     * `DateTimeInterface` is no longer imported here for `hoursForDay()`; the
     * trait declares it, and an unused import is a lie about what this file
     * needs.
     */
    use HasOpeningHours;

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
