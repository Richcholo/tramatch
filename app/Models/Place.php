<?php

namespace App\Models;

use App\Models\Concerns\HasOpeningHours;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somewhere to eat or somewhere to sleep.
 *
 * The other half of a complete trip. `Destination` answers "what is worth
 * seeing"; a `Place` answers "where do I eat and where do I sleep", which is what
 * the panel asked the itinerary to carry. See
 * `2026_10_14_090000_create_places_table` for why this is not more
 * `destinations` rows.
 *
 * READ THE PRICE, DON'T ASSUME IT. `price_min` and `price_max` are nullable and
 * null means UNKNOWN, never free. `Destination::estimated_cost` is
 * `decimal default 0`, so on that table "nobody checked" and "it costs nothing"
 * are the same stored value -- which is safe for an attraction whose fee is
 * published and is not safe for a hotel, because a trip total that silently
 * counted a room as free would make a budget claim false. Every costed figure
 * this model hands out therefore goes through `hasVerifiedPrice()`, and the
 * itinerary reports an incomplete budget rather than a total it cannot stand
 * behind.
 *
 * A PLACE WITH NO PRICE AND NO HOURS IS THE NORMAL STATE, not a broken one. The
 * catalogue this came from is curated by hand; an unverified field is blank and
 * the UI says "not listed". See `priceLabel()` and the inherited
 * `hasAnyHours()`.
 */
class Place extends Model
{
    use HasOpeningHours;

    /**
     * What kind of stop this is.
     *
     * Two, not a taxonomy. The panel asked for food and lodging, and a third
     * value ("shop", "activity") would be a category with no curated data behind
     * it. Adding one later is a migration and a constant; leaving the door open
     * is not a reason to ship an empty slot now.
     */
    public const TYPE_FOOD = 'food';

    public const TYPE_LODGING = 'lodging';

    public const TYPES = [
        self::TYPE_FOOD,
        self::TYPE_LODGING,
    ];

    /**
     * What a price band is a price OF.
     *
     * This is the panel's second question made concrete: "the budget" is
     * meaningless without it. A hotel's 4,500 is a room for one night for two
     * people; a restaurant's 450 is a head. Summing both into one "EST. TOTAL"
     * without a basis adds pesos to pesos that are not the same peso, so the
     * basis travels with the figure and the calculator (Day 4-5) does the
     * arithmetic per unit.
     *
     * `per_person` is an admission or an activity ticket that is per head but not
     * tied to one sitting -- a museum entry, a guided tour.
     * `per_person_meal` is one meal for one person, so a two-day trip for four
     * needs eight of them.
     * `per_room_night` is one room for one night, which does NOT scale with group
     * size. A four-person group booking two rooms is a traveller decision this
     * basis cannot make for them.
     */
    public const PRICE_PER_PERSON = 'per_person';

    public const PRICE_PER_PERSON_MEAL = 'per_person_meal';

    public const PRICE_PER_ROOM_NIGHT = 'per_room_night';

    public const PRICE_BASES = [
        self::PRICE_PER_PERSON,
        self::PRICE_PER_PERSON_MEAL,
        self::PRICE_PER_ROOM_NIGHT,
    ];

    /**
     * The meals a food place can be slotted into.
     *
     * Drives the generator's "put lunch here, not dinner" decision. `merienda` is
     * the Filipino afternoon snack, and its absence is what made an earlier
     * list of meal labels read as imported rather than local.
     */
    public const MEAL_SLOTS = [
        'breakfast',
        'merienda',
        'lunch',
        'dinner',
        'supper',
    ];

