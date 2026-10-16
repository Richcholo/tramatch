<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use App\Services\Routing\OsrmClient;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Road-derived legs: the routing client, the cache, and what the editor does
 * with an answer — or without one.
 *
 * The base TestCase stubs the OSRM host with a fixed 8.4 km / 10 min leg, so
 * no test here touches the network. Tests that need a different answer
 * re-stub the host themselves.
 */
class RoutingLegsTest extends TestCase
{
    use RefreshDatabase;

    private function osrm(array $routes, int $status = 200): void
    {
        $this->stubOsrm(fn () => Http::response(
            ['code' => 'Ok', 'routes' => $routes],
            $status
        ));
    }

    public function test_the_client_turns_a_route_into_meters_and_minutes(): void
    {
        $this->osrm([['distance' => 15200.6, 'duration' => 1500.2]]);

        $leg = app(OsrmClient::class)->route(14.3, 121.4, 14.6, 121.8);

        $this->assertNotNull($leg);
        $this->assertSame(15201, $leg->distanceMeters);
        // 1500.2 seconds is 25.003 minutes: rounded up to 26, never down to
        // 25, because a leg that claims less than the road takes is a missed
        // connection.
        $this->assertSame(26, $leg->durationMinutes);
        $this->assertSame('osrm', $leg->provider);
    }

    public function test_every_upstream_failure_is_an_unresolved_leg(): void
    {
        $route = fn () => app(OsrmClient::class)->route(14.3, 121.4, 14.6, 121.8);

        $this->osrm([], 500);
        $this->assertNull($route());

        $this->osrm([]);
        $this->assertNull($route());

        $this->osrm([['distance' => 'far', 'duration' => 'a while']]);
        $this->assertNull($route());

        $this->stubOsrm(fn () => Http::failedConnection('Could not resolve host'));
        $this->assertNull($route());
    }

    /**
     * The header that made routing work at all.
     *
     * MEASURED, NOT GUESSED, on 2026-10-15 against the public demo server:
     * a request carrying NO User-Agent is answered 403, and the same request
     * with any User-Agent at all is answered 200. Guzzle 7 — which is what
     * Laravel's Http client is — sets no default one, so without this the
     * client was refused everywhere and every leg quietly fell back to the
     * flat estimate. The tests stub the endpoint and therefore never saw it.
     *
     * The stub below reproduces the provider's real behaviour rather than
     * asserting the header's presence: it refuses a UA-less request exactly
     * as the demo server does. Asserting `$request->hasHeader(...)` alone
     * would pass while the provider kept refusing us, which is the shape of
     * the bug this pins.
     */
    public function test_a_provider_that_refuses_anonymous_requests_still_routes(): void
    {
        Http::fake([
            // A fake receives Illuminate\Http\Client\Request, NOT the Guzzle PSR-7
            // message: type-hinting the PSR-7 class here fails the stub for a
            // reason that has nothing to do with routing.
            'router.project-osrm.org*' => function (ClientRequest $request) {
                if (! $request->hasHeader('User-Agent') || $request->header('User-Agent')[0] === '') {
                    return Http::response('Forbidden', 403);
                }

                return Http::response([
                    'code' => 'Ok',
                    'routes' => [['distance' => 8400, 'duration' => 600]],
                ], 200);
            },
        ]);

        $leg = app(OsrmClient::class)->route(14.3, 121.4, 14.6, 121.8);

        $this->assertNotNull($leg);
        $this->assertSame(8400, $leg->distanceMeters);

        // And it identifies itself as something a human can answer to.
        Http::assertSent(fn (ClientRequest $request) => str_contains(
            (string) $request->header('User-Agent')[0],
            'mailto:'
        ));
    }

    public function test_a_cached_leg_issues_no_second_request(): void
    {
        $service = app(\App\Services\Routing\RouteService::class);

        $service->leg(14.3, 121.4, 14.6, 121.8);
        $service->leg(14.3, 121.4, 14.6, 121.8);

        Http::assertSentCount(1);
    }

