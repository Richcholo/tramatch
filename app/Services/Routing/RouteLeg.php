<?php

namespace App\Services\Routing;

/**
 * One routed leg between two pins: how far along the road, and how long a
 * drive takes.
 *
 * A value, not a row. Meters and minutes are integers because a route that
 * claims 7.005 minutes is implying a precision the provider does not have —
 * see the rounding in `OsrmClient`. Anything that needs the geometry draws it
 * in the browser instead: `routing.js` already asks OSRM for the shape, so
 * storing polylines in the database would pay kilobytes per leg to duplicate
 * a line the map fetches itself.
 */
final readonly class RouteLeg
{
    public function __construct(
        public int $distanceMeters,
        public int $durationMinutes,
        public string $provider,
    ) {
    }
}