    /**
     * The three areas this build supports a COMPLETE trip in, and why.
     *
     * "The locations are too small" is a complaint about coverage, and coverage is
     * not a count. Adding sixty attractions scattered across Luzon raises the
     * number and leaves every single trip unable to answer "where do I eat?" --
     * which is the complaint. So the two-week revision picks areas that already
     * have a dense enough destination cluster to build on, and fills in food and
     * lodging around them.
     *
     * Chosen from the catalogue, not from taste. These are three of the four
     * largest provinces by destination count, and each has destinations sharing a
     * municipality rather than spread forty kilometres apart:
     *
     *   baguio   Benguet. FIVE of the eight destinations are inside Baguio City
     *            itself (Burnham Park, Wright Park, Camp John Hay, Mirador, the
     *            Botanical Garden), so a two-day trip is walkable-to-short-drive
     *            rather than four hours of road. BenCab and Mt. Pulag sit
     *            outside it and are a day trip of their own.
     *   antipolo Rizal. Three destinations inside Antipolo City (Cloud 9, the
     *            Pintô, Hinulugang Taktak) plus four in Tanay, which is the same
     *            ridge. Metro Manila's food and lodging coverage is the densest in
     *            the country, so the sourcing for this cluster is the easiest.
     *   paoay    Ilocos Norte. Paoay (the Sand Dunes, San Agustin Church) and
     *            Burgos (Cape Bojeador, Kapurpurawan) are one coastal run, and
     *            Bangui and Pagudpud extend it north. Six destinations is the
     *            smallest of the three and it is the best coastal test case.
     *   vigan    Ilocos Sur. Added 2026-10-25 as the fourth cluster: five
     *            destinations inside Vigan City itself (Calle Crisologo, the
     *            Heritage Site, Baluarte Watchtower, the Cathedral, the Jar
     *            Factory) plus Bantay Bell Tower at its edge, so the whole
     *            cluster walks. The heritage-core restaurants and inns face the
     *            same sourcing reality as everywhere else: most publish no rates,
     *            so the rows are honest blanks rather than invented figures.
     *
     * Deliberately NOT chosen: Aurora and Cagayan, which have five and four
     * destinations but no second municipality worth driving to, so a trip there
     * is one day out and one day back rather than a place to stay.
     *
     * This is a CURATION label, not a filter. Nothing refuses a place because its
     * cluster is not listed here; the constant exists so the coverage report can
     * say whether one of the three is ready, and so Day 2-3 knows where to spend
     * its sourcing effort.
     *
     * @var array<string, array{province: string, label: string}>
     */
    public const CLUSTERS = [
        'baguio' => [
            'province' => 'Benguet',
            'label' => 'Baguio',
        ],
        'antipolo' => [
            'province' => 'Rizal',
            'label' => 'Antipolo and the Tanay ridge',
        ],
        'paoay' => [
            'province' => 'Ilocos Norte',
            'label' => 'Paoay and Burgos',
        ],
        'vigan' => [
            'province' => 'Ilocos Sur',
            'label' => 'Vigan',
        ],
    ];

    /**
     * What a cluster needs before it can produce a complete trip.
     *
     * Below these numbers an itinerary built there is the thin thing the panel
     * complained about, however many destinations the province has: a traveller
     * gets an afternoon of sights and nowhere to eat or sleep. Checked per budget
     * tier by `clusterCoverage()`, because the exact-match filter makes a
     * province-wide total misleading.
     *
     * Lodging is the binding constraint. Food can be left unpriced and the trip
     * still works; a cluster with nowhere to sleep cannot be planned at all.
     */
    public const CLUSTER_MINIMUMS = [
        'food' => 6,
        'lodging' => 3,
    ];

