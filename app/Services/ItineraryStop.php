<?php

namespace App\Services;

use App\Models\Destination;
use App\Models\ItineraryItem;
use App\Models\Place;
use Illuminate\Support\Collection;

/**
 * One stop, fully resolved, whatever kind of stop it is.
 *
 * `ItineraryEditor` used to read `Destination` directly in five places —
 * resolving, validating, timing, costing and totalling — so adding meal,
 * lodging and custom stops would have forked every one of those three ways.
 * This is the seam they all go through instead: resolve any stop reference
 * once, then read the value, never the tables.
 *
 * WHAT IT OWNS AND WHAT IT DOES NOT. It owns resolution (which catalogue row,
 * if any) and derivation (name, position, visit length, snapshot price). It
 * does NOT own validation — liked-only, active-only, no-repeat all stay in
 * `ItineraryEditor::resolveDestinations`, which is the ownership boundary the
 * request check enforces. And it does NOT own scheduling — durations flow into
 * `ItinerarySchedule`, which stays the single home for the day window.
 *
 * NAME AND POSITION DELEGATE, DELIBERATELY. `ItineraryItem::placeName()`,
 * `latitude()` and `longitude()` are the readers every view goes through, and
 * a second implementation here would drift from them. So this builds an
 * unsaved item with the resolved relations attached and asks it — the same
 * answers, one implementation. An unsaved item issues no queries: the
 * relations are set, never lazy-loaded.
 *
 * `snapshotCost()` returns NULL WHEN THE PRICE IS UNKNOWN, rather than zero.
 * A hotel with no verified rate contributes nothing to a trip total; summing
 * it as zero is the specific failure that makes a budget claim false. Callers
 * that must store a non-nullable figure today (the `estimated_cost` column)
 * decide the fallback themselves — see `ItineraryEditor::costFor()` — so the
 * unknown stays visible at this layer instead of being laundered here.
 */
final class ItineraryStop
{
    private function __construct(
        public readonly string $kind,
        public readonly ?Destination $destination,
        public readonly ?Place $place,
        /** @var array{name: string, address: string, latitude: ?float, longitude: ?float} */
        public readonly array $custom,
    ) {
    }

    public static function attraction(Destination $destination): self
    {
        return new self(ItineraryItem::TYPE_ATTRACTION, $destination, null, []);
    }

    public static function forPlace(Place $place, ?string $kind = null): self
    {
        if (! in_array($kind, [ItineraryItem::TYPE_MEAL, ItineraryItem::TYPE_LODGING], true)) {
            $kind = $place->isLodging()
                ? ItineraryItem::TYPE_LODGING
                : ItineraryItem::TYPE_MEAL;
        }

        return new self($kind, null, $place, []);
    }

    /**
     * @param  array{name?: mixed, address?: mixed, latitude?: mixed, longitude?: mixed}  $custom
     */
    public static function custom(array $custom): self
    {
        return new self(ItineraryItem::TYPE_CUSTOM, null, null, [
            'name' => trim((string) ($custom['name'] ?? '')),
            'address' => trim((string) ($custom['address'] ?? '')),
            'latitude' => is_numeric($custom['latitude'] ?? null)
                ? (float) $custom['latitude']
                : null,
            'longitude' => is_numeric($custom['longitude'] ?? null)
                ? (float) $custom['longitude']
                : null,
        ]);
    }

    /**
     * Every catalogue row a draft touches, keyed by id.
     *
     * One query per table, not one per stop. Called once per `apply()` and once
     * per reflow preview; everything downstream reads the collections.
     *
     * @param  array<int|string, array<string, mixed>>  $draft
     * @return array{0: Collection<int, Destination>, 1: Collection<int, Place>}
     */
    public static function preload(array $draft): array
    {
        $ids = static function (string $field) use ($draft): Collection {
            return collect($draft)
                ->map(fn ($fields) => (int) (is_array($fields) ? ($fields[$field] ?? 0) : 0))
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->values();
        };

        return [
            Destination::query()
                ->whereIn('id', $ids('destination_id'))
                ->get()
                ->keyBy('id'),
            Place::query()
                ->whereIn('id', $ids('place_id'))
                ->get()
                ->keyBy('id'),
        ];
    }

