<?php

namespace Tests\Feature\Admin;

use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\Place;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin places screens: CRUD, verification stamps, and the coverage
 * report that tells curation what is missing.
 *
 * Shaped like the destinations admin tests, because the screens are shaped
 * like the destinations screens. What is asserted here and nowhere else is
 * the places-specific contract: sources are mandatory, prices stay null, a
 * hotel check-in is 0 minutes, and deleting a place never takes a
 * traveller's stop with it.
 */
class PlaceAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_guests_are_redirected_and_non_admins_are_refused(): void
    {
        $place = $this->place();

        $this->get(route('admin.places.index'))->assertRedirect(route('login'));

        $traveller = User::factory()->create();

        foreach ([
            ['get', route('admin.places.index')],
            ['get', route('admin.places.create')],
            ['get', route('admin.places.edit', $place)],
        ] as [$method, $url]) {
            $this->actingAs($traveller)->{$method}($url)->assertForbidden();
        }

        $this->actingAs($traveller)
            ->post(route('admin.places.store'), $this->payload())
            ->assertForbidden();

        $this->actingAs($traveller)
            ->put(route('admin.places.update', $place), $this->payload())
            ->assertForbidden();

        $this->actingAs($traveller)
            ->patch(route('admin.places.archive', $place))
            ->assertForbidden();

        $this->actingAs($traveller)
            ->patch(route('admin.places.restore', $place))
            ->assertForbidden();

        $this->actingAs($traveller)
            ->delete(route('admin.places.destroy', $place))
            ->assertForbidden();
    }

    public function test_the_index_names_each_cluster_and_its_gaps(): void
    {
        $this->place(['name' => 'Baguio Eatery', 'province' => 'Benguet']);

        $html = $this->actingAs($this->admin())
            ->get(route('admin.places.index'))
            ->assertOk()
            ->getContent();

        foreach (['Baguio', 'Antipolo', 'Paoay', 'Vigan', 'economy', 'mid-range', 'premium'] as $word) {
            $this->assertStringContainsString($word, $html);
        }

        // Benguet economy has one food place and no hotel: the report must say
        // both numbers, not just the ready/short badge.
        $this->assertStringContainsString('1 food', $html);
        $this->assertStringContainsString('0 lodging', $html);
        $this->assertStringContainsString('of 6 needed', $html);
        $this->assertStringContainsString('of 3 needed', $html);
    }

    public function test_the_index_filters_by_search_and_kind(): void
    {
        $this->place(['name' => 'Cafe Oro', 'type' => Place::TYPE_FOOD]);
        $this->place(['name' => 'Ridge Hotel', 'type' => Place::TYPE_LODGING]);

        $html = $this->actingAs($this->admin())
            ->get(route('admin.places.index', ['search' => 'Cafe']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Cafe Oro', $html);
        $this->assertStringNotContainsString('Ridge Hotel', $html);

        $html = $this->actingAs($this->admin())
            ->get(route('admin.places.index', ['type' => 'lodging']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Ridge Hotel', $html);
        $this->assertStringNotContainsString('Cafe Oro', $html);
    }

    public function test_the_create_page_carries_the_price_and_source_fields(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.places.create'))
            ->assertOk()
            ->assertSee('name="type"', false)
            ->assertSee('name="price_min"', false)
            ->assertSee('name="price_max"', false)
            ->assertSee('name="price_basis"', false)
            ->assertSee('name="meal_slots[]"', false)
            ->assertSee('name="source_url"', false)
            ->assertSee('name="opening_time"', false)
            ->assertSee('name="closed_days[]"', false);
    }

    public function test_storing_stamps_both_verifications_when_hours_and_price_are_given(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.places.store'), $this->payload([
                'opening_time' => '10:00',
                'closing_time' => '21:00',
                'price_min' => '250',
                'price_max' => '1200',
                'price_basis' => Place::PRICE_PER_PERSON_MEAL,
            ]))
            ->assertRedirect(route('admin.places.index'));

        $place = Place::where('slug', 'storable-cafe')->firstOrFail();

        $this->assertNotNull($place->last_verified_at);
        $this->assertNotNull($place->price_verified_at);
        $this->assertSame(250.0, (float) $place->price_min);
        $this->assertSame('₱250–₱1,200 a head, per meal', $place->priceLabel());
    }

    /**
     * AN UNSOURCED ROW IS REFUSED BY THE FORM, exactly as the seeder refuses
     * it. The form must not admit what the seeder would reject on re-seed,
     * or every re-seed silently drops the row the admin just created.
     */
    public function test_storing_refuses_a_place_without_a_source(): void
    {
        $response = $this->actingAs($this->admin())
            ->from(route('admin.places.create'))
            ->post(route('admin.places.store'), $this->payload(['source_url' => '']));

        $response->assertSessionHasErrors('source_url');
        $this->assertSame(0, Place::count());
    }

    /**
     * A BLANK PRICE IS NULL, and clearing one round-trips back to unknown.
     *
     * `numeric` would reject the empty string the browser sends for a cleared
     * field, so the controller nulls blanks before validating. Without that,
     * removing a withdrawn price fails the save that was trying to remove it.
     */
    public function test_a_blank_price_is_stored_as_unknown_and_never_stamped(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.places.store'), $this->payload([
                'price_min' => '',
                'price_max' => '',
                'price_basis' => '',
            ]))
            ->assertRedirect(route('admin.places.index'));

        $place = Place::where('slug', 'storable-cafe')->firstOrFail();

        $this->assertNull($place->price_min);
        $this->assertNull($place->price_basis);
        $this->assertNull($place->price_verified_at);
        $this->assertFalse($place->hasVerifiedPrice());
    }

    public function test_a_maximum_without_a_minimum_is_rejected(): void
    {
        $response = $this->actingAs($this->admin())
            ->from(route('admin.places.create'))
            ->post(route('admin.places.store'), $this->payload([
                'price_min' => '',
                'price_max' => '1200',
            ]));

        $response->assertSessionHasErrors('price_min');
        $this->assertSame(0, Place::count());
    }

    public function test_a_reversed_band_and_a_bad_meal_slot_are_rejected(): void
    {
        $response = $this->actingAs($this->admin())
            ->from(route('admin.places.create'))
            ->post(route('admin.places.store'), $this->payload([
                'price_min' => '1200',
                'price_max' => '250',
                'meal_slots' => ['lunch', 'brunch'],
            ]));

        $response->assertSessionHasErrors(['price_max', 'meal_slots.1']);
        $this->assertSame(0, Place::count());
    }

    /**
     * A HOTEL CHECK-IN IS 0 MINUTES, and the form floor is 0 for exactly that
     * reason. A 15-minute floor copied from destinations would force every
     * overnight stay to be scheduled as a quarter-hour attraction.
     */
    public function test_a_hotel_can_be_saved_with_a_zero_minute_stay(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.places.store'), $this->payload([
                'name' => 'Zero Stay Hotel',
                'type' => Place::TYPE_LODGING,
                'recommended_minutes' => 0,
            ]))
            ->assertRedirect(route('admin.places.index'));

        $this->assertSame(
            0,
            Place::where('slug', 'zero-stay-hotel')->firstOrFail()->recommended_minutes
        );
    }

    public function test_an_unrelated_edit_does_not_restamp_either_verification(): void
    {
        $place = $this->place([
            'opening_time' => '10:00',
            'closing_time' => '21:00',
            'price_min' => 250,
            'price_basis' => Place::PRICE_PER_PERSON_MEAL,
            'last_verified_at' => '2026-08-01 10:00:00',
            'price_verified_at' => '2026-08-01 10:00:00',
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.places.update', $place), $this->payload([
                'name' => 'Renamed Cafe',
                'opening_time' => '10:00',
                'closing_time' => '21:00',
                'price_min' => '250.00',
                'price_basis' => Place::PRICE_PER_PERSON_MEAL,
            ]))
            ->assertRedirect(route('admin.places.index'));

        $fresh = $place->fresh();

        // "250" and "250.00" are the same price: restamping over a formatting
        // difference would make the date meaningless.
        $this->assertSame('2026-08-01 10:00:00', $fresh->last_verified_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-01 10:00:00', $fresh->price_verified_at->format('Y-m-d H:i:s'));
    }

    public function test_changing_hours_and_price_restamps_each_separately(): void
    {
        $place = $this->place([
            'opening_time' => '10:00',
            'closing_time' => '21:00',
            'price_min' => 250,
            'price_basis' => Place::PRICE_PER_PERSON_MEAL,
            'last_verified_at' => '2026-08-01 10:00:00',
            'price_verified_at' => '2026-08-01 10:00:00',
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.places.update', $place), $this->payload([
                'opening_time' => '09:00',
                'closing_time' => '21:00',
                'price_min' => '300',
                'price_basis' => Place::PRICE_PER_PERSON_MEAL,
            ]))
            ->assertRedirect(route('admin.places.index'));

        $fresh = $place->fresh();

        $this->assertNotSame('2026-08-01 10:00:00', $fresh->last_verified_at->format('Y-m-d H:i:s'));
        $this->assertNotSame('2026-08-01 10:00:00', $fresh->price_verified_at->format('Y-m-d H:i:s'));
        $this->assertSame(300.0, (float) $fresh->price_min);
    }

    public function test_archiving_hides_the_place_and_restoring_brings_it_back(): void
    {
        $place = $this->place();

        $this->actingAs($this->admin())
            ->patch(route('admin.places.archive', $place))
            ->assertRedirect();

        $this->assertFalse($place->fresh()->is_active);

        $this->actingAs($this->admin())
            ->patch(route('admin.places.restore', $place))
            ->assertRedirect();

        $this->assertTrue($place->fresh()->is_active);
    }

    /**
     * DELETING A PLACE MUST NOT TAKE THE STOP WITH IT.
     *
     * `place_id` nulls on delete at the schema level: a restaurant closes, the
     * row goes, and the traveller's saved trip keeps the stop with its type
     * and note. The delete dialog on the index says exactly this, and this is
     * the test that makes the dialog honest.
     */
    public function test_deleting_a_place_leaves_itinerary_stops_in_place(): void
    {
        $place = $this->place(['name' => 'Closing Cafe']);

        $user = User::factory()->create();

        $itinerary = Itinerary::create([
            'user_id' => $user->id,
            'title' => 'A trip',
            'area' => 'Benguet',
            'budget_level' => 'economy',
            'trip_duration_days' => 1,
        ]);

        $day = ItineraryDay::create([
            'itinerary_id' => $itinerary->id,
            'day_number' => 1,
        ]);

        $item = ItineraryItem::create([
            'itinerary_day_id' => $day->id,
            'item_type' => ItineraryItem::TYPE_MEAL,
            'destination_id' => null,
            'place_id' => $place->id,
            'sort_order' => 1,
            'note' => 'Lunch on day one',
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.places.destroy', $place))
            ->assertRedirect(route('admin.places.index'));

        $this->assertNull(Place::find($place->id));

        $survivor = $item->fresh();

        $this->assertNotNull($survivor);
        $this->assertNull($survivor->place_id);
        $this->assertTrue($survivor->isMeal());
        $this->assertSame('Lunch on day one', $survivor->note);
    }

    // -------------------------------------------------------------- helpers

    private function place(array $overrides = []): Place
    {
        static $sequence = 0;

        $sequence++;

        return Place::create(array_merge([
            'name' => 'Test Place '.$sequence,
            'slug' => 'test-place-'.$sequence,
            'type' => Place::TYPE_FOOD,
            'province' => 'Benguet',
            'municipality' => 'Baguio City',
            'latitude' => 16.4120,
            'longitude' => 120.5960,
            'budget_level' => 'economy',
            'source_url' => 'https://example.test/place/'.$sequence,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Storable Cafe',
            'type' => Place::TYPE_FOOD,
            'cluster' => 'baguio',
            'province' => 'Benguet',
            'municipality' => 'Baguio City',
            'address' => 'Session Road',
            'latitude' => 16.4120,
            'longitude' => 120.5960,
            'budget_level' => 'economy',
            'cuisine' => 'Filipino',
            'meal_slots' => ['lunch', 'dinner'],
            'price_min' => '',
            'price_max' => '',
            'price_basis' => '',
            'recommended_minutes' => 60,
            'source_url' => 'https://example.test/storable-cafe',
            'source_label' => 'Official page',
        ], $overrides);
    }
}
