<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stop on one day of a trip.
 *
 * A stop used to be an attraction and nothing else, because `destination_id` was
 * the only thing identifying it. The panel asked for where the traveller will eat
 * and where they will stay, so a stop now has four shapes, and `item_type` says
 * which:
 *
 *   attraction  a Destination from the curated catalogue. The original shape;
 *               `destination_id` is set.
 *   meal        a Place of type 'food'. `place_id` is set.
 *   lodging     a Place of type 'lodging'. `place_id` is set, and it is the
 *               overnight base: charged ONCE for the whole stay, not once per
 *               night it appears on the schedule.
 *   custom      something the traveller typed in -- the hotel they already
 *               booked, a restaurant a relative recommended. No foreign key at
 *               all, just the `custom_*` columns.
 *
 * `item_type` DEFAULTS TO 'attraction', so every row that existed before this
 * column is correct without a backfill. What decides which shape is legal is not
 * the type column alone, it is the reference: a `meal` with no `place_id` is
 * malformed, and `placeName()` and `latitude()` below are what every reader goes
 * through, so they degrade to something honest rather than null.
 *
 * THE COST ON THIS ROW IS A SNAPSHOT, taken when the stop was placed, and it is
 * not editable. `ItineraryEditor::costFor()` ignores a posted `estimated_cost`
 * outright and a stored stop keeps what it was generated with, because a price is
 * curated catalogue data and editing a time must not quietly restate what a place
 * costs. The route columns below are the opposite: they are DERIVED from two
 * coordinates and are rewritten every time the day's order changes.
 */
class ItineraryItem extends Model
{
    public const TYPE_ATTRACTION = 'attraction';

    public const TYPE_MEAL = 'meal';

    public const TYPE_LODGING = 'lodging';

    public const TYPE_CUSTOM = 'custom';

    public const TYPES = [
        self::TYPE_ATTRACTION,
        self::TYPE_MEAL,
        self::TYPE_LODGING,
        self::TYPE_CUSTOM,
    ];

    /**
     * A leg with no route behind it. The DEFAULT, and the point of having it: a
     * leg the routing provider could not resolve must say so on screen, and
     * `travel_minutes_from_previous` -- the flat 45-minute estimate every stop in
     * this app used to carry -- must not silently stand in as if it were a real
     * answer.
     */
    public const ROUTE_UNRESOLVED = 'unresolved';

    public const ROUTE_RESOLVED = 'resolved';

    public const ROUTE_STATUSES = [
        self::ROUTE_UNRESOLVED,
        self::ROUTE_RESOLVED,
    ];

    protected $fillable = [
        'itinerary_day_id',
        'item_type',
        'destination_id',
        'place_id',
        'sort_order',
        'start_time',
        'end_time',
        'travel_minutes_from_previous',
        'estimated_cost',
        'note',
        'custom_name',
        'custom_address',
        'custom_latitude',
        'custom_longitude',
        'route_distance_meters',
        'route_duration_minutes',
        'route_provider',
        'route_status',
    ];

    protected function casts(): array
    {
        // ONLY the columns this migration added.
        //
        // `start_time`, `end_time`, `travel_minutes_from_previous` and
        // `estimated_cost` are deliberately left uncast, as they were before. They
        // arrive from the driver as strings on MySQL and as whatever SQLite
        // stored on the test database, and 420 passing tests read them that way.
        // Adding a cast to a column that already works is a change with no
        // feature behind it, and `decimal:2` in particular would turn `375` into
        // `"375.00"` under the test suite.
        return [
            'custom_latitude' => 'float',
            'custom_longitude' => 'float',
            'route_distance_meters' => 'integer',
            'route_duration_minutes' => 'integer',
        ];
    }

    protected $attributes = [
        // A row inserted without an explicit item_type -- an older caller, or a
        // seeder -- still has to read as an attraction rather than as null, which
        // every reader below compares against TYPE_ATTRACTION.
        'item_type' => self::TYPE_ATTRACTION,
        'route_status' => self::ROUTE_UNRESOLVED,
    ];

