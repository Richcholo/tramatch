<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Place;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A day visits what is NEAR, not what scored highest.
 *
 * Generation used to walk one cursor through the traveller's ticked order and
 * take whatever it pointed at. Since the create form ticks places in ranking
 * order, that produced days that were geographically scattered: three places at
 * the top of a ranking can be an hour apart, and the traveller is driven between
 * them for a reason nobody chose.
 *
 * The important half of this feature is what it must NOT do. Choosing the nearest
 * candidate is only safe because every candidate has already passed the same
 * hours, duration and cost checks it always did — geography chooses between
 * options that were always going to be legal. Two of these tests exist purely to
 * pin that: ordering must not talk the scheduler into breaking a cited opening
 * window, and it must not resurrect a place that is shut that day.
 *
 * The second fix here is quieter and was a bug before it was a feature: every
 * rejection advanced the cursor, so a place closed on Monday was dropped from the
 * trip entirely and never retried, even though it was open the rest of the week.
 * That is the same shape of error as reading a registration window as opening
 * hours — a fact about one day presented as a fact about the place.
 */
class ItineraryGeographyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** Burnham Park, Baguio. */
    private const NEAR = [16.3540, 120.5860];

    /** A long way up the Cordillera, still Benguet so it passes the area filter. */
    private const FAR = [16.9000, 120.9000];

    /**
     * THE CLOSED-MONDAY TESTS NEED A MONDAY, AND A HARDCODED ONE ROTS.
     *
     * These two tests pinned `2026-10-19` and passed for exactly as long as
     * that date was today: the store request validates `after_or_equal:today`,
     * so the moment the calendar moved on, the POST failed validation, no
     * itinerary was created, and the two tests failed with "No query results
     * for model [Itinerary]" — a message that names nothing about dates.
     *
     * So the start date is COMPUTED: always the next Monday, which is what
     * "shut on the trip's first day and open on the second" needs the start
     * date to be, in every week, forever. If today is a Monday the Monday
     * after next is used and the assertion still reads the same.
     */
    private function nextMonday(): string
    {
        return now()->next('Monday')->toDateString();
    }

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
    }

    /**
     * THE WHOLE POINT: the ticked order is not the visiting order.
     *
     * The far destination is ticked FIRST, so under the old cursor behaviour it
     * was visited first and the traveller opened the day with a two-hour drive.
     */
    public function test_the_day_opens_with_the_nearest_place_not_the_first_ticked(): void
    {
        $far = $this->destination('Far Ridge', self::FAR);
        $near = $this->destination('Burnham Park', self::NEAR);

        $this->hotel();

        $day = $this->generate([$far->id, $near->id])->days->first();

        $attractions = $day->items->where('item_type', 'attraction')->sortBy('sort_order');

        $this->assertSame(
            'Burnham Park',
            $attractions->first()->destination->name,
            'the day opened with the far destination because it was ticked first'
        );
    }

    /**
     * GEOGRAPHY MAY NOT OVERRIDE A CITED CLOSURE.
     *
     * The near destination is shut on the trip's first day and open on the
     * second. It must not be chosen on day one *however* close it is — that is
     * the whole contract of the hours work, and an ordering pass that ignores it
     * would put a traveller in front of a locked gate.
     */
    public function test_a_closed_destination_is_never_chosen_for_being_nearest(): void
    {
        $near = $this->destination('Burnham Park', self::NEAR, closedDays: 'monday');
        $far = $this->destination('Far Ridge', self::FAR);

        $this->hotel();

        $day = $this->generate([$near->id, $far->id], startDate: $this->nextMonday())
            ->days->first();

        $names = $day->items
            ->where('item_type', 'attraction')
            ->pluck('destination.name')
            ->all();

        $this->assertNotContains('Burnham Park', $names, 'a place closed that day was scheduled anyway');
        $this->assertContains('Far Ridge', $names);
    }

    /**
     * AND IT IS STILL IN THE TRIP ON THE NEXT DAY.
     *
     * This is the deferral fix. Under the old cursor, one closure ended the
     * destination's candidacy for the whole trip.
     */
    public function test_a_destination_closed_today_is_retried_on_the_next_day(): void
    {
        $near = $this->destination('Burnham Park', self::NEAR, closedDays: 'monday');

        // Four places, three slots a day: day one cannot swallow the lot, so day
        // two is the only place Burnham Park can still fit.
        $others = collect(range(1, 3))->map(fn ($n) => $this->destination(
            'Filler ' . $n, [16.5 + ($n / 100), 120.5 + ($n / 100)]
        ));

        $this->hotel();

        $ids = [$near->id, ...$others->pluck('id')->all()];

        $days = $this->generate($ids, days: 2, startDate: $this->nextMonday())
            ->days
            ->keyBy('day_number');

        $first = $days[1]->items->pluck('destination.name')->all();
        $second = $days[2]->items->pluck('destination.name')->all();

        $this->assertNotContains('Burnham Park', $first);
        $this->assertContains('Burnham Park', $second, 'one closed Monday cost it the whole trip');
    }

    /**
     * EVERY DAY DEPARTS FROM THE HOTEL, NOT ONLY THE FIRST.
     *
     * The obvious implementation anchors day one on the hotel and lets every
     * later day carry on from wherever day one finished — which is wrong twice
     * over, because the traveller sleeps at the hotel every night, so day two
     * begins a drive from the same place day one did.
     *
     * The assertion is about what day two OPENS with, not a specific place:
     * Burnham Park is nearest the hotel and is therefore consumed on day one, so
     * the meaningful claim is that day two starts with the nearest of what is
     * LEFT rather than with whatever the ranking left at the front — here, not
     * the far destination that was ticked first.
     */
    public function test_the_second_day_also_departs_from_the_hotel(): void
    {
        $far = $this->destination('Far Ridge', self::FAR);
        $near = $this->destination('Burnham Park', self::NEAR);

        $others = collect(range(1, 3))->map(fn ($n) => $this->destination(
            'Filler ' . $n, [16.5 + ($n / 100), 120.5 + ($n / 100)]
        ));

        $this->hotel();

        $days = $this->generate(
            [$far->id, $near->id, ...$others->pluck('id')->all()],
            days: 2
        )
            ->days
            ->keyBy('day_number');

        // Day one opens at the hotel, which is only true because it is seeded
        // before the loop.
        $this->assertSame(
            'lodging',
            $days[1]->items->sortBy('sort_order')->first()->item_type
        );

        // And day one opens at the NEAR place, not the far one that was ticked
        // first.
        $this->assertSame(
            'Burnham Park',
            $days[1]->items->where('item_type', 'attraction')->sortBy('sort_order')->first()->destination->name
        );

        // Day two is measured from the hotel again. The far ridge is ticked
        // first and is the furthest thing in the province; it is not what a
        // traveller sets off for at 08:00.
        $secondOpens = $days[2]->items
            ->where('item_type', 'attraction')
            ->sortBy('sort_order')
            ->first();

        $this->assertNotNull($secondOpens, 'day two has nothing on it');
        $this->assertNotSame(
            'Far Ridge',
            $secondOpens->destination->name,
            'day two opened with the furthest place in the province'
        );
    }

    /**
     * WITH NO HOTEL THERE IS NO ORIGIN, AND THE OLD ORDER STANDS.
     *
     * Two of the three researched provinces are exactly this case, and silently
     * inventing an origin for them would order a trip by a fiction.
     */
    public function test_without_a_hotel_the_ticked_order_stands(): void
    {
        $far = $this->destination('Far Ridge', self::FAR);
        $near = $this->destination('Burnham Park', self::NEAR);

        $day = $this->generate([$far->id, $near->id])->days->first();

        $attractions = $day->items->where('item_type', 'attraction')->sortBy('sort_order');

        $this->assertSame(
            'Far Ridge',
            $attractions->first()->destination->name,
            'with no origin, the catalogue order should be preserved'
        );
    }

    /**
     * THE SAME REQUEST ALWAYS PRODUCES THE SAME TRIP.
     *
     * The plan proposed ordering over the cached OSRM legs, which would make the
     * result depend on whether the cache happened to be warm — a generator that
     * is not deterministic cannot be tested or reasoned about. Straight-line
     * distance is the reason this holds.
     */
    public function test_the_same_request_produces_the_same_order_every_time(): void
    {
        $far = $this->destination('Far Ridge', self::FAR);
        $nearA = $this->destination('Burnham Park', self::NEAR);
        $nearB = $this->destination('Session Road', [16.4090, 120.5940]);

        $this->hotel();

        $first = $this->order([$far->id, $nearA->id, $nearB->id]);
        $second = $this->order([$far->id, $nearA->id, $nearB->id]);

        $this->assertSame($first, $second);
    }


    private function hotel(): Place
    {
        return Place::create([
            'name' => 'Citylight Hotel',
            'slug' => 'citylight-hotel',
            'type' => Place::TYPE_LODGING,
            'province' => 'Benguet',
            'municipality' => 'Baguio',
            'latitude' => self::NEAR[0],
            'longitude' => self::NEAR[1],
            'budget_level' => 'economy',
            'recommended_minutes' => 0,
            'price_min' => 1800,
            'price_max' => 1800,
            'price_basis' => Place::PRICE_PER_ROOM_NIGHT,
            'is_active' => true,
            'source_url' => 'https://example.test/hotel',
        ]);
    }

    private function destination(string $name, array $pin, ?string $closedDays = null): Destination
    {
        return Destination::create([
            'name' => $name,
            'slug' => str($name)->slug(),
            'description' => 'A place.',
            'province' => 'Benguet',
            'municipality' => 'Baguio',
            'latitude' => $pin[0],
            'longitude' => $pin[1],
            'budget_level' => 'economy',
            'estimated_cost' => 100,
            'recommended_minutes' => 90,
            'closed_days' => $closedDays,
            'is_featured' => true,
            'is_active' => true,
        ]);
    }

    private function generate(array $ids, int $days = 1, ?string $startDate = null)
    {
        $payload = [
            'title' => 'Geography',
            'area' => 'Benguet',
            'days' => $days,
            'destination_ids' => $ids,
        ];

        if ($startDate !== null) {
            $payload['start_date'] = $startDate;
        }

        $this->actingAs($this->user)
            ->post(route('itineraries.store'), $payload)
            ->assertRedirect();

        return $this->user->itineraries()
            ->latest('id')
            ->firstOrFail()
            ->load('days.items.destination');
    }

    /** @return array<int, string> */
    private function order(array $ids): array
    {
        return $this->generate($ids)->days->first()->items
            ->where('item_type', 'attraction')
            ->sortBy('sort_order')
            ->map(fn ($item) => $item->destination->name)
            ->values()
            ->all();
    }
}
