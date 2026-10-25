<?php

namespace Tests\Feature;

use App\Models\Itinerary;
use App\Models\Place;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HOT-ADD: the suggestion panel's Add button, at the HTTP boundary.
 *
 * The itinerary editor's own dialog works on a full draft payload; a panel
 * pick is a ONE-ROW write, so it posts here and lands in the editor's same
 * acceptance rules through `ItineraryEditor::addPlaceTo()` — owner, day
 * ownership, duplicates, the trip's province. A stranger is refused before
 * any of it runs.
 */
class ItineraryPlaceAddTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Itinerary $trip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();

        $this->trip = Itinerary::create([
            'user_id' => $this->owner->id,
            'title' => 'A Baguio weekend',
            'area' => 'Benguet',
            'budget_level' => 'economy',
            'trip_duration_days' => 2,
        ]);

        foreach ([1, 2] as $dayNumber) {
            $this->trip->days()->create(['day_number' => $dayNumber]);
        }

        $this->trip = $this->trip->fresh();
        $this->trip->load('days');
    }

    public function test_the_owner_hot_adds_a_place_to_a_day(): void
    {
        $place = $this->place();

        $this->actingAs($this->owner)
            ->post(route('itineraries.places.add', [$this->trip, $place]), [
                'day_id' => $this->trip->days->first()->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $item = $this->trip->days()->first()->items()->first();

        $this->assertNotNull($item, 'the place stop must exist after the add');
        $this->assertSame($place->id, $item->place_id);
        $this->assertSame('meal', $item->item_type);

        // A place added hot gets a real slot, not 00:00 — the same nextSlot()
        // the dialog path uses.
        $this->assertNotNull($item->start_time);
        $this->assertNotSame('00:00', substr((string) $item->start_time, 0, 5));
    }

    public function test_a_lodging_place_arrives_as_lodging(): void
    {
        $hotel = $this->place(['type' => Place::TYPE_LODGING]);

        $this->actingAs($this->owner)
            ->post(route('itineraries.places.add', [$this->trip, $hotel]), [
                'day_id' => $this->trip->days->first()->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(
            'lodging',
            $this->trip->days()->first()->items()->first()->item_type
        );
    }

    public function test_a_stranger_cannot_add_to_someone_elses_trip(): void
    {
        $place = $this->place();

        $this->actingAs(User::factory()->create())
            ->post(route('itineraries.places.add', [$this->trip, $place]), [
                'day_id' => $this->trip->days->first()->id,
            ])
            ->assertForbidden();

        $this->assertSame(0, $this->trip->days()->first()->items()->count());
    }

    public function test_a_day_from_another_trip_is_refused(): void
    {
        $place = $this->place();

        $other = Itinerary::create([
            'user_id' => $this->owner->id,
            'title' => 'Another trip',
            'area' => 'Benguet',
            'budget_level' => 'economy',
            'trip_duration_days' => 1,
        ]);

        $foreignDay = $other->days()->create(['day_number' => 1]);

        $this->actingAs($this->owner)
            ->post(route('itineraries.places.add', [$this->trip, $place]), [
                'day_id' => $foreignDay->id,
            ])
            ->assertNotFound();

        $this->assertSame(0, $this->trip->days()->first()->items()->count());
    }

    public function test_a_duplicate_place_is_refused_with_the_reason(): void
    {
        $place = $this->place();

        $this->actingAs($this->owner)
            ->post(route('itineraries.places.add', [$this->trip, $place]), [
                'day_id' => $this->trip->days->first()->id,
            ])
            ->assertRedirect();

        $this->actingAs($this->owner)
            ->post(route('itineraries.places.add', [$this->trip, $place]), [
                'day_id' => $this->trip->days->first()->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('day_id');

        $this->assertSame(
            1,
            $this->trip->days()->first()->items()->where('place_id', $place->id)->count()
        );
    }

    public function test_a_place_outside_the_trips_province_is_refused(): void
    {
        $far = $this->place(['province' => 'Ilocos Norte']);

        $this->actingAs($this->owner)
            ->post(route('itineraries.places.add', [$this->trip, $far]), [
                'day_id' => $this->trip->days->first()->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('day_id');

        $this->assertSame(0, $this->trip->days()->first()->items()->count());
    }

    // -------------------------------------------------------------- helpers

    private function place(array $overrides = []): Place
    {
        static $sequence = 0;

        $sequence++;

        return Place::create(array_merge([
            'name' => 'Hot Add Place '.$sequence,
            'slug' => 'hot-add-place-'.$sequence,
            'type' => Place::TYPE_FOOD,
            'province' => 'Benguet',
            'municipality' => 'Baguio City',
            'latitude' => 16.4120,
            'longitude' => 120.5960,
            'budget_level' => 'economy',
            'source_url' => 'https://example.test/hot-add-'.$sequence,
        ], $overrides));
    }
}