    /**
     * The failure that shipped to production and failed every save.
     *
     * The client guards its own transport errors, but the cache layer sat
     * OUTSIDE that guard. On the suite the cache driver is `array` and cannot
     * fail; on the host it is `database` and hits the `cache` table on every
     * leg, so an unreadable cache threw out of `RouteService::leg()`, through
     * `ItineraryEditor::routedLeg()`, and took the save down with it. This
     * reproduces exactly that, against the whole HTTP save rather than the
     * service in isolation, because the guarantee being tested is that a
     * routing failure cannot fail a traveller's trip.
     */
    public function test_an_unreadable_route_cache_cannot_fail_a_save(): void
    {
        $this->breakTheRouteCache();

        [$user, $itinerary, $day] = $this->trip();
        $first = $this->stop($day, $this->destination(14.30, 121.40), 1);
        $second = $this->destination(14.60, 121.80);
        $this->like($user, $second);

        $this->actingAs($user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $first->id => $this->row($day->id, 1, $first->destination_id, '09:00', '10:00'),
                    '-1' => $this->row($day->id, 2, $second->id, '10:30', '11:30'),
                ],
            ])
            ->assertRedirect(route('itineraries.show', $itinerary))
            ->assertSessionHasNoErrors();

        $items = $itinerary->fresh()->days->first()->items()->orderBy('sort_order')->get();

        // The stop was still saved, with the honest answer rather than an
        // exception: the leg is unresolved, and nothing was invented for it.
        $this->assertCount(2, $items);
        $this->assertSame('unresolved', $items[1]->route_status);
        $this->assertNull($items[1]->routeSummary());
    }

    /**
     * A reflow must say which of its gaps are measurements and which are the
     * flat estimate.
     *
     * THE TWO PRODUCE IDENTICAL TIMES. A leg the road cannot answer for falls
     * back to TRAVEL_MINUTES, so a day laid out entirely on estimates is
     * byte-identical to one measured on real distances — and a report of the
     * finish time alone cannot tell them apart. That is the panel's complaint
     * reproduced inside the feature built to answer it, and it is why this is
     * counted per day and returned to the browser rather than logged and
     * forgotten.
     */
    public function test_a_reflow_reports_its_estimated_gaps(): void
    {
        [$user, $itinerary, $day] = $this->trip();
        $first = $this->stop($day, $this->destination(14.30, 121.40), 1);
        $second = $this->destination(14.60, 121.80);
        $this->like($user, $second);

        $draft = [
            $first->id => $this->row($day->id, 1, $first->destination_id),
            '-1' => $this->row($day->id, 2, $second->id),
        ];

        // Measured: the stubbed road answers, so nothing is an estimate.
        $measured = $this->actingAs($user)
            ->postJson(route('itineraries.schedule', $itinerary), ['items' => $draft])
            ->assertOk()
            ->json();

        $this->assertSame(0, $measured['estimated_gaps'][(string) $day->id]);

        $this->stubOsrm(fn () => Http::failedConnection('Could not resolve host'));

        // A dead road does NOT make a cached leg unmeasured. The first call
        // resolved this pair and the answer is in hand, so reporting it as an
        // estimate would be its own kind of lie — and it is the reason the
        // cache is worth having.
        $stillMeasured = $this->actingAs($user)
            ->postJson(route('itineraries.schedule', $itinerary), ['items' => $draft])
            ->assertOk()
            ->json();

        $this->assertSame(0, $stillMeasured['estimated_gaps'][(string) $day->id]);

        // Drop the memo and ask again: now the road genuinely cannot answer,
        // so the one leg in this day is an estimate and says so.
        Cache::flush();

        $unmeasured = $this->actingAs($user)
            ->postJson(route('itineraries.schedule', $itinerary), ['items' => $draft])
            ->assertOk()
            ->json();

        $this->assertSame(1, $unmeasured['estimated_gaps'][(string) $day->id]);

        // And it still lays the day out, on the estimate, rather than refusing
        // a schedule because one road is unknown.
        $this->assertSame(
            ['08:00', '09:45'],
            array_values(array_column($unmeasured['days'][(string) $day->id], 'start_time'))
        );
    }

    /**
     * The first stop of a day has no leg into it, which is not a failure.
     *
     * Pinned on its own because it is the rule that stops the notice crying
     * wolf: counting the day's first stop would report at least one estimated
     * gap on EVERY day, including the fully-measured ones, and a warning that
     * is always on is a warning nobody reads.
     */
    public function test_a_days_first_stop_is_never_an_estimated_gap(): void
    {
        $this->stubOsrm(fn () => Http::failedConnection('Could not resolve host'));

        [$user, $itinerary, $day] = $this->trip();
        $only = $this->stop($day, $this->destination(14.30, 121.40), 1);

        $response = $this->actingAs($user)
            ->postJson(route('itineraries.schedule', $itinerary), [
                'items' => [
                    $only->id => $this->row($day->id, 1, $only->destination_id),
                ],
            ])
            ->assertOk();

        $this->assertSame(0, $response->json('estimated_gaps.'.(string) $day->id));
    }

    /**
     * The browser has to say it, not just receive it.
     *
     * The count is only honest if it reaches the traveller. A reflow that
     * quietly substituted the estimate is the bug; one that knows and stays
     * quiet is the same bug with better manners. The wording is asserted
     * because the failure mode here is a notice that mentions the number but
     * not that the times are estimates.
     */
    public function test_the_editor_names_an_estimate_rather_than_reporting_it_as_a_distance(): void
    {
        $source = (string) file_get_contents(resource_path('js/itinerary-editor.js'));

        $this->assertStringContainsString('estimated_gaps', $source);
        $this->assertStringContainsString('an estimate, not a measured drive', $source);

        // The measured case has to claim what it is too, or the two notices
        // differ only in tone.
        $this->assertStringContainsString('timed on real driving distances', $source);
    }

    /**
     * A routing failure must leave a trace.
     *
     * Both layers swallow their failures by design — that is what makes an
     * unreachable road a stored `unresolved` rather than a failed save — and
     * a swallow with no log is how "reflow uses generic times" arrived with
     * nothing anywhere explaining it. Asserting on the log is what keeps the
     * diagnosis available on the host instead of requiring a code change.
     */
    public function test_a_dead_routing_provider_is_logged(): void
    {
        Log::spy();

        $this->stubOsrm(fn () => Http::failedConnection('Could not resolve host'));

        app(OsrmClient::class)->route(14.3, 121.4, 14.6, 121.8);

        Log::shouldHaveReceived('warning')
            ->with('routing.transport_failed', \Mockery::type('array'));
    }

    /**
     * Make the cache store throw for routing keys only.
     *
     * Scoped to the `route:` prefix on purpose: a cache that throws for
     * everything would also break the throttle middleware and the session,
     * and the test would then fail for reasons that have nothing to do with
     * routing. This is the production shape of the fault — a route cache that
     * cannot be read — and nothing more.
     */
    private function breakTheRouteCache(): void
    {
        $store = new class implements Store
        {
            private function guard($key): void
            {
                if (str_contains((string) $key, 'route:')) {
                    throw new RuntimeException('cache table is not readable');
                }
            }

            public function get($key)
            {
                $this->guard($key);

                return null;
            }

            public function many(array $keys)
            {
                return [];
            }

            public function put($key, $value, $seconds)
            {
                return false;
            }

            public function putMany(array $values, $seconds)
            {
                return false;
            }

            public function increment($key, $value = 1)
            {
                $this->guard($key);

                return false;
            }

            public function decrement($key, $value = 1)
            {
                $this->guard($key);

                return false;
            }

            public function forever($key, $value)
            {
                return false;
            }

            public function forget($key)
            {
                return false;
            }

            public function touch($key, $seconds)
            {
                return false;
            }

            public function flush()
            {
                return false;
            }

            public function getPrefix()
            {
                return 'unreadable:';
            }
        };

        // A custom creator returns a REPOSITORY, not a bare store: CacheManager
        // wraps the built-in stores itself and expects a custom one to arrive
        // finished. Handing back the store alone puts a Store where half the
        // app asks for a Repository — which is the harness being wrong again,
        // in a way that fails for reasons unrelated to routing.
        Cache::extend('unreadable', fn () => new CacheRepository($store));

        // extend() registers a CREATOR keyed by driver, and resolve() looks it
        // up via the store's `driver` key — so the store needs an entry of its
        // own. Setting only cache.default is "store not defined".
        config([
            'cache.stores.unreadable' => ['driver' => 'unreadable', 'prefix' => 'unreadable'],
            'cache.default' => 'unreadable',
        ]);

        Cache::forgetDriver('unreadable');
    }

    public function test_reflow_spaces_stops_by_the_road(): void
    {
        [$user, $itinerary, $day] = $this->trip();
        $first = $this->stop($day, $this->destination(14.30, 121.40), 1);
        $second = $this->destination(14.60, 121.80);
        $this->like($user, $second);

        $response = $this->actingAs($user)
            ->postJson(route('itineraries.schedule', $itinerary), [
                'items' => [
                    $first->id => $this->row($day->id, 1, $first->destination_id),
                    '-1' => $this->row($day->id, 2, $second->id),
                ],
            ])
            ->assertOk();

        $slots = $response->json('days.'.(string) $day->id);

        // First stop 08:00–09:00, then the stubbed 10-minute road rather than
        // the flat 45: 09:10–10:10.
        $this->assertSame(['08:00', '09:10'], array_values(array_column($slots, 'start_time')));
        $this->assertSame(['09:00', '10:10'], array_values(array_column($slots, 'end_time')));
    }

    public function test_saving_a_day_persists_the_road(): void
    {
        [$user, $itinerary, $day] = $this->trip();
        $first = $this->stop($day, $this->destination(14.30, 121.40), 1);
        $second = $this->destination(14.60, 121.80);
        $this->like($user, $second);

        $this->actingAs($user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $first->id => $this->row($day->id, 1, $first->destination_id, '09:00', '10:00'),
                    '-1' => $this->row($day->id, 2, $second->id, '10:30', '11:30'),
                ],
            ])
            ->assertRedirect(route('itineraries.show', $itinerary));

        $items = $itinerary->fresh()->days->first()->items()->orderBy('sort_order')->get();

        $this->assertSame('unresolved', $items[0]->route_status);

        $this->assertSame('resolved', $items[1]->route_status);
        $this->assertSame(8400, $items[1]->route_distance_meters);
        $this->assertSame(10, $items[1]->route_duration_minutes);
        $this->assertSame('osrm', $items[1]->route_provider);
    }

    public function test_an_unroutable_day_still_saves_with_unresolved_legs(): void
    {
        $this->stubOsrm(fn () => Http::failedConnection('Could not resolve host'));

        [$user, $itinerary, $day] = $this->trip();
        $first = $this->stop($day, $this->destination(14.30, 121.40), 1);
        $second = $this->destination(14.60, 121.80);
        $this->like($user, $second);

        $this->actingAs($user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $first->id => $this->row($day->id, 1, $first->destination_id, '09:00', '10:00'),
                    '-1' => $this->row($day->id, 2, $second->id, '10:30', '11:30'),
                ],
            ])
            ->assertRedirect(route('itineraries.show', $itinerary));

        $second = $itinerary->fresh()->days->first()->items()->orderBy('sort_order')->get()[1];

        $this->assertSame('unresolved', $second->route_status);
        $this->assertNull($second->route_distance_meters);
        $this->assertNull($second->route_duration_minutes);
        $this->assertNull($second->routeSummary());
    }

    public function test_a_stop_without_a_pin_issues_no_request(): void
    {
        [$user, $itinerary, $day] = $this->trip();
        $first = $this->stop($day, $this->destination(14.30, 121.40), 1);

        $this->actingAs($user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $first->id => $this->row($day->id, 1, $first->destination_id, '09:00', '10:00'),
                    '-1' => [
                        'day_id' => $day->id,
                        'sort_order' => 2,
                        'item_type' => 'custom',
                        'destination_id' => null,
                        'place_id' => null,
                        'custom_name' => 'Pinless',
                        'custom_address' => 'Somewhere',
                        'custom_latitude' => null,
                        'custom_longitude' => null,
                        'start_time' => '10:30',
                        'end_time' => '11:30',
                    ],
                ],
            ])
            ->assertRedirect(route('itineraries.show', $itinerary));

        // Pins are checked before the client is touched: the only pair here
        // has one end without a position, so nothing may be asked.
        Http::assertSentCount(0);

        $second = $itinerary->fresh()->days->first()->items()->orderBy('sort_order')->get()[1];

        $this->assertSame('unresolved', $second->route_status);
    }

    /**
     * GENERATION ROUTES. This is the behaviour the test that used to sit here
     * denied.
     *
     * The old test asserted generation issued zero requests and left every leg
     * `unresolved`, on the reasoning that routing is the wrong latency trade
     * inside a generation request. The trade was real; the consequence was not,
     * and it was the reason a freshly generated trip showed "45 min travel" in
     * the same slot a measured leg uses -- a guessed drive time presented as a
     * fact, which is the panel's complaint reproduced inside the feature built
     * to answer it.
     */
    public function test_generation_resolves_the_road(): void
    {
        $user = User::factory()->create();

        $profile = TravelProfile::create([
            'user_id' => $user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 1,
        ]);

        $tag = Tag::create(['name' => 'Nature', 'slug' => 'nature']);
        $profile->tags()->attach($tag, ['weight' => 3]);

        $ids = [];

        foreach ([[14.30, 121.40], [14.60, 121.80]] as [$lat, $lng]) {
            $destination = $this->destination($lat, $lng);
            $destination->tags()->attach($tag);
            $this->like($user, $destination);
            $ids[] = $destination->id;
        }

        $this->actingAs($user)->post(route('itineraries.store'), [
            'title' => 'Road trip',
            'area' => 'Laguna',
            'destination_ids' => $ids,
        ])->assertRedirect();

        $items = $user->itineraries()->firstOrFail()->days->first()->items;

        $this->assertGreaterThan(1, $items->count());

        // The first stop of a day has no leg INTO it -- there is no previous
        // pin -- so it is unresolved by construction, not by failure.
        $this->assertSame('unresolved', $items->first()->route_status);

        foreach ($items->skip(1) as $item) {
            $this->assertSame('resolved', $item->route_status);
            $this->assertNotNull($item->route_distance_meters);
            $this->assertNotNull($item->route_duration_minutes);
        }

        // And it actually asked the road, rather than writing numbers it had
        // invented.
        Http::assertSentCount($items->count() - 1);
    }

    /**
     * A generation that cannot reach the road still produces a trip.
     *
     * Routing inside generation must not become a new way for the feature to
     * fail. Every failure resolves to null, the leg is written `unresolved`, and
     * the traveller gets their trip -- which is the same promise `RouteService`
     * makes and the reason the read view has an unresolved branch at all.
     */
    public function test_generation_survives_a_dead_routing_provider(): void
    {
        // `stubOsrm()` and not a second `Http::fake()`: the factory honours the FIRST
        // matching stub, so a second fake is ignored and this test would quietly
        // measure the healthy stub instead of a dead road.
        $this->stubOsrm(fn () => Http::response('no road today', 500));

        $user = User::factory()->create();

        $profile = TravelProfile::create([
            'user_id' => $user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 1,
        ]);

        $tag = Tag::create(['name' => 'Nature', 'slug' => 'nature']);
        $profile->tags()->attach($tag, ['weight' => 3]);

        $ids = [];

        foreach ([[14.30, 121.40], [14.60, 121.80]] as [$lat, $lng]) {
            $destination = $this->destination($lat, $lng);
            $destination->tags()->attach($tag);
            $this->like($user, $destination);
            $ids[] = $destination->id;
        }

        $this->actingAs($user)->post(route('itineraries.store'), [
            'title' => 'Road trip',
            'area' => 'Laguna',
            'destination_ids' => $ids,
        ])->assertRedirect();

        $items = $user->itineraries()->firstOrFail()->days->first()->items;

        $this->assertGreaterThan(1, $items->count());

        foreach ($items as $item) {
            $this->assertSame('unresolved', $item->route_status);
            $this->assertNull($item->route_distance_meters);
        }
    }

    /**
     * Generation asks the road a bounded number of times.
     *
     * A leg costs an HTTP call until the 30-day cache answers, measured at
     * roughly 300-500 ms. A 30-day trip is 87 legs, which overruns PHP's
     * execution limit on a cold cache -- and a generation that dies half way
     * leaves the traveller with nothing at all, which is a far worse outcome
     * than a trip whose last few legs are honestly unresolved.
     *
     * So the tail is left unresolved rather than attempted, and the editor
     * routes the whole day the moment the traveller opens and saves it.
     */
    public function test_generation_bounds_how_many_legs_it_asks_the_road_about(): void
    {
        $user = User::factory()->create();

        $profile = TravelProfile::create([
            'user_id' => $user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 13,
        ]);

        $tag = Tag::create(['name' => 'Nature', 'slug' => 'nature']);
        $profile->tags()->attach($tag, ['weight' => 3]);

        $ids = [];

        // Thirteen days at three stops a day is 39 stops and 26 legs, so the
        // budget bites part way through and the tail is left alone.
        foreach (range(1, 40) as $index) {
            $destination = $this->destination(14.0 + ($index / 100), 121.0 + ($index / 100));
            $destination->tags()->attach($tag);
            $this->like($user, $destination);
            $ids[] = $destination->id;
        }

        $this->actingAs($user)->post(route('itineraries.store'), [
            'title' => 'Long haul',
            'area' => 'Laguna',
            'days' => 13,
            'destination_ids' => $ids,
        ])->assertRedirect();

        $items = $user->itineraries()->firstOrFail()
            ->load('days.items')
            ->days
            ->flatMap(fn ($day) => $day->items);

        Http::assertSentCount(24);

        $this->assertGreaterThan(24, $items->where('item_type', 'attraction')->count(),
            'the fixture did not produce enough legs to reach the budget');

        // Each attempted leg marks the stop it arrives at, so exactly the budget
        // resolved and every leg past it is unresolved rather than guessed.
        $this->assertSame(24, $items->where('route_status', 'resolved')->count());
        $this->assertGreaterThan(0, $items->where('route_status', 'unresolved')->count());
    }

    public function test_reordering_a_day_recomputes_its_legs(): void
    {
        [$user, $itinerary, $day] = $this->trip();
        $first = $this->stop($day, $this->destination(14.30, 121.40), 1, '11:00', '12:00');
        $second = $this->stop($day, $this->destination(14.60, 121.80), 2, '08:00', '09:00');

        // Swap the two stops and save: the legs must describe the new order,
        // not the order the day was generated in.
        $this->actingAs($user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $first->id => $this->row($day->id, 2, $first->destination_id, '11:00', '12:00'),
                    $second->id => $this->row($day->id, 1, $second->destination_id, '08:00', '09:00'),
                ],
            ])
            ->assertRedirect(route('itineraries.show', $itinerary));

        $items = $itinerary->fresh()->days->first()->items()->orderBy('sort_order')->get();

        // The 14.60 pin now leads: no leg into the first stop, a resolved leg
        // into the second.
        $this->assertSame('unresolved', $items[0]->route_status);
        $this->assertSame('resolved', $items[1]->route_status);
        $this->assertSame(10, $items[1]->route_duration_minutes);
    }

    public function test_the_read_view_prefers_the_road_over_the_gap(): void
    {
        [$user, $itinerary, $day] = $this->trip();
        $first = $this->stop($day, $this->destination(14.30, 121.40), 1);
        $second = $this->destination(14.60, 121.80);
        $this->like($user, $second);

        $this->actingAs($user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $first->id => $this->row($day->id, 1, $first->destination_id, '09:00', '10:00'),
                    '-1' => $this->row($day->id, 2, $second->id, '10:30', '11:30'),
                ],
            ])
            ->assertRedirect(route('itineraries.show', $itinerary));

        $this->actingAs($user)
            ->get(route('itineraries.show', $itinerary))
            ->assertOk()
            ->assertSee('8.4 km · 10 min', false);
    }

    /**
     * An UNMEASURED leg says so, on the page and in the PDF.
     *
     * Both used to print the bare gap — "45 min travel" — in the same slot a
     * measured leg prints "9.9 km · 28 min by road". Two numbers, one kind of
     * typography, one of them a guess. That is precisely why a flat reflow and a
     * measured one were indistinguishable on screen, which is the defect this
     * whole change is about rather than a cosmetic addition to it.
     *
     * The gap is still printed: it is real information, it is the space this
     * plan leaves between two stops. What it is not is a drive we measured.
     */
    public function test_an_unmeasured_leg_is_labelled_on_the_page_and_in_the_pdf(): void
    {
        [$user, $itinerary, $day] = $this->trip();
        $first = $this->stop($day, $this->destination(14.30, 121.40), 1);

        // Both stops have pins and the provider still cannot answer, which is
        // the ordinary way a leg ends up unmeasured. `stubOsrm()` rather than a
        // second `Http::fake()`, which the factory ignores.
        $this->stubOsrm(fn () => Http::response('no road today', 500));

        $second = $this->destination(14.60, 121.80);
        $this->like($user, $second);

        $this->actingAs($user)
            ->patch(route('itineraries.update', $itinerary), [
                'items' => [
                    $first->id => $this->row($day->id, 1, $first->destination_id, '09:00', '10:00'),
                    '-1' => $this->row($day->id, 2, $second->id, '10:30', '11:30'),
                ],
            ])
            ->assertRedirect(route('itineraries.show', $itinerary));

        $this->actingAs($user)
            ->get(route('itineraries.show', $itinerary))
            ->assertOk()
            ->assertSee('drive not measured')
            // The old wording, which asserted a drive nobody measured.
            ->assertDontSee('min travel');

        $pdf = $this->actingAs($user)
            ->get(route('itineraries.download', $itinerary))
            ->assertOk()
            ->getContent();

        $text = mb_convert_encoding(
            (string) $pdf,
            'Windows-1252',
            'UTF-8'
        );

        $this->assertStringContainsString('drive not measured', $text);
        $this->assertStringNotContainsString('min travel', $text);
    }

    // -------------------------------------------------------------- helpers

    /**
     * @return array{0: User, 1: Itinerary, 2: \App\Models\ItineraryDay}
     */
    private function trip(): array
    {
        $user = User::factory()->create();

        $itinerary = Itinerary::create([
            'user_id' => $user->id,
            'title' => 'Road trip',
            'area' => 'Laguna',
            'budget_level' => 'economy',
            'trip_duration_days' => 1,
        ]);

        $day = $itinerary->days()->create(['day_number' => 1]);

        return [$user, $itinerary, $day];
    }

    private function destination(float $lat, float $lng): Destination
    {
        return Destination::create([
            'name' => 'Road Stop '.$lat.' '.$lng,
            'slug' => 'road-stop-'.$lat.'-'.$lng.'-'.uniqid(),
            'description' => 'A place.',
            'province' => 'Laguna',
            'latitude' => $lat,
            'longitude' => $lng,
            'budget_level' => 'economy',
            'estimated_cost' => 100,
            'recommended_minutes' => 60,
        ]);
    }

    private function like(User $user, Destination $destination): void
    {
        DestinationSwipe::create([
            'user_id' => $user->id,
            'destination_id' => $destination->id,
            'action' => 'liked',
        ]);
    }

    private function stop(
        $day,
        Destination $destination,
        int $sort,
        ?string $start = null,
        ?string $end = null
    ): ItineraryItem {
        return ItineraryItem::create([
            'itinerary_day_id' => $day->id,
            'destination_id' => $destination->id,
            'sort_order' => $sort,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    /**
     * One draft row the way the editor posts it: underscores in the names,
     * exactly like the form sends.
     */
    private function row(
        int $dayId,
        int $sort,
        int $destinationId,
        ?string $start = null,
        ?string $end = null
    ): array {
        return [
            'day_id' => $dayId,
            'sort_order' => $sort,
            'item_type' => 'attraction',
            'destination_id' => $destinationId,
            'place_id' => null,
            'custom_name' => null,
            'custom_address' => null,
            'custom_latitude' => null,
            'custom_longitude' => null,
            'start_time' => $start,
            'end_time' => $end,
        ];
    }
}
