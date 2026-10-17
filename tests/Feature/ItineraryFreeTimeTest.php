<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use App\Services\ItinerarySchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A day tells the traveller what is left of it, in a sentence.
 *
 * The plan called for this as "free time is a sentence, not a chart", and the
 * rejected alternative is the interesting part: the obvious build is a
 * utilisation bar showing how much of the 08:00-18:00 window the plan fills.
 *
 * A bar is wrong here for a reason worth keeping. It asks the traveller to decode
 * a fraction before they know whether the number is good, and — worse — a
 * *full-looking-empty* bar on a well-packed day reads as a fault when it is
 * exactly the outcome the traveller wanted. "That leaves 2 h 20 min free." says
 * the same thing and needs nothing decoded.
 */
class ItineraryFreeTimeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $profile = TravelProfile::create([
            'user_id' => $this->user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 1,
        ]);

        $tag = Tag::create(['name' => 'Nature', 'slug' => 'nature']);
        $profile->tags()->attach($tag, ['weight' => 3]);
    }

    public function test_a_day_that_finishes_early_says_how_much_is_left(): void
    {
        $day = $this->dayEndingAt('15:40');

        // 18:00 - 15:40 = 2h 20m.
        $this->assertSame(140, $day->freeMinutes());
        $this->assertSame('That leaves 2 h 20 min free.', $day->freeTimeSentence());
    }

    /**
     * A FULL DAY SAYS NOTHING.
     *
     * Not "0 min free". A day packed to the window has not failed to have free
     * time — the traveller got what they asked for — and printing a zero reads
     * as a shortfall.
     */
    public function test_a_full_day_claims_no_free_time_at_all(): void
    {
        $day = $this->dayEndingAt('18:00');

        $this->assertSame(0, $day->freeMinutes());
        $this->assertNull($day->freeTimeSentence());
    }

    /**
     * AN OVERRUNNING DAY IS OWED NOTHING, AND NEVER A NEGATIVE NUMBER.
     *
     * Reflow deliberately does not refuse a day that runs past 18:00 — the
     * traveller typed those durations themselves. So the arithmetic is
     * `DAY_END - last end`, floored at zero, and never "budget minus the plan",
     * which is where a negative would come from.
     */
    public function test_a_day_that_overruns_reports_no_free_time_and_never_a_negative_one(): void
    {
        $day = $this->dayEndingAt('19:30');

        $this->assertSame(0, $day->freeMinutes());
        $this->assertNull($day->freeTimeSentence());
    }

    /**
     * AN EMPTY DAY IS NOT A DAY WITH NO FREE TIME.
     *
     * Null rather than zero: "That leaves 10 h free." under a day with nothing
     * in it is a sentence about a day nobody planned, and the empty state
     * already says the true thing.
     */
    public function test_a_day_with_nothing_in_it_has_no_free_time_to_report(): void
    {
        $day = ItineraryDay::create([
            'itinerary_id' => $this->itinerary()->id,
            'day_number' => 1,
        ]);

        $this->assertNull($day->freeMinutes());
        $this->assertNull($day->freeTimeSentence());
    }

    public function test_stops_with_no_times_yet_cannot_be_measured(): void
    {
        $day = ItineraryDay::create([
            'itinerary_id' => $this->itinerary()->id,
            'day_number' => 1,
        ]);

        $day->items()->create([
            'item_type' => 'custom',
            'custom_name' => 'The hotel you already booked',
            'sort_order' => 1,
        ]);

        $day->refresh();

        $this->assertNull($day->freeMinutes());
        $this->assertNull($day->freeTimeSentence());
    }

    /**
     * The page prints the sentence, and only when there is one.
     */
    public function test_the_read_view_states_the_free_time(): void
    {
        $itinerary = $this->itinerary();
        $day = $itinerary->days()->create(['day_number' => 1]);
        $this->stopEndingAt($day, '15:40');

        $this->actingAs($this->user)
            ->get(route('itineraries.show', $itinerary))
            ->assertOk()
            ->assertSee('That leaves 2 h 20 min free.')
            ->assertSee('free', false);
    }

    public function test_the_read_view_says_nothing_about_a_full_day(): void
    {
        $itinerary = $this->itinerary();
        $day = $itinerary->days()->create(['day_number' => 1]);
        $this->stopEndingAt($day, '18:00');

        $this->actingAs($this->user)
            ->get(route('itineraries.show', $itinerary))
            ->assertOk()
            ->assertDontSee('free.', false);
    }

    // ---------------------------------------------------------- helpers

    private function itinerary()
    {
        return $this->user->itineraries()->create([
            'title' => 'A trip',
            'area' => 'Benguet',
            'budget_level' => 'economy',
            'trip_duration_days' => 1,
        ]);
    }

    private function stopEndingAt(ItineraryDay $day, string $end): ItineraryItem
    {
        $destination = Destination::create([
            'name' => 'Somewhere '.uniqid(),
            'slug' => 'somewhere-'.uniqid(),
            'description' => 'A place.',
            'province' => 'Benguet',
            'latitude' => 16.4,
            'longitude' => 120.6,
            'budget_level' => 'economy',
            'estimated_cost' => 100,
            'recommended_minutes' => 60,
        ]);

        return $day->items()->create([
            'item_type' => 'attraction',
            'destination_id' => $destination->id,
            'sort_order' => 1,
            'start_time' => ItinerarySchedule::format(
                ItinerarySchedule::parse($end) - 60
            ),
            'end_time' => $end,
        ]);
    }

    private function dayEndingAt(string $end): ItineraryDay
    {
        $day = ItineraryDay::create([
            'itinerary_id' => $this->itinerary()->id,
            'day_number' => 1,
        ]);

        $this->stopEndingAt($day, $end);

        return $day->fresh();
    }
}