    /**
     * Resolve one draft row to a stop, or null when it names nothing resolvable.
     *
     * Null is a normal answer, not an error: a stored row whose destination row
     * is gone, or a row the traveller has not pointed at anything yet, resolves
     * to nothing, and the editor keeps the row's stored values rather than
     * inventing a stop. Validation of NEW rows (liked-only, active-only,
     * no-repeat) happens elsewhere — see the class docblock.
     *
     * @param  array<string, mixed>  $fields
     * @param  Collection<int, Destination>  $destinations
     * @param  Collection<int, Place>  $places
     */
    public static function fromFields(
        array $fields,
        Collection $destinations,
        Collection $places
    ): ?self {
        $destinationId = (int) ($fields['destination_id'] ?? 0);

        if ($destinationId > 0 && $destinations->get($destinationId)) {
            return self::attraction($destinations->get($destinationId));
        }

        $placeId = (int) ($fields['place_id'] ?? 0);

        if ($placeId > 0 && $places->get($placeId)) {
            $kind = $fields['item_type'] ?? null;

            return self::forPlace(
                $places->get($placeId),
                is_string($kind) ? $kind : null
            );
        }

        $custom = [
            'name' => $fields['custom_name'] ?? null,
            'address' => $fields['custom_address'] ?? null,
            'latitude' => $fields['custom_latitude'] ?? null,
            'longitude' => $fields['custom_longitude'] ?? null,
        ];

        $hasCustom = trim((string) ($custom['name'] ?? '')) !== ''
            || trim((string) ($custom['address'] ?? '')) !== ''
            || is_numeric($custom['latitude'] ?? null)
            || is_numeric($custom['longitude'] ?? null);

        return $hasCustom ? self::custom($custom) : null;
    }

    /**
     * Resolve a stored row to a stop, or null when its catalogue row is gone.
     *
     * A meal whose place row was deleted (place_id nulls on delete,
     * deliberately) resolves to null rather than to a stop with no name: the
     * row itself still renders through the item's own fallback, and resolving
     * it here to something nameless would invent a stop the catalogue no
     * longer has.
     */
    public static function forItem(ItineraryItem $item): ?self
    {
        if ($item->isCustom()) {
            return self::custom([
                'name' => $item->custom_name,
                'address' => $item->custom_address,
                'latitude' => $item->custom_latitude,
                'longitude' => $item->custom_longitude,
            ]);
        }

        if ($item->place) {
            return self::forPlace($item->place, $item->item_type);
        }

        if ($item->isAttraction() && $item->destination) {
            return self::attraction($item->destination);
        }

        return null;
    }

    public function isAttraction(): bool
    {
        return $this->kind === ItineraryItem::TYPE_ATTRACTION;
    }

    /**
     * The name to print. Delegates to the item — see the class docblock.
     */
    public function displayName(): string
    {
        return $this->asItem()->placeName();
    }

    /**
     * Delegates to the item. Null is a real answer: a custom entry with an
     * address and no pin has no position, and the honest outcome is a leg the
     * routing step cannot resolve.
     */
    public function latitude(): ?float
    {
        return $this->asItem()->latitude();
    }

    public function longitude(): ?float
    {
        return $this->asItem()->longitude();
    }

    public function hasKnownLocation(): bool
    {
        return $this->asItem()->hasKnownLocation();
    }

    /**
     * How long to hold this stop, in minutes.
     *
     * A destination brings its curated visit length, a place brings its own
     * (0 for lodging — a check-in is not a visit), and anything else falls
     * back to 60. Callers clamp to at least 1 themselves, the way they always
     * have: a 0 here means "no visit", not "a one-minute visit".
     */
    public function durationMinutes(): int
    {
        if ($this->destination) {
            return (int) ceil((float) $this->destination->recommended_minutes);
        }

        if ($this->place) {
            return (int) $this->place->recommended_minutes;
        }

        return 60;
    }

    /**
     * What this stop costs to place, or null when nobody has verified a price.
     *
     * A destination brings its curated estimate. A place brings the middle of
     * its band — display-grade, never a budget total: summing a midpoint states
     * something the source never said, and the calculator in Days 4-5 works
     * from the band and the basis instead.
     */
    public function snapshotCost(): ?float
    {
        if ($this->destination) {
            return (float) $this->destination->estimated_cost;
        }

        if ($this->place) {
            return $this->place->priceMidpoint();
        }

        return null;
    }

    /**
     * The item this stop would be, without saving it.
     *
     * Relations are SET, so `placeName()`, `latitude()` and friends never hit
     * the database. Anything that must not issue a query can call these
     * freely — including the reflow preview, which runs on an unvalidated
     * draft.
     */
    private function asItem(): ItineraryItem
    {
        $item = new ItineraryItem([
            'item_type' => $this->kind,
            'destination_id' => $this->destination?->id,
            'place_id' => $this->place?->id,
            'custom_name' => $this->custom['name'] ?? null,
            'custom_address' => $this->custom['address'] ?? null,
            'custom_latitude' => $this->custom['latitude'] ?? null,
            'custom_longitude' => $this->custom['longitude'] ?? null,
        ]);

        if ($this->destination) {
            $item->setRelation('destination', $this->destination);
        }

        if ($this->place) {
            $item->setRelation('place', $this->place);
        }

        return $item;
    }
}
