<?php

namespace App\Services\Routing;

use App\Models\ItineraryItem;

/**
 * Writing the road onto a day's stops — ONE implementation, two callers.
 *
 * The editor has always resolved every leg it writes. The generator did not,
 * and that was a recorded decision: "routing six legs synchronously inside a
 * generation request is the wrong latency trade". It was the wrong call, and
 * the reason it was made the way it was made is the interesting part — the
 * generator's legs were left `unresolved` with the flat 45 in
 * `travel_minutes_from_previous`, and the page printed that flat number in the
 * same slot a measured leg uses. So a trip the traveller never edited showed
 * "45 min travel" with nothing to say it had never met the road, which is the
 * panel's complaint reproduced inside the feature built to answer it.
 *
 * So this is a trait rather than a second method on the generator. Two copies
 * of "resolve the leg, write it onto the row" is how the editor and the
 * generator end up disagreeing about which legs exist — and there is no way to
 * tell from a row which implementation wrote it.
 *
 * CONSECUTIVE PAIRS ONLY. There is no leg into the first stop of a day: the day
 * starts wherever the traveller woke up, not at the previous day's last pin.
 * That is why the first stop of every day is `unresolved` by construction and
 * not by failure.
 *
 * @see RouteService for the cache and the "every failure is null" promise.
 */
trait WritesLegs
{
    /**
     * Resolve and store every leg of one day's stops, in order.
     *
     * Runs after the rows exist, because it writes to them. A leg that cannot
     * be routed is written as `unresolved` with its numbers nulled — never as
     * zero, and never left holding a previous day's measurement, which would be
     * a different road entirely.
     *
     * The budget exists because generation now routes, and a 30-day trip is 87
     * legs. Unattempted legs stay `unresolved`, which is the truth: we do not
     * have a measured drive for that pair. The editor resolves them on the
     * first save, which is where a traveller who cares about one leg will go.
     *
     * @param  iterable<ItineraryItem>  $items  consecutive stops of ONE day
     * @return int how many legs were actually attempted
     */
    protected function writeLegs(iterable $items, int $budget = PHP_INT_MAX): int
    {
        $previous = null;
        $attempted = 0;

        foreach ($items as $item) {
            $leg = null;

            if ($previous !== null && $attempted < $budget) {
                $leg = $this->routeBetween($previous, $item);
                $attempted++;
            }

            $item->update($leg === null
                ? [
                    'route_distance_meters' => null,
                    'route_duration_minutes' => null,
                    'route_provider' => null,
                    'route_status' => ItineraryItem::ROUTE_UNRESOLVED,
                ]
                : [
                    'route_distance_meters' => $leg->distanceMeters,
                    'route_duration_minutes' => $leg->durationMinutes,
                    'route_provider' => $leg->provider,
                    'route_status' => ItineraryItem::ROUTE_RESOLVED,
                ]);

            $previous = $item;
        }

        return $attempted;
    }

    /**
     * The road between two things that can report a pin, or null.
     *
     * Typed against the SHAPE (`latitude()`/`longitude()`) rather than a class,
     * because two callers need this and they hold two different things:
     * `ItineraryStop` during an edit, when the pins are still only in the posted
     * form, and `ItineraryItem` once the row is saved. Both answer those two
     * methods and neither is the other's subclass, so a shared implementation
     * has to be duck-typed or the editor keeps a private copy.
     *
     * Pins are checked BEFORE the client is touched, so an unpinned stop never
     * costs an HTTP call to learn what the catalogue already knows. A custom
     * entry with an address and no coordinates is the common case.
     */
    private function routeBetween(?object $from, ?object $to): ?RouteLeg
    {
        if ($from === null || $to === null) {
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
}