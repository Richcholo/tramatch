<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\Place;
use App\Models\User;
use App\Services\Pdf\ItineraryPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE PRINTED TRIP, WHICH IS THE ONE ARTEFACT A TRAVELLER KEEPS AND TRUSTS.
 *
 * The PDF was built when every stop was an attraction, and it read
 * `$destination->name ?? 'Stop'`. A meal, a hotel and the traveller's own
 * entry therefore printed the literal word **"Stop"** — no name, no place, no
 * price, no source — on a document that looked finished and described a third
 * of the trip.
 *
 * The invariants:
 *
 * - **EVERY STOP IS READ THROUGH ITS OWN READERS**, so all four shapes print
 *   a real name and a real location.
 * - **A PRICE PRINTS ITS UNIT, OR ITS ABSENCE.** "PHP 0" is not a free lunch:
 *   an unpriced stop stores 0 because the column cannot hold null. A place
 *   prints "per room, per night" beside its band, because a bare number is a
 *   rumour.
 * - **THE TOTAL SAYS IT IS A SUM OF VERIFIED PRICES** wherever it appears —
 *   cover, day footer and colophon — using the SAME rule the web header counts
 *   with (`ItineraryItem::hasVerifiedPrice()`).
 * - **EVERY CURATED FIGURE IS CITED.** A price with no source cannot be
 *   checked when it turns out to be wrong.
 * - **AN UNRESOLVED LEG IS LABELLED UNRESOLVED**, which it already was and
 *   must stay.
 */
class ItineraryPdfStopsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Itinerary $trip;

    private ItineraryDay $day;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->trip = Itinerary::create([
            'user_id' => $this->user->id,
            'title' => 'Four shapes of a day',
            'budget_level' => 'economy',
            'trip_duration_days' => 1,
            'total_estimated_cost' => 4000,
        ]);

        $this->day = ItineraryDay::create([
            'itinerary_id' => $this->trip->id,
            'day_number' => 1,
        ]);
    }

    // ------------------------------------------------------- the four shapes

    public function test_a_meal_stop_prints_its_name_and_its_unit(): void
    {
        $place = $this->place('Le Chef', 'food', [
            'price_min' => 750,
            'price_max' => 950,
            'price_basis' => Place::PRICE_PER_PERSON_MEAL,
            'source_url' => 'https://example.test/le-chef',
        ]);

        $this->stop([
            'item_type' => 'meal',
            'place_id' => $place->id,
            'estimated_cost' => 850,
        ]);

        $text = $this->textOf();

        $this->assertStringContainsString('Le Chef', $text);
        $this->assertStringContainsString('Where to eat', $text);

        // The unit is the whole point of the basis column: PHP 850 beside
        // "a head, per meal" is a price; PHP 850 alone is a rumour.
        $this->assertStringContainsString('a head, per meal', $text);
        $this->assertStringContainsString('Source: https://example.test/le-chef', $text);

        // The old bug's fingerprint: the literal placeholder.
        $this->assertStringNotContainsString("\nStop\n", $text);
    }

    public function test_a_lodging_stop_prints_the_room_and_night_basis(): void
    {
        $place = $this->place('A Hotel Baguio', 'lodging', [
            'price_min' => 4500,
            'price_basis' => Place::PRICE_PER_ROOM_NIGHT,
            'source_url' => 'https://example.test/a-hotel',
        ]);

        $this->stop([
            'item_type' => 'lodging',
            'place_id' => $place->id,
            'estimated_cost' => 4500,
        ]);

        $text = $this->textOf();

        $this->assertStringContainsString('A Hotel Baguio', $text);
        $this->assertStringContainsString('Where to stay', $text);
        $this->assertStringContainsString('per room, per night', $text);
    }

    public function test_a_custom_stop_prints_what_the_traveller_typed_and_says_it_has_no_price(): void
    {
        $this->stop([
            'item_type' => 'custom',
            'custom_name' => 'The hotel you already booked',
            'custom_address' => 'Session Road, Baguio',
            'estimated_cost' => 0,
        ]);

        $text = $this->textOf();

        $this->assertStringContainsString('The hotel you already booked', $text);
        $this->assertStringContainsString('Session Road, Baguio', $text);
        $this->assertStringContainsString('Your stop', $text);

        // PHP 0 would state a price nobody published.
        //
        // COUNTED, not merely present: the colophon also explains missing
        // prices, and an earlier version of this copy QUOTED the exact phrase,
        // so a presence assertion passed on the colophon's prose while the
        // stop's own line printed nothing at all. The phrase now means exactly
        // one thing in the document — one stop, one line.
        $this->assertSame(
            1,
            substr_count($text, 'No published price'),
            'Exactly one stop line should claim a missing price.'
        );
    }

    public function test_an_attraction_still_prints_its_estimate_and_its_hours(): void
    {
        $destination = Destination::create([
            'name' => 'Burnham Park',
            'slug' => 'burnham-park',
            'description' => 'A place.',
            'municipality' => 'Baguio',
            'province' => 'Benguet',
            'latitude' => 16.4095,
            'longitude' => 120.5960,
            'budget_level' => 'economy',
            'estimated_cost' => 60,
            'recommended_minutes' => 90,
            'opening_time' => '08:00',
            'closing_time' => '17:00',
            'is_active' => true,
        ]);

        $this->stop([
            'destination_id' => $destination->id,
            'estimated_cost' => 60,
        ]);

        $text = $this->textOf();

        $this->assertStringContainsString('Burnham Park', $text);
        $this->assertStringContainsString('Est. PHP 60.00', $text);
        $this->assertStringContainsString('08:00', $text);
    }

    public function test_a_place_with_no_verified_price_says_so_rather_than_printing_zero(): void
    {
        $place = $this->place('The unnamed canteen', 'food', [
            'price_min' => null,
            'price_basis' => null,
        ]);

        $this->stop([
            'item_type' => 'meal',
            'place_id' => $place->id,
            'estimated_cost' => 0,
        ]);

        $this->assertStringContainsString('No published price', $this->textOf());
    }

    // ----------------------------------------------- the total is a sum of some

    public function test_the_cover_says_the_total_leaves_out_unpriced_stops(): void
    {
        $this->stop(['item_type' => 'custom', 'custom_name' => 'Your own stop', 'estimated_cost' => 0]);
        $this->pricedAttraction();

        $text = $this->textOf();

        $this->assertStringContainsString('no published price', $text);
        $this->assertStringContainsString('the total above counts the verified ones', $text);
    }

    public function test_a_day_total_that_counts_an_unpriced_stop_says_so(): void
    {
        $this->stop(['item_type' => 'custom', 'custom_name' => 'Your own stop', 'estimated_cost' => 0]);

        $text = $this->textOf();

        $this->assertStringContainsString('this day total counts the verified ones', $text);
    }

    public function test_a_fully_priced_trip_warns_about_nothing(): void
    {
        $place = $this->place('Le Chef', 'food', [
            'price_min' => 750,
            'price_basis' => Place::PRICE_PER_PERSON_MEAL,
        ]);

        $this->stop([
            'item_type' => 'meal',
            'place_id' => $place->id,
            'estimated_cost' => 750,
        ]);

        $text = $this->textOf();

        $this->assertStringNotContainsString('No published price', $text);
        $this->assertStringNotContainsString('counts the verified ones', $text);
        $this->assertStringNotContainsString('Some prices are missing', $text);
    }

    public function test_the_colophon_explains_a_missing_price_when_there_is_one(): void
    {
        $this->stop(['item_type' => 'custom', 'custom_name' => 'Your own stop', 'estimated_cost' => 0]);

        $this->assertStringContainsString('Some prices are missing', $this->textOf());
    }

    // ------------------------------------------------------------- the legs

    public function test_an_unmeasured_gap_is_labelled_and_a_measured_leg_is_not(): void
    {
        $this->pricedAttraction(['travel_minutes_from_previous' => 45, 'route_status' => 'unresolved']);

        $this->assertStringContainsString('drive not measured', $this->textOf());
    }

    public function test_a_measured_leg_prints_the_road_and_not_the_gap(): void
    {
        $this->pricedAttraction([
            'travel_minutes_from_previous' => 28,
            'route_status' => 'resolved',
            'route_distance_meters' => 9900,
            'route_duration_minutes' => 28,
        ]);

        $text = $this->textOf();

        $this->assertStringContainsString('by road', $text);
        $this->assertStringNotContainsString('drive not measured', $text);
    }

    // ---------------------------------------------------------------- helpers

    private function pricedAttraction(array $overrides = []): ItineraryItem
    {
        $destination = Destination::create([
            'name' => $overrides['name'] ?? 'Wright Park',
            'slug' => str($overrides['name'] ?? 'wright-park')->slug(),
            'description' => 'A place.',
            'municipality' => 'Baguio',
            'province' => 'Benguet',
            'latitude' => 16.3950,
            'longitude' => 120.5610,
            'budget_level' => 'economy',
            'estimated_cost' => 60,
            'recommended_minutes' => 90,
            'is_active' => true,
        ]);

        return $this->stop(array_merge([
            'destination_id' => $destination->id,
            'estimated_cost' => 60,
        ], $overrides, ['destination_id' => $destination->id]));
    }

    private function stop(array $attributes): ItineraryItem
    {
        return ItineraryItem::create(array_merge([
            'itinerary_day_id' => $this->day->id,
            'sort_order' => ItineraryItem::count() + 1,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ], $attributes));
    }

    private function place(string $name, string $type, array $attributes): Place
    {
        return Place::create(array_merge([
            'name' => $name,
            'slug' => str($name)->slug(),
            'type' => $type,
            'province' => 'Benguet',
            'municipality' => 'Baguio City',
            'latitude' => 16.4120,
            'longitude' => 120.5960,
            'budget_level' => 'economy',
            'source_url' => 'https://example.test/'.str($name)->slug(),
        ], $attributes));
    }

    private function textOf(): string
    {
        $pdf = (new ItineraryPdf())->build($this->trip->fresh(['days.items']));

        preg_match_all('/<< \/Length \d+ \/?[^>]*>>\nstream\n(.*?)\nendstream/s', $pdf, $streams);

        $text = '';

        foreach ($streams[1] as $content) {
            preg_match_all('/\((.*?)\) Tj/s', $content, $found);

            foreach ($found[1] as $raw) {
                $unescaped = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $raw);

                $text .= mb_convert_encoding($unescaped, 'UTF-8', 'Windows-1252')."\n";
            }
        }

        return $text;
    }
}
