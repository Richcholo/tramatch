<?php

namespace App\Services;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\User;
use App\Services\Routing\RouteLeg;
use App\Services\Routing\RouteService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rewrites a generated itinerary from what the traveller typed into the editor.
 *
 * The payload is keyed by item id rather than by array position, so reordering
 * a day never has to renumber form field names. A key that matches no stored
 * item is a stop the traveller just added in the browser: the editor gives
 * those negative keys, which no database id can collide with.
 *
 *   items[<item id or negative key>][day_id|sort_order|start_time|end_time
 *                                            |destination_id|note]
 *
 * There is deliberately no estimated_cost in that list. See costFor().
 *
 * Every catalogue read below goes through `ItineraryStop`: one preload per
 * apply, one resolution per row. The editor never queries Destination or Place
 * itself any more, so adding a stop kind touches the seam, not five methods.
 */
class ItineraryEditor
{
    public function __construct(
        private RecommendationService $recommendations,
        private RouteService $routes,
    ) {
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $draft
     */
    public function apply(Itinerary $itinerary, array $draft): Itinerary
    {
        $user = $itinerary->user;

        return DB::transaction(function () use ($itinerary, $user, $draft) {
            $days = $itinerary->days()->get()->keyBy('id');

            $stored = ItineraryItem::query()
                ->whereIn('itinerary_day_id', $days->keys())
                ->get()
                ->keyBy('id');

            $this->deleteRemovedStops($stored, $draft);

            [$destinations, $places] = ItineraryStop::preload($draft);

            $this->resolveNewStops(
                $itinerary,
                $user,
                $stored,
                $draft,
                $destinations,
                $places
            );

            // Overnight stays charge once per trip, not once per night they
            // appear: the first occurrence in draft order carries the price and
            // every repeat stores 0. Seeded before any day is written so a
            // repeat processed on day one still finds the original.
            $chargedLodging = $stored
                ->filter(fn (ItineraryItem $item) => $item->isLodging() && $item->place_id)
                ->map(fn (ItineraryItem $item) => (int) $item->place_id)
                ->all();

            foreach ($days as $day) {
                $this->writeDay($day, $stored, $draft, $destinations, $places, $chargedLodging);
            }

            $this->refreshTotals($itinerary, $user);

            return $itinerary->load('days.items.destination', 'days.items.place');
        });
    }

    /**
     * Recompute the times on every day of a draft without writing anything, so
     * the editor can show the traveller what a reflow would produce.
     *
     * Durations come from the window the traveller typed, falling back to the
     * stop's own visit length, so a stop whose times are still blank lands
     * somewhere sensible instead of at 00:00. Gaps come from the road: each
     * consecutive pair with two known pins is routed, and anything else keeps
     * the flat estimate. Routing here is what makes a reflowed day disagree
     * with a generated one on purpose — the generated day has never met the
     * road, and its legs stay unresolved until this runs.
     *
     * @param  array<int|string, array<string, mixed>>  $draft
     * @return array{days: array<string, array<string, array<string, mixed>>>, estimated_gaps: array<string, int>} keyed by day id
     */
    public function previewReflow(Itinerary $itinerary, array $draft): array
    {
        $days = $itinerary->days()->get()->keyBy('id');

        [$destinations, $places] = ItineraryStop::preload($draft);

        $result = [];
        $estimated = [];

        foreach ($days as $day) {
            $entries = $this->entriesForDay($day, $draft);

            $stops = [];
            $previous = null;

            foreach ($entries as $entry) {
                $stop = ItineraryStop::fromFields(
                    $entry['fields'],
                    $destinations,
                    $places
                );

                $stops[] = [
                    'id' => (int) $entry['key'],
                    'duration' => $this->durationFor($entry, $stop),
                    'travel' => $this->routedMinutes($previous, $stop),
                ];

                $previous = $stop;
            }

            $slots = ItinerarySchedule::reflow($stops);

            $result[(string) $day->id] = $slots;

            // Per day, not per itinerary: one unroutable leg in a week does
            // not make the other six days suspect, and the notice names the
            // day it belongs to.
            $estimated[(string) $day->id] = ItinerarySchedule::estimatedGapCount($slots);
        }

        return ['days' => $result, 'estimated_gaps' => $estimated];
    }

    /**
     * The road between two resolved stops, in minutes — or null when either
     * end has no pin, which keeps the flat estimate rather than inventing a
     * leg. A null previous (the day's first stop) also answers null: there is
     * no leg into the first stop of a day.
     */
    private function routedMinutes(?ItineraryStop $from, ?ItineraryStop $to): ?int
    {
        return $this->routedLeg($from, $to)?->durationMinutes;
    }

    /**
     * The road between two resolved stops, or null when there is no answer.
     *
     * Pins are checked before the client is touched, so unpinned stops never
     * issue a request: a custom entry with an address and no coordinates must
     * not cost an HTTP call to learn what the catalogue already knows.
     */
    private function routedLeg(?ItineraryStop $from, ?ItineraryStop $to): ?RouteLeg
    {
        if (! $from || ! $to) {
            return null;
        }

        $fromLat = $from->latitude();
        $fromLng = $from->longitude();
        $toLat = $to->latitude();
        $toLng = $to->longitude();

        if ($fromLat === null || $fromLng === null || $toLat === null || $toLng === null) {
            return null;
        }

        return $this->routes->leg($fromLat, $fromLng, $toLat, $toLng);
    }

    /**
     * Append one empty day to a trip.
     *
     * A day is a real row, not a client-side placeholder, because every stop on
     * it posts an itinerary_day_id that UpdateItineraryRequest checks against
     * this trip's own days. Inventing the row in the browser would mean posting
     * an id that does not exist yet, and the ownership check exists precisely
     * to reject those.
     *
     * The day starts empty on purpose. Deciding what goes on it is the
     * traveller's, and the picker already offers every place they liked that
     * this trip does not visit yet.
     */
    public function addDay(Itinerary $itinerary): ItineraryDay
    {
        return DB::transaction(function () use ($itinerary) {
            $lastNumber = (int) $itinerary->days()->max('day_number');

            if ($lastNumber >= Itinerary::MAX_DAYS) {
                throw new RuntimeException(
                    'A trip cannot run longer than '.Itinerary::MAX_DAYS.' days.'
                );
            }

            $dayNumber = $lastNumber + 1;

            $day = $itinerary->days()->create([
                'day_number' => $dayNumber,
                // Derived from the trip's own start date, the same way the
                // generator dates day N. An undated trip stays undated rather
                // than being given a date the traveller never chose.
                'date' => $itinerary->start_date
                    ? $itinerary->start_date->copy()->addDays($dayNumber - 1)->toDateString()
                    : null,
            ]);

            $itinerary->update([
                'trip_duration_days' => $dayNumber,
            ]);

            return $day;
        });
    }

    /**
     * Take a day off a trip.
     *
     * The mirror of addDay(), and its own endpoint for the same reason: the day
     * is a row, so it cannot be part of a draft that is written whole.
     *
     * A day that still holds stops is refused rather than deleted along with
     * them. Silently taking three stops off someone's trip because they asked
     * for one fewer day is the kind of loss nobody recovers from and everybody
     * blames on the app, and the traveller has the tools to move or delete them
     * first. The refusal names the day so they know which one to empty.
     *
     * The last day cannot go either: a trip with no days has nothing to show,
     * and the header would read "0 day(s)".
     */
    public function removeDay(Itinerary $itinerary, ItineraryDay $day): Itinerary
    {
        if ((int) $day->itinerary_id !== (int) $itinerary->id) {
            // The controller rejects this first, the same way it rejects a stop
            // belonging to another trip. This only fires if that check is ever
            // weakened.
            throw new RuntimeException('That day is not part of this trip.');
        }

        return DB::transaction(function () use ($itinerary, $day) {
            $days = $itinerary->days()->get();

            if ($days->count() <= 1) {
                throw new RuntimeException(
                    'One day is the least an itinerary can have.'
                );
            }

            if ($day->items()->exists()) {
                throw new RuntimeException(
                    'Move or remove the stops on day '.$day->day_number
                    .' before taking that day off.'
                );
            }

            $day->delete();

            $this->renumberDays(
                $itinerary,
                // Cast rather than ===: day_number has no id cast, so a driver
                // handing back a string would fail to match, leave the deleted
                // day in the collection, and quietly write a trip_duration_days
                // one too high.
                $days->reject(
                    fn (ItineraryDay $candidate) => (int) $candidate->id === (int) $day->id
                )->values()
            );

            return $itinerary->load('days.items.destination');
        });
    }

    /**
     * Re-number the surviving days from 1 and bring the stored trip length back
     * in step with them.
     *
     * Every card prints day_number and the header prints trip_duration_days, so
     * removing day 1 of 3 and leaving the survivors numbered 2 and 3 would show
     * a trip that appears to begin on its second day and claims to be longer
     * than it is.
     *
     * Ascending order is what keeps the unique (itinerary_id, day_number) index
     * satisfied: each target number has already been vacated by the row ahead of
     * it, so no update ever collides with a day that is still sitting there. A
     * day that keeps its number keeps its date too, because the date is derived
     * from the number and nothing about it changed.
     *
     * @param  Collection<int, ItineraryDay>  $days
     */
    private function renumberDays(Itinerary $itinerary, Collection $days): void
    {
        $days
            ->sortBy(fn (ItineraryDay $day) => (int) $day->day_number)
            ->values()
            ->each(function (ItineraryDay $day, int $index) use ($itinerary) {
                $dayNumber = $index + 1;

                if ((int) $day->day_number === $dayNumber) {
                    return;
                }

                $day->update([
                    'day_number' => $dayNumber,
                    // Same arithmetic as addDay() and the generator, so a day
                    // that slides up the trip keeps following the start date. An
                    // undated trip stays undated.
                    'date' => $itinerary->start_date
                        ? $itinerary->start_date
                            ->copy()
                            ->addDays($dayNumber - 1)
                            ->toDateString()
                        : null,
                ]);
            });

        $itinerary->update([
            'trip_duration_days' => $days->count(),
        ]);
    }

    /**
     * The stops on one day, in the order the draft asks for, with each one
     * resolved to its destination.
     *
     * @param  array<int|string, array<string, mixed>>  $draft
     * @return array<int, array<string, mixed>>
     */
    private function entriesForDay(ItineraryDay $day, array $draft): array
    {
        $entries = [];

        foreach ($draft as $key => $fields) {
            if ((int) ($fields['day_id'] ?? 0) !== (int) $day->id) {
                continue;
            }

            $entries[] = [
                'key' => $key,
                'fields' => is_array($fields) ? $fields : [],
                'sort_order' => (int) ($fields['sort_order'] ?? PHP_INT_MAX),
            ];
        }

        // A stable tiebreak on the key keeps two stops the traveller gave the
        // same position from swapping places between saves.
        usort(
            $entries,
            fn (array $a, array $b) => [$a['sort_order'], (int) $a['key']]
                <=> [$b['sort_order'], (int) $b['key']]
        );

        return $entries;
    }

    /**
     * Write one day back: renumber the stops, fill in any time the traveller
     * left blank, and derive each travel gap from the times actually stored.
     *
     * @param  Collection<int, ItineraryItem>  $stored
     * @param  array<int|string, array<string, mixed>>  $draft
     * @param  Collection<int, Destination>  $destinations
     * @param  Collection<int, \App\Models\Place>  $places
     * @param  array<int, int>  $chargedLodging  place ids already carrying a
     *     price on this trip, shared by reference across every day so the
     *     second night at the same hotel stores 0 rather than a second room
     *     charge. See apply().
     */
    private function writeDay(
        ItineraryDay $day,
        Collection $stored,
        array $draft,
        Collection $destinations,
        Collection $places,
        array &$chargedLodging
    ): void {
        $placed = [];
        $previousEnd = null;
        $ordered = [];

        foreach ($this->entriesForDay($day, $draft) as $position => $entry) {
            $key = (int) $entry['key'];
            $fields = $entry['fields'];
            $stop = ItineraryStop::fromFields($fields, $destinations, $places);
            $destination = $stop?->destination;

            $item = $key > 0 ? $stored->get($key) : null;

            if ($key > 0 && ! $item) {
                // The form request rejects foreign ids before we get here, so
                // this only fires if that check is ever weakened.
                throw new RuntimeException(
                    'One of the stops you edited does not belong to this trip.'
                );
            }

            $start = ItinerarySchedule::parse($fields['start_time'] ?? null);
            $end = ItinerarySchedule::parse($fields['end_time'] ?? null);

            // A stop with no usable window was added seconds ago and has not
            // been placed yet. Give it the next free slot so saving does not
            // dump it at 00:00.
            if (($start === null || $end === null) && $stop) {
                $slot = ItinerarySchedule::nextSlot(
                    $placed,
                    max(1, $stop->durationMinutes())
                );

                $start ??= ItinerarySchedule::parse($slot['start_time']);
                $end ??= ItinerarySchedule::parse($slot['end_time']);
            }

            $attributes = [
                'itinerary_day_id' => $day->id,
                'item_type' => $item?->item_type
                    ?? $stop?->kind
                    ?? ItineraryItem::TYPE_ATTRACTION,
                'destination_id' => $destination?->id,
                'place_id' => $stop?->place?->id,
                'custom_name' => $this->customText($fields['custom_name'] ?? null, 150),
                'custom_address' => $this->customText($fields['custom_address'] ?? null, 255),
                'custom_latitude' => $this->customCoordinate($fields['custom_latitude'] ?? null),
                'custom_longitude' => $this->customCoordinate($fields['custom_longitude'] ?? null),
                'sort_order' => $position + 1,
                'start_time' => $start === null ? null : ItinerarySchedule::format($start),
                'end_time' => $end === null ? null : ItinerarySchedule::format($end),
                'travel_minutes_from_previous' => ItinerarySchedule::travelBetween(
                    $previousEnd,
                    $start
                ),
                'estimated_cost' => $this->costFor($item, $stop, $chargedLodging),
                'note' => $this->noteFor($fields),
            ];

            if ($item) {
                $item->update($attributes);
            } elseif ($stop) {
                $item = $day->items()->create($attributes);
            }

            if ($item && $item->start_time && $item->end_time) {
                $placed[] = [
                    'start_time' => $item->start_time,
                    'end_time' => $item->end_time,
                ];

                $previousEnd = ItinerarySchedule::parse($item->end_time);
            }

            if ($item) {
                $ordered[] = ['item' => $item, 'stop' => $stop];
            }
        }

        $this->writeLegs($ordered);
    }

    /**
     * Resolve every leg of a written day against the road.
     *
     * Runs after the stops because it needs stored rows to write to, and
     * inside the same transaction as the day so a failed leg lookup cannot
     * leave times saved with legs missing — a leg lookup never fails loudly
     * (see `OsrmClient`), but the ordering guarantee is what makes the two
     * columns describe the same day. Consecutive pairs only: there is no leg
     * into the first stop of a day, because the day starts wherever the
     * traveller woke up, not at the previous day's last pin.
     *
     * @param  array<int, array{item: ItineraryItem, stop: ?ItineraryStop}>  $ordered
     */
    private function writeLegs(array $ordered): void
    {
        $previous = null;

        foreach ($ordered as $entry) {
            $item = $entry['item'];
            $stop = $entry['stop'];
            $leg = $this->routedLeg($previous, $stop);

            if ($leg === null) {
                $item->update([
                    'route_distance_meters' => null,
                    'route_duration_minutes' => null,
                    'route_provider' => null,
                    'route_status' => ItineraryItem::ROUTE_UNRESOLVED,
                ]);
            } else {
                $item->update([
                    'route_distance_meters' => $leg->distanceMeters,
                    'route_duration_minutes' => $leg->durationMinutes,
                    'route_provider' => $leg->provider,
                    'route_status' => ItineraryItem::ROUTE_RESOLVED,
                ]);
            }

            $previous = $stop;
        }
    }

    /**
     * @param  Collection<int, ItineraryItem>  $stored
     * @param  array<int|string, array<string, mixed>>  $draft
     */
    private function deleteRemovedStops(Collection $stored, array $draft): void
    {
        $kept = collect(array_keys($draft))
            ->map(fn ($key) => (int) $key)
            ->filter(fn (int $key) => $key > 0);

        $stored
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->diff($kept)
            ->each(function (int $id) use ($stored) {
                $stored->get($id)?->delete();
            });
    }

    /**
     * What a stop the traveller just added is allowed to be.
     *
     * Stored rows skip this entirely: they were validated when they were new,
     * and re-checking them on every save would refuse a trip its own history
     * the moment a catalogue row changed. Only negative keys — stops that do
     * not exist yet — are judged.
     *
     *   attraction  active, liked while swiping, and not already on the trip.
     *               The liked rule is what ties the editor to the discovery
     *               deck: the picker and this check read the same swipes.
     *   meal/lodging  active, in this trip's province, and not already stored
     *               on it. There is no liked rule because places have no
     *               swipes; the picker and this check read the same active rows
     *               instead. The province rule is what ties a stop to the trip:
     *               the generator only ever plans inside one province, so a
     *               Batangas hotel on a Benguet trip is a nonsense the editor
     *               must not create. Repeats inside one save are allowed — two
     *               nights at one hotel, lunch twice at a favorite — while a
     *               repeat of a stored stop is still a duplicate.
     *   custom  the traveller's own booking. Always allowed — it is theirs,
     *               not the catalogue's, and there is nothing to check it
     *               against.
     *
     * @param  Collection<int, ItineraryItem>  $stored
     * @param  array<int|string, array<string, mixed>>  $draft
     * @param  Collection<int, Destination>  $destinations
     * @param  Collection<int, \App\Models\Place>  $places
     */
    private function resolveNewStops(
        Itinerary $itinerary,
        User $user,
        Collection $stored,
        array $draft,
        Collection $destinations,
        Collection $places
    ): void {
        $liked = DestinationSwipe::query()
            ->where('user_id', $user->id)
            ->where('action', 'liked')
            ->pluck('destination_id')
            ->map(fn ($id) => (int) $id);

        $usedDestinations = $stored
            ->pluck('destination_id')
            ->map(fn ($id) => (int) $id);

        $usedPlaces = $stored
            ->pluck('place_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id);

        foreach ($draft as $key => $fields) {
            if ((int) $key > 0) {
                continue;
            }

            $fields = is_array($fields) ? $fields : [];
            $stop = ItineraryStop::fromFields($fields, $destinations, $places);

            if ($stop && $stop->isAttraction()) {
                $this->resolveNewDestination($stop, $liked, $usedDestinations);

                continue;
            }

            if ($stop && $stop->place) {
                $this->resolveNewPlace($itinerary, $stop, $usedPlaces);

                continue;
            }

            // Custom entries and rows pointing at catalogue rows that are gone
            // need nothing: the former is the traveller's own, and the latter
            // is rejected earlier by the request's exists rules.
        }
    }

    /**
     * @param  Collection<int, int>  $liked
     * @param  Collection<int, int>  $usedDestinations
     */
    private function resolveNewDestination(
        ItineraryStop $stop,
        Collection $liked,
        Collection $usedDestinations
    ): void {
        $destination = $stop->destination;
        $destinationId = (int) $destination?->id;

        if (! $destination || ! $destination->is_active) {
            throw new RuntimeException(
                'One of the places you tried to add is no longer available.'
            );
        }

        if (! $liked->contains($destinationId)) {
            throw new RuntimeException(
                'You can only add places you liked while swiping.'
            );
        }

        if ($usedDestinations->contains($destinationId)) {
            throw new RuntimeException(
                $destination->name.' is already on this trip.'
            );
        }

        $usedDestinations->push($destinationId);
    }

    /**
     * @param  Collection<int, int>  $usedPlaces
     */
    private function resolveNewPlace(
        Itinerary $itinerary,
        ItineraryStop $stop,
        Collection $usedPlaces
    ): void {
        $place = $stop->place;
        $placeId = (int) $place?->id;

        if (! $place || ! $place->is_active) {
            throw new RuntimeException(
                'One of the places you tried to add is no longer available.'
            );
        }

        if ($place->province !== $itinerary->area) {
            throw new RuntimeException(
                $place->name.' is in '.$place->province.', not on this trip.'
            );
        }

        if ($usedPlaces->contains($placeId)) {
            throw new RuntimeException(
                $place->name.' is already on this trip.'
            );
        }

        // Deliberately NOT pushed: two new rows for the same hotel in one save
        // are a multi-night stay, and the first night carries the charge while
        // the repeats store 0 (see apply()). Refusing the second row would make
        // a two-night stay unsavable. Stored rows are what this guards — they
        // were seeded into $usedPlaces above, and a repeat of one of those is
        // still a duplicate.
    }

    /**
     * How long a reflowed stop runs: the window the traveller typed, falling
     * back to the stop's own visit length.
     *
     * @param  array<string, mixed>  $entry
     */
    private function durationFor(array $entry, ?ItineraryStop $stop): int
    {
        $fields = $entry['fields'];

        $duration = ItinerarySchedule::durationBetween(
            $fields['start_time'] ?? null,
            $fields['end_time'] ?? null
        );

        if ($duration !== null) {
            return $duration;
        }

        return max(1, $stop?->durationMinutes() ?? 60);
    }

    /**
     * A price is curated catalogue data, not something a traveller edits.
     *
     * A stored stop therefore keeps the figure it was generated with, and a
     * stop the traveller just added takes the stop's own snapshot. The posted
     * value is ignored outright: there is no cost field in the form, and
     * honouring one anyway would let anyone rewrite a price by crafting a
     * request. Editing a time must not quietly restate what a place costs.
     *
     * The `?? 0` is the column talking, not the catalogue: `estimated_cost`
     * cannot hold null today, so an unpriced stop stores 0 while the stop
     * itself still reports unknown. Days 4-5 teach the totals to say the
     * budget is incomplete instead.
     *
     * A second night at the same hotel stores 0: the first occurrence carries
     * the room charge and repeats must not double it. `$chargedLodging` is the
     * trip-wide record apply() seeds from the stored rows.
     */
    private function costFor(?ItineraryItem $item, ?ItineraryStop $stop, array &$chargedLodging): float
    {
        if ($item) {
            return round((float) $item->estimated_cost, 2);
        }

        if ($stop && $stop->kind === ItineraryItem::TYPE_LODGING && $stop->place) {
            $placeId = (int) $stop->place->id;

            if (in_array($placeId, $chargedLodging, true)) {
                return 0.0;
            }

            $chargedLodging[] = $placeId;
        }

        return round($stop?->snapshotCost() ?? 0, 2);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function noteFor(array $fields): ?string
    {
        $note = trim((string) ($fields['note'] ?? ''));

        return $note === '' ? null : $note;
    }

    /**
     * Traveller-typed text for a custom stop: blank collapses to null so the
     * column answers "nothing typed" rather than holding an empty string that
     * reads as a name. Truncated to the column, because the request's max and
     * the schema's varchar must agree and the schema wins.
     */
    private function customText(mixed $value, int $length): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $length);
    }

    /**
     * A traveller-typed pin: numeric or nothing. The request already enforces
     * the ranges; this only decides what reaches the column.
     */
    private function customCoordinate(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * How many stops on a saved trip have no price anyone verified.
     *
     * A custom entry is always unpriced — it is the traveller's own, and the
     * catalogue has no figure for it. A meal or a hotel is unpriced when its
     * place row carries no verified band. Attractions always carry their
     * curated estimate. The header total sums stored figures, so this count
     * is what stops "₱8,375" from reading as a complete answer when three of
     * the stops were counted at zero.
     */
    public function countUnpricedStops(Itinerary $itinerary): int
    {
        return $itinerary->days()
            ->with('items.place')
            ->get()
            ->pluck('items')
            ->flatten()
            ->filter(function (ItineraryItem $item): bool {
                if ($item->isCustom()) {
                    return true;
                }

                return $item->place && ! $item->place->hasVerifiedPrice();
            })
            ->count();
    }

    /**
     * The header shows a cost and a match average. Both are derived from the
     * stops, so leaving them alone after an edit would put a number on screen
     * that no longer describes the trip.
     */
    private function refreshTotals(Itinerary $itinerary, User $user): void
    {
        $items = $itinerary->days()
            ->with('items')
            ->get()
            ->pluck('items')
            ->flatten();

        $total = round(
            $items->sum(fn (ItineraryItem $item) => (float) $item->estimated_cost),
            2
        );

        $scores = $this->recommendations
            ->recommend($user, 1000)
            ->keyBy('id');

        $matched = $items
            ->map(fn (ItineraryItem $item) => $scores->get($item->destination_id))
            ->filter()
            ->map(fn (Destination $destination) => (float) $destination->match_score);

        $itinerary->update([
            'total_estimated_cost' => $total,
            // A place the traveller added by hand is not in the recommendation
            // set, so it earns no score. With none of the stops scoring, keep
            // whatever the generator recorded rather than writing a zero that
            // reads as "no match".
            'match_score' => $matched->isEmpty()
                ? $itinerary->match_score
                : round($matched->avg(), 2),
        ]);
    }
}
