<?php

namespace App\Services\Routing;

use Illuminate\Support\Facades\Cache;

/**
 * The application's one way to ask how far two pins are apart by road.
 *
 * A cache with a client behind it, in that order of importance. Coordinates
 * barely move — a restaurant does not relocate between saves — so without the
 * cache every save re-buys the same legs, and with it a day's re-saves after
 * the first are free. The key rounds to five decimals (about a metre), which
 * is the point where two pins stop being the same leg.
 *
 * The TTL is a month because road distances go stale slowly and the failure
 * mode of a stale leg is a slightly wrong minute count, not a wrong trip.
 * Cache driver is whatever the app runs (database in production, array under
 * test), so this needs no table and no migration — a dedicated route table
 * would be a second source of truth for data that is legitimately tossable.
 */
class RouteService
{
    public function __construct(
        private readonly OsrmClient $client,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(OsrmClient::fromConfig());
    }

    /**
     * A routed leg, or null when the road cannot be resolved.
     *
     * Null callers must handle, not work around: an unresolved leg stays
     * `route_status = unresolved` on the item and the page says so. Falling
     * back to the flat 45-minute estimate here is the exact claim the panel
     * called out, and it would hide inside every caller at once.
     */
    public function leg(
        float $fromLatitude,
        float $fromLongitude,
        float $toLatitude,
        float $toLongitude
    ): ?RouteLeg {
        $key = sprintf(
            'route:%s:%.5f:%.5f:%.5f:%.5f',
            config('planning.transport.profile', 'driving'),
            $fromLatitude,
            $fromLongitude,
            $toLatitude,
            $toLongitude,
        );

        $days = (int) config('planning.routing.cache_days', 30);

        return Cache::remember(
            $key,
            now()->addDays(max(1, $days)),
            fn (): ?RouteLeg => $this->client->route(
                $fromLatitude,
                $fromLongitude,
                $toLatitude,
                $toLongitude
            )
        );
    }
}
