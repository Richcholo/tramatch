<?php

namespace App\Services\Routing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

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
     * The cache key for one pair of pins.
     *
     * Public so a test can put a specific payload under a specific key without
     * duplicating this format in a second place, where a change to one would
     * leave the other writing to keys nothing reads.
     */
    public function cacheKey(
        float $fromLatitude,
        float $fromLongitude,
        float $toLatitude,
        float $toLongitude
    ): string {
        return sprintf(
            'route:%s:%.5f:%.5f:%.5f:%.5f',
            config('planning.transport.profile', 'driving'),
            $fromLatitude,
            $fromLongitude,
            $toLatitude,
            $toLongitude,
        );
    }

    /**
     * A routed leg, or null when the road cannot be resolved.
     *
     * Null callers must handle, not work around: an unresolved leg stays
     * `route_status = unresolved` on the item and the page says so. Falling
     * back to the flat 45-minute estimate here is the exact claim the panel
     * called out, and it would hide inside every caller at once.
     *
     * **THE CACHE IS INSIDE THE GUARD, AND THAT IS THE WHOLE POINT.** The client
     * catches its own transport failures, but the cache was not guarded — so the
     * cache store could throw straight through this method, into
     * `ItineraryEditor::writeLegs()`, and fail a save or a reflow preview. It
     * went live that way: the suite runs the `array` cache driver, which cannot
     * fail, while production runs the `database` driver and hits the `cache`
     * table on every single leg. A layer that cannot be exercised on the test
     * path is exactly the layer that throws on the host.
     *
     * So the guarantee lives here rather than in the client: this method is
     * the boundary the callers trust, so everything it touches is guarded,
     * including the parts written for its own benefit.
     *
     * ## THE CACHE STORES SCALARS, NOT THE OBJECT, AND THAT IS LOAD-BEARING
     *
     * This is the lesson `DestinationCarousel` was rebuilt around and the
     * routing layer never got: the cache driver is the database, entries are
     * serialised, and **they survive a deploy**. An object graph comes back as
     * `__PHP_Incomplete_Class` when its class no longer loads, and behind a
     * strict return type that is a `TypeError` rather than one missed rebuild.
     *
     * On the live host it was neither theoretical nor rare: every leg came back
     * `unresolved`, and the log said `routing.cache_unreadable` — the WRONG
     * label, because the cache read perfectly well and handed back something
     * stale. A cache that is *readable and wrong* is a different fault from a
     * cache that cannot be read, and calling them the same thing sent the
     * diagnosis after the wrong subsystem.
     *
     * Three scalars cannot go stale in that way, so `legFromCache()` also
     * tolerates a legacy `RouteLeg` object and treats anything else as a miss.
     *
     * ## A FAILED LOOKUP LEAVES NOTHING BEHIND
     *
     * On failure the key is forgotten rather than written. Note what this is
     * NOT: an earlier version of this method claimed that `Cache::remember()`
     * cached failures for the full TTL and that "every leg that failed once
     * failed forever", which would have explained why fixing the User-Agent did
     * not fix routing. **That was wrong** — `remember()` tests `is_null($value)`
     * and therefore treats a stored `null` as a miss, so a failure was never
     * honoured on a later call anyway. The claim was falsified by reverting
     * only this method's body and watching `test_a_failed_lookup_is_never_cached`
     * pass unchanged.
     *
     * So the real cost of forgetting here is only that a useless `null` row is
     * not written on every failed request. Kept because it is free, not
     * because it fixes anything — and recorded here so nobody rebuilds the
     * "permanent null" theory on top of it.
     *
     * The host's all-`unresolved` state had exactly one cause, and it was the
     * `__PHP_Incomplete_Class` above.
     */
    public function leg(
        float $fromLatitude,
        float $fromLongitude,
        float $toLatitude,
        float $toLongitude
    ): ?RouteLeg {
        $key = $this->cacheKey($fromLatitude, $fromLongitude, $toLatitude, $toLongitude);

        $days = (int) config('planning.routing.cache_days', 30);

        $readable = true;
        $cached = null;

        try {
            $cached = Cache::get($key);
        } catch (Throwable $e) {
            $readable = false;

            Log::warning('routing.cache_unreadable', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }

        if ($readable && $cached !== null) {
            $leg = $this->legFromCache($cached);

            if ($leg !== null) {
                return $leg;
            }

            // Readable, and not what we put there. Forget it and ask the road,
            // rather than reporting a leg as unmeasured because of a payload a
            // past deploy left behind.
            //
            // The log line NAMES the class that would not load, because
            // `get_debug_type()` on an `__PHP_Incomplete_Class` just says
            // `__PHP_Incomplete_Class` -- true, and no help at all. PHP keeps
            // the original name in a magic property for exactly this reason.
            Log::warning('routing.cache_stale', [
                'key' => $key,
                'found' => $this->describeCached($cached),
            ]);
        }

        try {
            $leg = $this->client->route($fromLatitude, $fromLongitude, $toLatitude, $toLongitude);
        } catch (Throwable $e) {
            // `OsrmClient` logs its own transport failures. This is the net
            // under an unexpected throw, which would otherwise escape into a
            // save transaction.
            Log::warning('routing.transport_failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        try {
            if ($leg === null) {
                Cache::forget($key);
            } else {
                Cache::put(
                    $key,
                    $this->scalarsFrom($leg),
                    now()->addDays(max(1, $days))
                );
            }
        } catch (Throwable $e) {
            // A cache that cannot be WRITTEN is only a lost memo. The leg is
            // real and the caller still gets it.
            Log::warning('routing.cache_unwritable', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }

        return $leg;
    }

    /**
     * Name whatever was in the cache, usefully.
     *
     * @param  mixed  $cached
     */
    private function describeCached($cached): string
    {
        if ($cached instanceof \__PHP_Incomplete_Class) {
            // NOT `$cached->__PHP_Incomplete_Class_Name`. PHP refuses property
            // access on an incomplete object and throws a fatal, so the name has
            // to come out through an array cast -- where it appears as an
            // ordinary key.
            $parts = (array) $cached;

            return '__PHP_Incomplete_Class('
                . ($parts['__PHP_Incomplete_Class_Name'] ?? 'unknown')
                . ')';
        }

        return get_debug_type($cached);
    }

    /**
     * Rebuild a leg from a cached payload, or null when it is not one.
     *
     * Anything unrecognised is a MISS, never an error and never a value passed
     * on. This is the whole guard: without it a payload this method did not
     * write reaches a `?RouteLeg` return type and takes the caller down instead
     * of costing one rebuild.
     *
     * @param  mixed  $cached
     */
    private function legFromCache($cached): ?RouteLeg
    {
        // A RouteLeg object, written by a version before this stored scalars.
        // Still perfectly usable; the class exists, so it unserialised fine.
        if ($cached instanceof RouteLeg) {
            return $cached;
        }

        if (! is_array($cached)) {
            return null;
        }

        foreach (['distance_meters', 'duration_minutes', 'provider'] as $field) {
            if (! array_key_exists($field, $cached)) {
                return null;
            }
        }

        return new RouteLeg(
            (int) $cached['distance_meters'],
            (int) $cached['duration_minutes'],
            (string) $cached['provider'],
        );
    }

    /**
     * The leg as the three scalars that get cached, or null for no leg.
     *
     * @return array{distance_meters: int, duration_minutes: int, provider: string}|null
     */
    private function scalarsFrom(?RouteLeg $leg): ?array
    {
        return $leg === null
            ? null
            : [
                'distance_meters' => $leg->distanceMeters,
                'duration_minutes' => $leg->durationMinutes,
                'provider' => $leg->provider,
            ];
    }
}