<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\DestinationSwipe;

class ItineraryGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Destination $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Trip duration 1 is the default the profile supplies. Every day-count
        // test below either overrides it or asserts it still wins, so the
        // profile and the form field cannot be confused for one another.
        $profile = TravelProfile::create([
            'user_id' => $this->user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 1,
        ]);

        $tag = Tag::create(['name' => 'Nature', 'slug' => 'nature']);
        $this->destination = Destination::create([
            'name' => 'Nature Park',
            'slug' => 'nature-park',
            'description' => 'A nature park.',
            'province' => 'Laguna',
            'latitude' => 14.3,
            'longitude' => 121.4,
            'budget_level' => 'economy',
            'estimated_cost' => 800,
            'recommended_minutes' => 120,
        ]);

        $this->destination->tags()->attach($tag);
        $profile->tags()->attach($tag, ['weight' => 3]);

        DestinationSwipe::create([
            'user_id' => $this->user->id,
            'destination_id' => $this->destination->id,
            'action' => 'liked',
        ]);
    }

    public function test_authenticated_user_can_generate_an_itinerary(): void
    {
        $response = $this->actingAs($this->user)->post(route('itineraries.store'), [
            'title' => 'Weekend nature trip',
            'area' => 'Laguna',
            'start_date' => now()->addDay()->toDateString(),
            'destination_ids' => [$this->destination->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('itineraries', ['user_id' => $this->user->id, 'title' => 'Weekend nature trip']);
        $this->assertDatabaseHas('itinerary_items', ['destination_id' => $this->destination->id]);
    }

    public function test_the_days_on_the_form_lengthen_the_trip_past_the_saved_preference(): void
    {
        $itinerary = $this->generate(days: 3);

        $this->assertSame(3, $itinerary->trip_duration_days);
        $this->assertSame(
            [1, 2, 3],
            $itinerary->days->pluck('day_number')->map(fn ($n) => (int) $n)->all()
        );
    }

    public function test_days_beyond_the_stop_count_are_left_open_rather_than_dropped(): void
    {
        $itinerary = $this->generate(days: 4);

        $this->assertCount(4, $itinerary->days);

        // The single stop stays on day 1 and the three days past it are real
        // rows, not rows the generator decided to skip.
        $this->assertSame(
            [1, 0, 0, 0],
            $itinerary->days->map(fn ($day) => $day->items->count())->all()
        );
    }

    public function test_the_saved_preference_still_sets_the_length_when_the_field_is_blank(): void
    {
        $this->user->travelProfile->update(['trip_duration_days' => 2]);

        $itinerary = $this->generate(days: '');

        $this->assertSame(2, $itinerary->trip_duration_days);
    }

    public function test_the_saved_preference_still_sets_the_length_when_no_field_is_posted(): void
    {
        $this->user->travelProfile->update(['trip_duration_days' => 2]);

        $itinerary = $this->generate();

        $this->assertSame(2, $itinerary->trip_duration_days);
    }

    public function test_a_trip_longer_than_the_ceiling_is_refused_rather_than_silently_shortened(): void
    {
        $this->actingAs($this->user)
            ->post(route('itineraries.store'), [
                'title' => 'Impossible trip',
                'area' => 'Laguna',
                'days' => Itinerary::MAX_DAYS + 1,
                'destination_ids' => [$this->destination->id],
            ])
            ->assertSessionHasErrors('days');

        $this->assertSame(0, Itinerary::count());
    }

    public function test_the_generate_page_offers_the_day_count_the_profile_would_have_used(): void
    {
        $this->user->travelProfile->update(['trip_duration_days' => 5]);

        $response = $this->actingAs($this->user)
            ->get(route('itineraries.create', ['area' => 'Laguna']))
            ->assertOk();

        // The default has to be the saved preference, or the field would quietly
        // shorten every trip the traveller generates.
        $response->assertSee('name="days"', false);
        $response->assertSee('value="5"', false);
    }

    private function generate(int|string|null $days = null): Itinerary
    {
        $payload = [
            'title' => 'Long weekend',
            'area' => 'Laguna',
            'destination_ids' => [$this->destination->id],
        ];

        if ($days !== null) {
            $payload['days'] = $days;
        }

        $this->actingAs($this->user)
            ->post(route('itineraries.store'), $payload)
            ->assertRedirect();

        return $this->user->itineraries()->firstOrFail()->load('days.items');
    }
}