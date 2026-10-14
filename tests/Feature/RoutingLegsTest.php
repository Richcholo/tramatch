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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_a_cached_leg_issues_no_second_request(): void
    {
        $service = app(\App\Services\Routing\RouteService::class);

        $service->leg(14.3, 121.4, 14.6, 121.8);
        $service->leg(14.3, 121.4, 14.6, 121.8);

        Http::assertSentCount(1);
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

    public function test_generation_leaves_legs_unresolved(): void
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

        // Generation is the fast path: it lays out with the flat gap and never
        // rings the road, so a three-day trip does not cost eighteen HTTP
        // calls. Legs resolve on the first edit or reflow instead.
        $this->actingAs($user)->post(route('itineraries.store'), [
            'title' => 'Road trip',
            'area' => 'Laguna',
            'destination_ids' => $ids,
        ])->assertRedirect();

        $items = $user->itineraries()->firstOrFail()->days->first()->items;

        $this->assertGreaterThan(0, $items->count());

        foreach ($items as $item) {
            $this->assertSame('unresolved', $item->route_status);
        }

        Http::assertSentCount(0);
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