    public function day(): BelongsTo
    {
        return $this->belongsTo(ItineraryDay::class, 'itinerary_day_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function isAttraction(): bool
    {
        return ($this->item_type ?: self::TYPE_ATTRACTION) === self::TYPE_ATTRACTION;
    }

    public function isMeal(): bool
    {
        return $this->item_type === self::TYPE_MEAL;
    }

    public function isLodging(): bool
    {
        return $this->item_type === self::TYPE_LODGING;
    }

    public function isCustom(): bool
    {
        return $this->item_type === self::TYPE_CUSTOM;
    }

    /**
     * The words a trip page puts above this stop's name.
     *
     * One method rather than a ternary in every view that renders a stop,
     * because the read view, the editor row and the add dialog all label one.
     */
    public function kindLabel(): string
    {
        if ($this->isMeal()) {
            return 'Where to eat';
        }

        if ($this->isLodging()) {
            return 'Where to stay';
        }

        if ($this->isCustom()) {
            return 'Your stop';
        }

        return 'Attraction';
    }

    /**
     * The name to print for this stop.
     *
     * Every reader goes through here rather than reaching for
     * `$item->destination->name` directly, because a meal and a custom entry have
     * no destination and a straight property chain would throw on a perfectly
     * valid row. Order matters: a stop keeps the name of what it is, not the
     * name of whatever happens to be attached.
     */
    public function placeName(): string
    {
        if ($this->isCustom()) {
            $name = trim((string) $this->custom_name);

            return $name !== '' ? $name : 'Your own stop';
        }

        if ($this->place) {
            return $this->place->name;
        }

        if ($this->destination) {
            return $this->destination->name;
        }

        // A `meal` or `lodging` whose place row was deleted (place_id nulls on
        // delete, deliberately, rather than taking the stop with it) keeps its
        // type but has lost its name. Say that instead of printing nothing.
        return 'Place no longer in the catalogue';
    }

    /**
     * The latitude to route and map from, or null.
     *
     * Null is a real answer here. A custom entry the traveller typed with an
     * address and no pin has no coordinates, and the honest response is a leg the
     * routing step cannot resolve -- not a coordinate guessed from the street
     * name, which would put a map pin and a route on a place we cannot locate.
     */
    public function latitude(): ?float
    {
        return $this->latitudeOrLongitude('latitude');
    }

    public function longitude(): ?float
    {
        return $this->latitudeOrLongitude('longitude');
    }

    private function latitudeOrLongitude(string $attribute): ?float
    {
        if ($this->isCustom()) {
            // `custom_latitude` / `custom_longitude`, not `latitude_custom`.
            $value = $this->{'custom_'.$attribute} ?? null;
        } elseif ($this->place) {
            $value = $this->place->{$attribute};
        } else {
            $value = $this->destination?->{$attribute};
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * DOES ANYONE VERIFY A PRICE FOR THIS STOP?
     *
     * A custom entry is always unpriced — it is the traveller's own, and the
     * catalogue has no figure for it. A meal or a hotel is unpriced when its
     * place row carries no verified band. Attractions always carry their
     * curated estimate.
     *
     * **This is the one answer, shared by the header count and the PDF.** The
     * page prints "3 stop(s) without a verified price — the total above is
     * incomplete" and the PDF has to print the same claim about the same rows;
     * two copies of this rule is how a document says a total is complete on
     * the page the traveller read it from.
     */
    public function hasVerifiedPrice(): bool
    {
        if ($this->isCustom()) {
            return false;
        }

        if ($this->place) {
            return $this->place->hasVerifiedPrice();
        }

        return true;
    }

    /**
     * Whether this stop can be routed from and mapped.
     *
     * Both coordinates or neither: half a position is not a position, and a
     * caller that only checked latitude would put a pin at 0 degrees longitude.
     */
    public function hasKnownLocation(): bool
    {
        return $this->latitude() !== null && $this->longitude() !== null;
    }

    /**
     * A driving-directions link for the drive INTO this stop, or null.
     *
     * **A DIRECTION IS A DIFFERENT KIND OF CLAIM FROM A MEASUREMENT.** The
     * driving lines on the map are drawn from OSRM; this hands the traveller
     * to a map app's own routing, which is a live estimate under live
     * traffic. So the label says "Directions" and the title says it is a
     * driving route from the previous stop — it is not a promise about how
     * long the drive takes, and it must never be printed beside a measured
     * leg as though it were the same number.
     *
     * Origin is the previous stop **when it has a pin**; the first stop of a
     * day omits the origin so the map app asks where the traveller actually
     * is, which is the question only they can answer. A stop without both
     * coordinates returns null and the view renders nothing: a link to a
     * search box is not directions, and the stop already says it cannot be
     * routed to.
     */
    public function directionsUrl(?self $previous = null): ?string
    {
        if (! $this->hasKnownLocation()) {
            return null;
        }

        $destination = $this->coordinate($this->latitude()).','.$this->coordinate($this->longitude());

        $query = [
            'api' => 1,
            'destination' => $destination,
            'travelmode' => 'driving',
        ];

        if ($previous && $previous->hasKnownLocation()) {
            $query = [
                'api' => 1,
                'origin' => $this->coordinate($previous->latitude()).','.$this->coordinate($previous->longitude()),
                'destination' => $destination,
                'travelmode' => 'driving',
            ];
        }

        return 'https://www.google.com/maps/dir/?'.http_build_query($query);
    }

    /**
     * Six decimals, always — the convention every map URL uses, and it stops
     * `http_build_query` quietly printing 120.5960 as `120.596`, which reads
     * as a precision problem in a link a traveller is about to trust.
     */
    private function coordinate(float $value): string
    {
        return number_format($value, 6, '.', '');
    }

    /**
     * The leg into this stop, in words.
     *
     * Null when the route is unresolved, which the UI must render as "not
     * resolved" rather than as zero minutes. `travel_minutes_from_previous` is
     * NOT the fallback: it is the flat schedule estimate, and substituting it
     * here is the exact lie the panel called out.
     */
    public function routeSummary(): ?string
    {
        if ($this->route_status !== self::ROUTE_RESOLVED) {
            return null;
        }

        if ($this->route_distance_meters === null || $this->route_duration_minutes === null) {
            return null;
        }

        $km = $this->route_distance_meters / 1000;

        return number_format($km, $km < 10 ? 1 : 0).' km · '
            .self::durationLabel($this->route_duration_minutes);
    }

    /**
     * Minutes as "45 min", "1 h" or "1 h 20 min".
     *
     * Shared with the PDF and the map tooltip so the three never round a journey
     * three different ways. The unit is kept on both parts -- "1 h 20" alone is
     * how a 1 hour 20 minute drive becomes 1 hour 2 minutes.
     */
    public static function durationLabel(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0
            ? $hours.' h'
            : $hours.' h '.$rest.' min';
    }
}