    protected $fillable = [
        'name',
        'slug',
        'type',
        'cluster',
        'province',
        'municipality',
        'address',
        'latitude',
        'longitude',
        'budget_level',
        'cuisine',
        'meal_slots',
        'price_min',
        'price_max',
        'price_basis',
        'recommended_minutes',
        'opening_time',
        'closing_time',
        'closed_days',
        'daily_hours',
        'hours_kind',
        'operating_status',
        'hours_note',
        'source_url',
        'source_label',
        'last_verified_at',
        'price_verified_at',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
            'meal_slots' => 'array',
            'daily_hours' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'recommended_minutes' => 'integer',
            'last_verified_at' => 'datetime',
            'price_verified_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function items(): HasMany
    {
        return $this->hasMany(ItineraryItem::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * A price someone actually verified.
     *
     * The only way to cost this place. Every caller that adds a figure to a trip
     * total asks this first, because summing a NULL as zero is the specific
     * failure that makes a budget claim false.
     */
    public function hasVerifiedPrice(): bool
    {
        return $this->price_min !== null;
    }

    /**
     * The middle of the band, for a single headline figure.
     *
     * Only for display next to the full label. A budget calculation must use the
     * band and the basis, never a midpoint: "₱250–₱1,200 a head" summed at 725
     * states something the source never said.
     */
    public function priceMidpoint(): ?float
    {
        if (! $this->hasVerifiedPrice()) {
            return null;
        }

        $min = (float) $this->price_min;

        if ($this->price_max === null) {
            return $min;
        }

        return ($min + (float) $this->price_max) / 2;
    }

    /**
     * What a traveller should be told this costs, as words.
     *
     * "Price not listed" is a real answer and the one this returns whenever
     * nothing has been verified. It is not a placeholder for a number nobody has
     * looked up.
     */
    public function priceLabel(): string
    {
        if (! $this->hasVerifiedPrice()) {
            return 'Price not listed';
        }

        $min = (float) $this->price_min;
        $max = $this->price_max === null ? $min : (float) $this->price_max;

        $band = $min === $max
            ? '₱'.number_format($min)
            : '₱'.number_format($min).'–₱'.number_format($max);

        return match ($this->price_basis) {
            self::PRICE_PER_PERSON => $band.' per person',
            self::PRICE_PER_PERSON_MEAL => $band.' a head, per meal',
            self::PRICE_PER_ROOM_NIGHT => $band.' per room, per night',
            // A band with no basis is not a price anyone can act on, and saying
            // so is better than guessing which unit was meant.
            default => $band.' (unit not recorded)',
        };
    }

    /**
     * The meals this place is filed under, sanitised.
     *
     * Same contract as `Destination::normalisedDailyHours()`: a day that is not
     * a real meal, or appears twice, degrades to a shorter list instead of
     * rendering "Lunch · Lunch" or breaking a view. An unrecognised slug is
     * DROPPED rather than stored, because the seeder decides that and a bad cell
     * must not invent a meal.
     */
    public function normalisedMealSlots(): array
    {
        $stored = $this->meal_slots;

        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        if (! is_array($stored)) {
            return [];
        }

        $slots = [];

        foreach ($stored as $slot) {
            $slot = strtolower(trim((string) $slot));

            if (in_array($slot, self::MEAL_SLOTS, true)) {
                $slots[] = $slot;
            }
        }

        return array_values(array_unique($slots));
    }

    /**
     * Whether this place could take a meal at the given slot.
     *
     * A place with NO recorded meal slots answers true for every slot, because
     * that is a missing field and not a claim that it serves nothing. Reading it
     * the other way would leave a traveller with an unlisted restaurant unable to
     * plan lunch anywhere.
     */
    public function servesMeal(string $slot): bool
    {
        $slots = $this->normalisedMealSlots();

        if ($slots === []) {
            return true;
        }

        return in_array(strtolower($slot), $slots, true);
    }

    public function isFood(): bool
    {
        return $this->type === self::TYPE_FOOD;
    }

    public function isLodging(): bool
    {
        return $this->type === self::TYPE_LODGING;
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_LODGING => 'Where to stay',
            default => 'Where to eat',
        };
    }

    /**
     * The meal slots this place can take, as the words a picker shows.
     */
    public function mealSlotsLabel(): ?string
    {
        if (! $this->isFood()) {
            return null;
        }

        $slots = $this->normalisedMealSlots();

        if ($slots === []) {
            return null;
        }

        return implode(' · ', array_map('ucfirst', $slots));
    }

    /**
     * How much of a budget this place can speak to, per unit.
     *
     * Day 4-5's calculator is what actually multiplies these out. This is the
     * place that decides whether it CAN: a place with no verified price or no
     * recorded basis cannot contribute a figure to a total, and returning null
     * here is how the total is able to say it is incomplete instead of
     * under-reporting.
     *
     * @param  int  $people  group size
     * @param  int  $nights  nights, for lodging only
     * @param  int  $rooms   rooms needed, for lodging only
     * @return float|null null when the price or the basis is unknown
     */
    public function costFor(int $people, int $nights = 1, int $rooms = 1): ?float
    {
        $midpoint = $this->priceMidpoint();

        if ($midpoint === null) {
            return null;
        }

        $people = max(1, $people);
        $nights = max(1, $nights);
        $rooms = max(1, $rooms);

        return round(match ($this->price_basis) {
            self::PRICE_PER_PERSON => $midpoint * $people,
            self::PRICE_PER_PERSON_MEAL => $midpoint * $people,
            // Deliberately NOT scaled by group size. A room is a room; the
            // traveller decides how many they need, and multiplying a room rate
            // by six would be a figure nobody can act on.
            self::PRICE_PER_ROOM_NIGHT => $midpoint * $nights * $rooms,
            // No basis means no unit, so no arithmetic. See priceLabel().
            default => null,
        }, 2);
    }

    /**
     * Whether every field this place publishes has a source behind it.
     *
     * The gate a curated place has to pass, checked by a test rather than
     * trusted: `source_url` is NOT NULL at the schema level, so a row cannot be
     * created without one, but nothing forces `price_basis` or the hours to match
     * it, and this is what notices.
     */
    public function isFullySourced(): bool
    {
        return trim((string) $this->source_url) !== '';
    }

    /**
     * How many food places and how many hotels a cluster has, PER BUDGET TIER.
     *
     * Per tier and not in total, which is the whole point of returning it this
     * way. `RecommendationService`, `SwipeDeckService`, `ItineraryGenerator` and
     * `SwipeDiscoveryController` all filter `where('budget_level', $tier)` for
     * EQUALITY, so a province with nine places evenly spread across three tiers
     * has THREE usable places for a given traveller, not nine. A single summed
     * number would report Benguet as the strongest cluster while hiding the fact
     * that a mid-range traveller can only eat in three of them.
     *
     * `$province` rather than the cluster slug on purpose: the counts have to be
     * computable before any row exists, which is the state this report is read in
     * most often.
     *
     * @return array<string, array<string, array{food: int, lodging: int, priced: int}>>
     *         keyed by budget tier, then by type; `priced` counts food places with
     *         a verified price, because a cluster full of unpriced restaurants
     *         cannot answer a budget question either
     */
    public static function clusterCoverage(string $province): array
    {
        $rows = self::query()
            ->where('province', $province)
            ->where('is_active', true)
            ->get(['type', 'budget_level', 'price_min', 'price_basis']);

        $coverage = [];

        foreach (Destination::BUDGET_TIERS as $tier => $definition) {
            $coverage[$tier] = [
                'food' => 0,
                'lodging' => 0,
                'priced' => 0,
            ];
        }

        foreach ($rows as $row) {
            $tier = $row->budget_level;

            if (! isset($coverage[$tier])) {
                // A tier nobody declared. Counted under nothing rather than
                // invented into one of the three, so it cannot quietly inflate a
                // tier that is genuinely short.
                continue;
            }

            if ($row->type === self::TYPE_FOOD) {
                $coverage[$tier]['food']++;
            }

            if ($row->type === self::TYPE_LODGING) {
                $coverage[$tier]['lodging']++;
            }

            if (
                $row->type === self::TYPE_FOOD
                && $row->price_min !== null
                && $row->price_basis !== null
            ) {
                $coverage[$tier]['priced']++;
            }
        }

        return $coverage;
    }

    /**
     * Whether one budget tier in one province can carry a complete trip.
     *
     * Returns the gaps rather than a bare false, because the answer to "is this
     * cluster ready" is always "for which tiers, and what is missing" and a
     * boolean throws that away.
     *
     * @return array{ready: bool, gaps: array<string, string>}
     */
    public static function clusterReadiness(string $province, string $budgetLevel): array
    {
        $coverage = self::clusterCoverage($province)[$budgetLevel] ?? null;

        if ($coverage === null) {
            return [
                'ready' => false,
                'gaps' => ['tier' => 'No places filed under the "'.$budgetLevel.'" tier.'],
            ];
        }

        $gaps = [];

        foreach (self::CLUSTER_MINIMUMS as $type => $minimum) {
            if ($coverage[$type] < $minimum) {
                $gaps[$type] = $coverage[$type].' of '.$minimum.' needed';
            }
        }

        if ($coverage['priced'] === 0) {
            $gaps['priced'] = 'No food place has a verified price, so the trip '
                .'total would be incomplete.';
        }

        return [
            'ready' => $gaps === [],
            'gaps' => $gaps,
        ];
    }
}