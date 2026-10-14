<?php

namespace App\Services\Routing;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Driving routes from an OSRM-compatible API.
 *
 * OSRM first, with no key, because the routing provider the config names
 * (OpenRouteService) needs an API key nobody has issued yet — and a routing
 * feature that only works after a signup is a feature the demo cannot show.
 * The client speaks plain OSRM (`/route/v1/{profile}/{lng},{lat};…`), so a
 * self-hosted OSRM or any compatible endpoint slots in through
 * `ROUTING_BASE_URL` with no code change.
 *
 * THE DEFAULT ENDPOINT IS THE PUBLIC DEMO SERVER, and that is a deliberate,
 * documented compromise rather than an oversight. It needs no key, it answers
 * the whole country, and with the cache in `RouteService` a leg is fetched
 * once and then read locally for a month. It is not production routing
 * infrastructure: run your own OSRM or buy a quota before promising a
 * traveller these numbers at scale. Nothing here assumes otherwise — every
 * failure resolves to null, never to a guessed leg.
 *
 * NULL IS THE ONLY FAILURE MODE. A 500, a timeout, an empty routes array, a
 * DNS failure: all of them mean "unresolved", and the itinerary says so on
 * screen. Throwing would turn one unreachable routing host into a failed
 * save, which is the exact failure `queue:drain` was taught not to have.
 */
class OsrmClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $profile,
        private readonly int $timeoutSeconds,
    ) {
    }

    public static function fromConfig(): self
    {
        $routing = config('services.routing', []);

        return new self(
            rtrim((string) ($routing['base_url'] ?? 'https://router.project-osrm.org'), '/'),
            (string) config('planning.transport.profile', 'driving'),
            (int) config('planning.timeouts.routing_seconds', 8),
        );
    }

    /**
     * Route one pair of pins, or null when there is no answer.
     *
     * Coordinates arrive in the order humans say them (latitude first) and go
     * out in the order the API demands (longitude first). Mixing those up
     * puts the pin in the wrong ocean and still returns 200 with a route, so
     * the order is assembled in exactly one place — here.
     */
    public function route(
        float $fromLatitude,
        float $fromLongitude,
        float $toLatitude,
        float $toLongitude
    ): ?RouteLeg {
        try {
            $response = Http::timeout($this->timeoutSeconds)->get(
                $this->baseUrl.'/route/v1/'.$this->profile.'/'
                .$fromLongitude.','.$fromLatitude.';'
                .$toLongitude.','.$toLatitude,
                ['overview' => 'false']
            );
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $route = $response->json('routes.0');

        if (! is_array($route)) {
            return null;
        }

        $meters = $route['distance'] ?? null;
        $seconds = $route['duration'] ?? null;

        if (! is_numeric($meters) || ! is_numeric($seconds)) {
            return null;
        }

        $meters = (int) round((float) $meters);
        $minutes = (int) ceil((float) $seconds / 60);

        if ($meters < 0 || $minutes <= 0) {
            return null;
        }

        return new RouteLeg($meters, $minutes, 'osrm');
    }
}
