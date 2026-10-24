<?php

namespace App\Services\Routing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        private readonly string $userAgent,
    ) {
    }

    public static function fromConfig(): self
    {
        $routing = config('services.routing', []);

        return new self(
            rtrim((string) ($routing['base_url'] ?? 'https://router.project-osrm.org'), '/'),
            (string) config('planning.transport.profile', 'driving'),
            (int) config('planning.timeouts.routing_seconds', 8),
            (string) ($routing['user_agent'] ?? 'TraMatch/1.0'),
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
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders(['User-Agent' => $this->userAgent])
                ->get(
                    $this->baseUrl.'/route/v1/'.$this->profile.'/'
                    .$fromLongitude.','.$fromLatitude.';'
                    .$toLongitude.','.$toLatitude,
                    ['overview' => 'false']
                );
        } catch (Throwable $e) {
            // Swallowed, but never silently. A routing failure that leaves no
            // trace is indistinguishable from a routing layer that is not
            // running at all, and the first symptom of this was a user
            // reporting that reflow "uses generic times" with nothing anywhere
            // saying why. A certificate failure on a host without a CA bundle
            // looks exactly like a provider that stopped answering, and the
            // two need different fixes.
            Log::warning('routing.transport_failed', [
                'url' => $this->baseUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            // A 403 is not a provider having a bad day — on the demo server it
            // means the request was not identifiable, and no amount of retrying
            // fixes it. Logged separately from the transport failures above
            // because the remedy is opposite: a header, not a backoff.
            Log::warning('routing.refused', [
                'url' => $this->baseUrl,
                'status' => $response->status(),
                'user_agent' => $this->userAgent,
            ]);

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

    public function vicinity(float $lng, float $lat, int $limit = 10): array
    {
        $base = rtrim((string) config('routing.base_url'), '/');
        $url = $base.'/nearest/v1/driving/'.$lng.','.$lat;
        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('crawling.user_agent', 'TraMatch/1.0'),
            ])->timeout((int) config('routing.timeouts.request_seconds', 10))->get($url, [
                'number' => $limit,
                'geometries' => 'geojson',
            ]);
            if (!$response->ok()) {
                return [];
            }
            $json = $response->json();
            return data_get($json, 'waypoints', []);
        } catch (Throwable) {
            return [];
        }
    }

}
