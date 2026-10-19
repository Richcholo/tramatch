<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A destination page can put the place on a trip.
 *
 * The plan calls this "closing the discovery → planning loop", and before it the
 * page referenced no itinerary at all: someone who found Burnham Park by browsing
 * had to go and find their trip again to do anything with it. The `/discover`
 * deck had a route in, and nothing had a route out.
 *
 * Two decisions carry the feature, and both are about not inventing a second
 * version of something.
 *
 * **THE PLACEMENT IS THE EDITOR'S, NOT THIS FEATURE'S.** `addDestinationTo()`
 * rebuilds the draft from the stored rows and appends the new stop under a
 * negative key, exactly as the browser's add dialog does, then calls `apply()`.
 * So the liked rule, the duplicate rule, the real start time, the written leg and
 * the refreshed total are all the one implementation — and the rules cannot drift
 * apart, because there is only one of them.
 *
 * **ADDING A PLACE IS A LIKE.** The editor refuses any destination the traveller
 * has not liked; that rule is what ties it to the deck. Someone who arrived here
 * from `/destinations` rather than from a swipe has liked nothing, so requiring a
 * like first would block the entry point this exists to add. Recording the
 * interest makes both surfaces tell one story.
 */
class DestinationAddToTripTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Destination $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        TravelProfile::create([
            'user_id' => $this->user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 2,
        ]);

        Tag::create(['name' => 'Nature', 'slug' => 'nature']);

        $this->destination = Destination::create([
            'name' => 'Burnham Park',
            'slug' => 'burnham-park',
            'description' => 'A park.',
            'province' => 'Benguet',
            'municipality' => 'Baguio',
            'latitude' => 16.3540,
            'longitude' => 120.5860,
            'budget_level' => 'economy',
            'estimated_cost' => 100,
            'recommended_minutes' => 90,
            'is_featured' => true,
            'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------ the happy path

    public function test_the_place_lands_on_the_chosen_day_of_the_chosen_trip(): void
    {
        $trip = $this->trip('Benguet trip', 'Benguet');
        $day = $trip->days()->first();

        $this->actingAs($this->user)
            ->from(route('destinations.show', $this->destination))
            ->post(route('destinations.add-to-trip', $this->destination), [
                'itinerary_id' => $trip->id,
                'day_id' => $day->id,
            ])
            ->assertRedirect(route('itineraries.show', $trip))
            ->assertSessionHasNoErrors();

        $items = $trip->fresh()->load('days.items')->days->first()->items;

        $this->assertCount(1, $items);
        $this->assertSame('Burnham Park', $items->first()->destination->name);
        $this->assertSame('attraction', $items->first()->item_type);
    }

    /**
     * It gets a REAL TIME, not 00:00.
     *
     * This is the editor's own rule for a stop that arrives with no usable
     * window, and it is the reason the feature delegates: a stop placed by hand
     * at the start of the day window is a different-looking bug from one placed at
     * 00:00, and only one of them is obvious.
     */
    public function test_the_added_stop_gets_a_real_slot_not_midnight(): void
    {
        $trip = $this->trip('Benguet trip', 'Benguet');
        $day = $trip->days()->first();

        $this->add($trip, $day);

        $item = $trip->fresh()->load('days.items')->days->first()->items->first();

        $this->assertNotNull($item->start_time, 'the stop was saved with no time at all');
        $this->assertSame('08:00', substr((string) $item->start_time, 0, 5));
        $this->assertSame('09:30', substr((string) $item->end_time, 0, 5));
    }

    /**
     * THE LEG IS WRITTEN, BECAUSE `apply()` IS WHAT WRITES THEM.
     *
     * The routing work made generation resolve every leg. A stop added here that
     * arrived with `route_status = unresolved` and a NULL distance would put a
     * hole in the map and print "drive not measured" beside a drive we could
     * have measured.
     *
     * TWO stops, because **the first stop of a day has no leg into it at all** —
     * the traveller woke up there, there is no previous pin. That is by design
     * and `test_a_days_first_stop_is_never_an_estimated_gap` pins it. A lone stop
     * with an unresolved leg is correct, so the assertion that means anything is
     * about the SECOND one.
     */
    public function test_the_added_stop_gets_its_leg_measured(): void
    {
        $trip = $this->trip('Benguet trip', 'Benguet');
        $day = $trip->days()->first();

        $this->add($trip, $day);

        $second = Destination::create([
            'name' => 'Session Road',
            'slug' => 'session-road',
            'description' => 'A street.',
            'province' => 'Benguet',
            'municipality' => 'Baguio',
            'latitude' => 16.4090,
            'longitude' => 120.5940,
            'budget_level' => 'economy',
            'estimated_cost' => 100,
            'recommended_minutes' => 90,
            'is_featured' => true,
            'is_active' => true,
        ]);

        $this->addTo($trip, $day, $second);

        $items = $trip->fresh()->load('days.items')->days->first()->items;

        $this->assertCount(2, $items);
        $this->assertSame(
            'resolved',
            $items->last()->route_status,
            'the stop added second arrived with an unresolved leg'
        );
        $this->assertNotNull($items->last()->route_distance_meters);
    }

    /**
     * ADDING A PLACE IS A LIKE, RECORDED BEFORE THE PLACEMENT.
     *
     * Recorded afterwards the editor's own rule would refuse it, because the rule
     * is "liked while swiping" and this traveller has never swiped. So the order
     * is part of the feature, not an implementation detail.
     */
    public function test_adding_a_place_records_that_it_was_liked(): void
    {
        $this->assertDatabaseMissing('destination_swipes', [
            'user_id' => $this->user->id,
            'destination_id' => $this->destination->id,
        ]);

        $trip = $this->trip('Benguet trip', 'Benguet');

        $this->add($trip, $trip->days()->first());

        $this->assertDatabaseHas('destination_swipes', [
            'user_id' => $this->user->id,
            'destination_id' => $this->destination->id,
            'action' => 'liked',
        ]);
    }

    /**
     * A SKIP IS UPGRADED, NEVER LEFT STANDING NEXT TO A PLANNED VISIT.
     *
     * Someone who passed this card and later decided to plan it has changed their
     * mind. Leaving `passed` on the row would make the deck disagree with the
     * trip about the same place.
     */
    public function test_choosing_to_plan_a_place_upgrades_an_earlier_skip(): void
    {
        DestinationSwipe::create([
            'user_id' => $this->user->id,
            'destination_id' => $this->destination->id,
            'action' => 'passed',
        ]);

        $trip = $this->trip('Benguet trip', 'Benguet');

        $this->add($trip, $trip->days()->first());

        $this->assertSame(1, DestinationSwipe::query()
            ->where('user_id', $this->user->id)
            ->where('destination_id', $this->destination->id)
            ->where('action', 'liked')
            ->count(), 'the skip is still on the row beside a planned visit');

        $this->assertSame(0, DestinationSwipe::query()
            ->where('user_id', $this->user->id)
            ->where('destination_id', $this->destination->id)
            ->where('action', 'passed')
            ->count());
    }

    public function test_adding_the_same_place_twice_does_not_duplicate_it(): void
    {
        $trip = $this->trip('Benguet trip', 'Benguet');
        $day = $trip->days()->first();

        $this->add($trip, $day);

        // A second trip, so the "already on this trip" rule is what refuses.
        $this->actingAs($this->user)
            ->from(route('destinations.show', $this->destination))
            ->post(route('destinations.add-to-trip', $this->destination), [
                'itinerary_id' => $trip->id,
                'day_id' => $day->id,
            ])
            ->assertSessionHasErrors('itinerary_id');

        $this->assertCount(1, $trip->fresh()->load('days.items')->days->first()->items);
    }

    // ------------------------------------------------------------- what is refused

    public function test_a_trip_in_another_province_is_refused(): void
    {
        $trip = $this->trip('Paoay trip', 'Ilocos Norte');

        $this->actingAs($this->user)
            ->from(route('destinations.show', $this->destination))
            ->post(route('destinations.add-to-trip', $this->destination), [
                'itinerary_id' => $trip->id,
                'day_id' => $trip->days()->first()->id,
            ])
            ->assertSessionHasErrors('itinerary_id');

        $this->assertDatabaseCount('itinerary_items', 0);
    }

    /**
     * NOT YOURSELF IS NOT YOUR TRIP.
     *
     * The trip is looked up through the signed-in user's own itineraries, so a
     * hand-posted id belonging to somebody else 404s rather than quietly
     * landing the stop on their trip.
     */
    public function test_someone_elses_trip_cannot_be_written_to(): void
    {
        $stranger = User::factory()->create();

        $theirTrip = Itinerary::create([
            'user_id' => $stranger->id,
            'title' => 'Not yours',
            'area' => 'Benguet',
            'budget_level' => 'economy',
            'trip_duration_days' => 1,
        ]);

        $theirDay = ItineraryDay::create([
            'itinerary_id' => $theirTrip->id,
            'day_number' => 1,
        ]);

        $this->actingAs($this->user)
            ->post(route('destinations.add-to-trip', $this->destination), [
                'itinerary_id' => $theirTrip->id,
                'day_id' => $theirDay->id,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('itinerary_items', 0);
    }

    /**
     * A DAY BELONGING TO ANOTHER TRIP IS NOT YOURS TO USE.
     *
     * Route-model binding resolves an `ItineraryDay` from the whole table, and
     * nothing about a posted day id is specific to the URL's trip. This is the
     * same ownership boundary `UpdateItineraryRequest::after()` draws for the
     * editor, and the same reason.
     */
    public function test_a_day_from_a_different_trip_is_refused(): void
    {
        $mine = $this->trip('Benguet trip', 'Benguet');
        $theirs = $this->trip('Another trip', 'Benguet');

        $this->actingAs($this->user)
            ->from(route('destinations.show', $this->destination))
            ->post(route('destinations.add-to-trip', $this->destination), [
                'itinerary_id' => $mine->id,
                'day_id' => $theirs->days()->first()->id,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('itinerary_items', 0);
    }

    public function test_a_guest_cannot_add_the_place_to_anything(): void
    {
        $this->post(route('destinations.add-to-trip', $this->destination), [
            'itinerary_id' => 1,
            'day_id' => 1,
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('itinerary_items', 0);
    }

    public function test_an_archived_place_is_not_addable(): void
    {
        $this->destination->update(['is_active' => false]);

        $trip = $this->trip('Benguet trip', 'Benguet');

        $this->actingAs($this->user)
            ->post(route('destinations.add-to-trip', $this->destination), [
                'itinerary_id' => $trip->id,
                'day_id' => $trip->days()->first()->id,
            ])
            ->assertNotFound();
    }

    // --------------------------------------------------------------- what is shown

    /**
     * THE PICKER OFFERS EXACTLY THE TRIPS THAT WOULD WORK.
     *
     * Offering a Paoay trip here and refusing it on submit is a UI that invites
     * the mistake it then prevents. Same rule, so the refusal still exists for a
     * hand-posted form — a UI is not a security boundary.
     */
    public function test_the_page_offers_only_trips_the_place_can_go_on(): void
    {
        $benguet = $this->trip('Benguet trip', 'Benguet');
        $this->trip('Paoay trip', 'Ilocos Norte');

        $html = (string) $this->actingAs($this->user)
            ->get(route('destinations.show', $this->destination))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Benguet trip', $html);
        $this->assertStringNotContainsString('Paoay trip', $html);
        $this->assertStringContainsString('Benguet', $html);
    }

    public function test_a_trip_already_visiting_the_place_is_not_offered(): void
    {
        $trip = $this->trip('Benguet trip', 'Benguet');

        $this->add($trip, $trip->days()->first());

        $html = (string) $this->actingAs($this->user)
            ->get(route('destinations.show', $this->destination))
            ->assertOk()
            ->getContent();

        // NOT on the title. The layout renders `session('status')`, so the trip's
        // name is on the page from the confirmation of the add above — which is
        // the flash banner, not the picker. What must be absent is the form.
        $this->assertStringNotContainsString('name="itinerary_id"', $html);
        $this->assertStringContainsString('no trip in Benguet yet', $html);
    }

    /**
     * A GUEST IS TOLD WHY, NOT SHOWN AN EMPTY FORM.
     *
     * The alternative is a form that silently does nothing on submit, which is
     * the shape of every dead control this project has removed.
     */
    public function test_a_guest_is_offered_a_way_in_rather_than_a_dead_form(): void
    {
        $this->trip('Benguet trip', 'Benguet');

        $html = (string) $this->get(route('destinations.show', $this->destination))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Sign in to put', $html);
        $this->assertStringNotContainsString('Add to trip', $html);
    }

    // ---------------------------------------------------------- helpers

    private function trip(string $title, string $area): Itinerary
    {
        $trip = $this->user->itineraries()->create([
            'title' => $title,
            'area' => $area,
            'budget_level' => 'economy',
            'trip_duration_days' => 2,
        ]);

        foreach (range(1, 2) as $number) {
            $trip->days()->create(['day_number' => $number]);
        }

        return $trip->fresh();
    }

    private function add(Itinerary $trip, ItineraryDay $day): void
    {
        $this->addTo($trip, $day, $this->destination);
    }

    private function addTo(Itinerary $trip, ItineraryDay $day, Destination $destination): void
    {
        $this->actingAs($this->user)
            ->from(route('destinations.show', $destination))
            ->post(route('destinations.add-to-trip', $destination), [
                'itinerary_id' => $trip->id,
                'day_id' => $day->id,
            ])
            ->assertRedirect(route('itineraries.show', $trip));
    }
}