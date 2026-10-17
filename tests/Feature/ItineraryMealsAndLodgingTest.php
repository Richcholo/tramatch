<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\Place;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use App\Services\ItinerarySchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A generated trip carries food and lodging, which is the panel's own complaint
 * in one sentence: a traveller asked where they will eat and where they will
 * stay, and the answer was "nowhere, add it yourself".
 *
 * Both rows were achievable long before this — the place catalogue, the four
 * stop shapes and the lunch block all existed. What did not exist was anything
 * that used them: generation emitted attractions and nothing else, so the
 * 12:00–13:00 block the scheduler had been reserving, and deliberately pushing
 * visits out of, sat empty on every day of every trip.
 *
 * The rules that matter are the ones about ABSENCE. Most provinces have no
 * researched places at all, and a trip there must still generate, must not be
 * reported as broken, and must not acquire a fabricated row to hide the gap.
 */
class ItineraryMealsAndLodgingTest extends TestCase
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
            'trip_duration_days' => 2,
        ]);

        $tag = Tag::create(['name' => 'Nature', 'slug' => 'nature']);
        $profile->tags()->attach($tag, ['weight' => 3]);

        // Six attractions, so a two-day trip has three stops a day and a real
        // gap in the middle for the lunch block to sit in.
        foreach (range(1, 6) as $index) {
            $destination = Destination::create([
                'name' => "Attraction {$index}",
                'slug' => "attraction-{$index}",
                'description' => 'A place.',
                'province' => 'Benguet',
                'latitude' => 16.4,
                'longitude' => 120.6,
                'budget_level' => 'economy',
                'estimated_cost' => 100,
                'recommended_minutes' => 90,
            ]);

            $destination->tags()->attach($tag);

            DestinationSwipe::create([
                'user_id' => $this->user->id,
                'destination_id' => $destination->id,
                'action' => 'liked',
            ]);
        }
    }

    // ------------------------------------------------------- the meal

    public function test_a_generated_day_eats_lunch_in_the_block_the_scheduler_reserved(): void
    {
        $this->place('Eden Kitchen', Place::TYPE_FOOD, ['lunch', 'dinner'], 60, 400);

        $items = $this->generate();

        $meal = $items->firstWhere('item_type', 'meal');

        $this->assertNotNull($meal, 'the generated trip contains no meal at all');
        $this->assertSame('Eden Kitchen', $meal->place->name);

        // Exactly the reserved block, and inside it.
        $this->assertSame('12:00', substr((string) $meal->start_time, 0, 5));
        $this->assertSame('13:00', substr((string) $meal->end_time, 0, 5));
    }

    public function test_the_meal_sits_in_the_right_place_in_the_order(): void
    {
        $this->place('Eden Kitchen', Place::TYPE_FOOD, ['lunch'], 60, 400);

        // Scoped to ONE day, because sort_order is per day. Comparing the whole
        // trip's sort_order against 1..n is a test that fails for the right
        // reason on the right code, which is the worst kind.
        $dayOne = $this->generate()
            ->filter(fn ($item) => $item->day->day_number === 1)
            ->sortBy('sort_order');

        $times = $dayOne
            ->map(fn ($item) => substr((string) $item->start_time, 0, 5))
            ->all();

        $lunchAt = array_search('12:00', $times, true);

        $this->assertIsInt($lunchAt, 'the meal was not scheduled at lunch');

        // Something before it, something after it: inserted, not appended.
        $this->assertGreaterThan(0, $lunchAt);
        $this->assertLessThan(count($times) - 1, $lunchAt);

        // And sort_order is a dense 1..n with no gaps or repeats, which is what
        // the editor's cross-day drag depends on.
        $this->assertSame(
            range(1, $dayOne->count()),
            $dayOne->pluck('sort_order')->map(fn ($n) => (int) $n)->all()
        );
    }

    /**
     * The rule that stops this from becoming a lie.
     *
     * A place serving merienda and supper but not lunch cannot be put at noon.
     * Placing it anyway would be the same error as reading a registration
     * window as opening hours — a stored value used as something it is not.
     */
    public function test_a_place_that_does_not_serve_lunch_is_not_used_for_lunch(): void
    {
        $this->place('Snack Bar', Place::TYPE_FOOD, ['merienda', 'supper'], 60, 400);

        $items = $this->generate();

        $this->assertNull(
            $items->firstWhere('item_type', 'meal'),
            'a place that does not serve lunch was scheduled into the lunch block'
        );
    }

    public function test_two_days_do_not_eat_at_the_same_place(): void
    {
        $this->place('First Floor', Place::TYPE_FOOD, ['lunch'], 60, 400);
        $this->place('Second Floor', Place::TYPE_FOOD, ['lunch'], 60, 450);

        $meals = $this->generate()->where('item_type', 'meal')->pluck('place_id')->all();

        $this->assertCount(2, $meals);
        $this->assertCount(2, array_unique($meals));
    }

    // ------------------------------------------------------ the lodging

    public function test_a_trip_gets_lodging_once_on_the_first_day(): void
    {
        $this->place('Citylight Hotel', Place::TYPE_LODGING, [], 0, 1800, Place::PRICE_PER_ROOM_NIGHT);

        $lodging = $this->generate()->where('item_type', 'lodging');

        $this->assertCount(1, $lodging, 'lodging must be charged once per trip, not once a night');

        $item = $lodging->first();

        $this->assertSame('Citylight Hotel', $item->place->name);
        $this->assertSame(
            $item->day->day_number,
            1,
            'lodging landed on a day other than the first'
        );

        // Zero duration, on purpose: you do not spend ninety minutes at your
        // own hotel, and a fabricated hour would displace a real visit.
        $this->assertSame(
            substr((string) $item->start_time, 0, 5),
            substr((string) $item->end_time, 0, 5)
        );
    }

    /**
     * THE HOTEL IS WHERE THE DAY STARTS.
     *
     * It used to be appended to the end of day one, on the reasoning that you go
     * back to your hotel after your last stop. True of the drive, and it produced
     * a plan with no idea where the traveller was standing: day one opened with
     * an attraction, and the first drive of the whole trip had no leg in it at
     * all, because there was no previous pin to measure from.
     */
    public function test_the_hotel_is_the_first_thing_on_day_one(): void
    {
        $this->place('Citylight Hotel', Place::TYPE_LODGING, [], 0, 1800, Place::PRICE_PER_ROOM_NIGHT);

        $dayOne = $this->generate()
            ->filter(fn ($item) => $item->day->day_number === 1)
            ->sortBy('sort_order');

        $this->assertSame(
            'lodging',
            $dayOne->first()->item_type,
            'the day does not open at the hotel'
        );

        // ...at the start of the day window.
        $this->assertSame('08:00', substr((string) $dayOne->first()->start_time, 0, 5));
    }

    /**
     * The hotel is NOT an attraction and must not eat one of the three slots.
     *
     * Seeding it before the loop is what makes the first drive measurable, and
     * the obvious way to do that is to let it count towards MAX_STOPS_PER_DAY --
     * which silently costs the traveller a stop on day one and was the reason
     * the cap needed its own counter.
     */
    public function test_the_hotel_does_not_consume_one_of_the_three_attraction_slots(): void
    {
        $this->place('Citylight Hotel', Place::TYPE_LODGING, [], 0, 1800, Place::PRICE_PER_ROOM_NIGHT);

        $dayOne = $this->generate()->filter(fn ($item) => $item->day->day_number === 1);

        $this->assertSame(
            ItinerarySchedule::MAX_STOPS_PER_DAY,
            $dayOne->where('item_type', 'attraction')->count(),
            'the hotel displaced a real visit'
        );
    }

    /**
    /**
     * THE HOTEL DOES NOT COST THE DAY ITS LUNCH, AND THE HARD CASE IS AN
     * AFTERNOON-ONLY ATTRACTION.
     *
     * The easy case is uninteresting: attractions fill the morning and the meal
     * lands in the gap before one that `nextStart()` pushed to 13:00. The case
     * that matters is a day whose ONLY attraction does not open until 13:00 --
     * the traveller then stands at their hotel from 08:00 to 13:00 with nothing
     * planned, which is exactly when they want lunch.
     *
     * This test exists because a guard was written for it and was wrong. The
     * lunch scan was changed to skip the lodging row, on the reasoning that "the
     * gap is between things you DO", and it deleted the meal here. It also
     * changed nothing on any other day, because `nextStart()` already moves a
     * visit that would cross noon to 13:00 -- so it was a guard that was inert
     * everywhere else and destructive on the one day it could reach.
     */
    public function test_an_afternoon_only_day_still_gets_its_lunch(): void
    {
        $this->place('Eden Kitchen', Place::TYPE_FOOD, ['lunch'], 60, 400);

        // Opens at 13:00, so nothing in this trip can start before then.
        $destination = Destination::create([
            'name' => 'Afternoon Only',
            'slug' => 'afternoon-only',
            'description' => 'A place.',
            'province' => 'Benguet',
            'latitude' => 16.4,
            'longitude' => 120.6,
            'budget_level' => 'economy',
            'estimated_cost' => 100,
            'recommended_minutes' => 60,
            'opening_time' => '13:00',
            'closing_time' => '17:00',
        ]);

        $destination->tags()->attach(Tag::where('slug', 'nature')->firstOrFail());
        DestinationSwipe::create([
            'user_id' => $this->user->id,
            'destination_id' => $destination->id,
            'action' => 'liked',
        ]);

        $items = $this->generate(days: 1, only: [$destination->id]);

        $meal = $items->firstWhere('item_type', 'meal');

        $this->assertNotNull($meal, 'five idle hours at the hotel, and no lunch');
        $this->assertSame('12:00', substr((string) $meal->start_time, 0, 5));

        // And it goes BEFORE the attraction, not after it.
        $this->assertLessThan(
            (int) $items->firstWhere('item_type', 'attraction')->sort_order,
            (int) $meal->sort_order
        );
    }

    /**
     * The first drive of the trip is a real leg, like every other.
     *
     * Only true once the hotel leads the day: a leg needs a previous pin, and an
     * attraction-first day had none.
     */
    public function test_the_first_drive_of_the_trip_is_measured_from_the_hotel(): void
    {
        $this->place('Citylight Hotel', Place::TYPE_LODGING, [], 0, 1800, Place::PRICE_PER_ROOM_NIGHT);

        $firstAttraction = $this->generate()
            ->filter(fn ($item) => $item->day->day_number === 1 && $item->item_type === 'attraction')
            ->sortBy('sort_order')
            ->first();

        $this->assertNotNull($firstAttraction);
        $this->assertSame(
            'resolved',
            $firstAttraction->route_status,
            'the drive from the hotel to the first stop was never measured'
        );
        $this->assertNotNull($firstAttraction->route_distance_meters);
    }

    public function test_a_three_day_trip_still_pays_for_one_nights_lodging(): void
    {
        $this->place('Citylight Hotel', Place::TYPE_LODGING, [], 0, 1800, Place::PRICE_PER_ROOM_NIGHT);

        $items = $this->generate(days: 3);

        $this->assertCount(1, $items->where('item_type', 'lodging'));

        // One room for one night, never scaled by group size: six people share a
        // room, and multiplying a room rate by six is a figure nobody can act on.
        $this->assertEquals(1800.0, (float) $items->firstWhere('item_type', 'lodging')->estimated_cost);
    }

    public function test_a_group_of_two_pays_for_two_meals(): void
    {
        $this->place('Eden Kitchen', Place::TYPE_FOOD, ['lunch'], 60, 400, Place::PRICE_PER_PERSON_MEAL);

        $meal = $this->generate()->firstWhere('item_type', 'meal');

        $this->assertEquals(800.0, (float) $meal->estimated_cost);
    }

    // ------------------------------------------------------ the absence

    public function test_a_province_with_nothing_researched_still_generates(): void
    {
        $this->place('Citylight Hotel', Place::TYPE_LODGING, [], 0, 1800, Place::PRICE_PER_ROOM_NIGHT, 'Ifugao');

        $items = $this->generate();

        // No food in Benguet, so no meal — and no complaint about it.
        $this->assertNull($items->firstWhere('item_type', 'meal'));
        $this->assertNull($items->firstWhere('item_type', 'lodging'));

        $this->assertGreaterThan(0, $items->where('item_type', 'attraction')->count());
    }

    /**
     * An unpriced place stays unpriced.
     *
     * 27 of the 36 researched places carry no verified price. Writing 0 and
     * calling it a total would be this project's central dishonesty, so the
     * column takes the 0 that means "unknown" and the item reads back as having
     * no verified price — which is what the unpriced-stop notice counts.
     */
    public function test_an_unpriced_place_is_not_added_to_a_total_it_cannot_be_part_of(): void
    {
        $this->place('Unpriced Eatery', Place::TYPE_FOOD, ['lunch'], 60, null);

        $meal = $this->generate()->firstWhere('item_type', 'meal');

        $this->assertNotNull($meal);
        $this->assertSame(0.0, (float) $meal->estimated_cost);
    }

    // ---------------------------------------------------------- helpers

    private function generate(int $days = 2, ?array $only = null)
    {
        $this->actingAs($this->user)
            ->post(route('itineraries.store'), [
                'title' => 'A complete trip',
                'area' => 'Benguet',
                'days' => $days,
                'destination_ids' => $only ?? Destination::pluck('id')->all(),
            ])
            ->assertRedirect();

        // Reached through the itinerary rather than eager-loaded: a
        // `with(['day'])` came back null on rows whose foreign key plainly
        // named a day, and debugging that was a detour. Reading the trip the
        // way a traveller does loads the same relations without the puzzle.
        return $this->user->itineraries()
            ->firstOrFail()
            ->load('days.items.place')
            ->days
            ->flatMap(fn ($day) => $day->items);
    }

    private function place(
        string $name,
        string $type,
        array $mealSlots,
        int $minutes,
        ?int $price,
        string $basis = Place::PRICE_PER_PERSON,
        string $province = 'Benguet'
    ): Place {
        return Place::create([
            'name' => $name,
            'slug' => str($name)->slug(),
            'type' => $type,
            'province' => $province,
            'municipality' => 'Baguio',
            'latitude' => 16.4,
            'longitude' => 120.6,
            'budget_level' => 'economy',
            'meal_slots' => $mealSlots,
            'recommended_minutes' => $minutes,
            'price_min' => $price,
            'price_max' => $price,
            'price_basis' => $price === null ? null : $basis,
            'is_active' => true,
            // NOT NULL at the schema level, deliberately: a place cannot exist
            // without a source. The helper has to supply one for the same reason
            // the seeder rejects unsourced rows.
            'source_url' => 'https://example.test/'.str($name)->slug(),
        ]);
    }
}
