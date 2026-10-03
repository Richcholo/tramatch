<?php

namespace App\Services;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\User;
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
 */
class ItineraryEditor
{
    public function __construct(
        private RecommendationService $recommendations
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

            $destinations = $this->resolveDestinations(
                $user,
                $stored,
                $draft
            );

            foreach ($days as $day) {
                $this->writeDay($day, $stored, $draft, $destinations);
            }

            $this->refreshTotals($itinerary, $user);

            return $itinerary->load('days.items.destination');
        });
    }

    /**
     * Recompute the times on every day of a draft without writing anything, so
     * the editor can show the traveller what a reflow would produce.
     *
     * Durations come from the window the traveller typed, falling back to the
     * destination's own recommended visit length, so a stop whose times are
     * still blank lands somewhere sensible instead of at 00:00.
     *
     * @param  array<int|string, array<string, mixed>>  $draft
     * @return array<string, array<string, array<string, mixed>>> keyed by day id
     */
    public function previewReflow(Itinerary $itinerary, array $draft): array
    {
        $days = $itinerary->days()->get()->keyBy('id');

        $destinations = $this->lookupDestinations($draft);

        $result = [];

        foreach ($days as $day) {
            $stops = [];

            foreach ($this->entriesForDay($day, $draft) as $entry) {
                $stops[] = [
                    'id' => (int) $entry['key'],
                    'duration' => $this->durationFor($entry, $destinations),
                ];
            }

            $result[(string) $day->id] = ItinerarySchedule::reflow($stops);
        }

        return $result;
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
     */
    private function writeDay(
        ItineraryDay $day,
        Collection $stored,
        array $draft,
        Collection $destinations
    ): void {
        $placed = [];
        $previousEnd = null;

        foreach ($this->entriesForDay($day, $draft) as $position => $entry) {
            $key = (int) $entry['key'];
            $fields = $entry['fields'];
            $destination = $destinations->get(
                (int) ($fields['destination_id'] ?? 0)
            );

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
            if (($start === null || $end === null) && $destination) {
                $slot = ItinerarySchedule::nextSlot(
                    $placed,
                    max(
                        1,
                        (int) ceil((float) $destination->recommended_minutes)
                    )
                );

                $start ??= ItinerarySchedule::parse($slot['start_time']);
                $end ??= ItinerarySchedule::parse($slot['end_time']);
            }

            $attributes = [
                'itinerary_day_id' => $day->id,
                'destination_id' => $destination?->id,
                'sort_order' => $position + 1,
                'start_time' => $start === null ? null : ItinerarySchedule::format($start),
                'end_time' => $end === null ? null : ItinerarySchedule::format($end),
                'travel_minutes_from_previous' => ItinerarySchedule::travelBetween(
                    $previousEnd,
                    $start
                ),
                'estimated_cost' => $this->costFor($item, $destination),
                'note' => $this->noteFor($fields),
            ];

            if ($item) {
                $item->update($attributes);
            } elseif ($destination) {
                $item = $day->items()->create($attributes);
            }

            if ($item && $item->start_time && $item->end_time) {
                $placed[] = [
                    'start_time' => $item->start_time,
                    'end_time' => $item->end_time,
                ];

                $previousEnd = ItinerarySchedule::parse($item->end_time);
            }
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
     * Look up every destination the draft touches.
     *
     * A stored stop keeps the destination it already had. A stop the traveller
     * just added has to be an active destination they actually liked, and it
     * cannot repeat a place the itinerary already visits: the generator never
     * repeats one, so allowing it here would quietly double a cost and draw a
     * second pin on the map.
     *
     * @param  Collection<int, ItineraryItem>  $stored
     * @param  array<int|string, array<string, mixed>>  $draft
     * @return Collection<int, Destination>
     */
    private function resolveDestinations(
        User $user,
        Collection $stored,
        array $draft
    ): Collection {
        $destinations = $this->lookupDestinations($draft);

        $liked = DestinationSwipe::query()
            ->where('user_id', $user->id)
            ->where('action', 'liked')
            ->pluck('destination_id')
            ->map(fn ($id) => (int) $id);

        $alreadyInItinerary = $stored
            ->pluck('destination_id')
            ->map(fn ($id) => (int) $id);

        foreach ($draft as $key => $fields) {
            if ((int) $key > 0) {
                continue;
            }

            $destinationId = (int) ($fields['destination_id'] ?? 0);
            $destination = $destinations->get($destinationId);

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

            if ($alreadyInItinerary->contains($destinationId)) {
                throw new RuntimeException(
                    $destination->name.' is already on this trip.'
                );
            }

            $alreadyInItinerary->push($destinationId);
        }

        return $destinations;
    }

    /**
     * Every destination the draft mentions, whether stored or freshly added.
     * Lookups only: nothing here is trusted, because this is also the path the
     * reflow preview takes on a draft that has not been validated for saving.
     *
     * @param  array<int|string, array<string, mixed>>  $draft
     * @return Collection<int, Destination>
     */
    private function lookupDestinations(array $draft): Collection
    {
        $ids = collect($draft)
            ->map(fn ($fields) => (int) ($fields['destination_id'] ?? 0))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        return Destination::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  Collection<int, Destination>  $destinations
     */
    private function durationFor(array $entry, Collection $destinations): int
    {
        $fields = $entry['fields'];

        $duration = ItinerarySchedule::durationBetween(
            $fields['start_time'] ?? null,
            $fields['end_time'] ?? null
        );

        if ($duration !== null) {
            return $duration;
        }

        $destination = $destinations->get(
            (int) ($fields['destination_id'] ?? 0)
        );

        return max(
            1,
            (int) ceil((float) ($destination?->recommended_minutes ?? 60))
        );
    }

    /**
     * A price is curated catalogue data, not something a traveller edits.
     *
     * A stored stop therefore keeps the figure it was generated with, and a
     * stop the traveller just added takes the destination's own estimate. The
     * posted value is ignored outright: there is no cost field in the form, and
     * honouring one anyway would let anyone rewrite a price by crafting a
     * request. Editing a time must not quietly restate what a place costs.
     */
    private function costFor(?ItineraryItem $item, ?Destination $destination): float
    {
        if ($item) {
            return round((float) $item->estimated_cost, 2);
        }

        return round((float) ($destination?->estimated_cost ?? 0), 2);
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