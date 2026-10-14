<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\Place;
use App\Models\User;
use App\Services\ItineraryStop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The stop seam `ItineraryEditor` reads through.
 *
 * These pin that the seam answers exactly what the direct `Destination` reads
 * used to answer, for every attraction row in existence — the refactor this
 * file arrived with must be behaviour-identical, and the editor suite alone
 * would not notice a quietly different duration or cost. The place and custom
 * branches have no editor behaviour yet (Days 4-5); what is pinned for them is
 * the contract the editor will rely on: unknown prices stay null at this
 * layer, and names and positions come from the item's own readers.
 */
class ItineraryStopTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_attraction_draft_resolves_exactly_what_the_editor_used_to_read(): void
    {
        $destination = $this->destination([
            'recommended_minutes' => 90,
            'estimated_cost' => 375,
        ]);

        [$destinations, $places] = ItineraryStop::preload([
            '1' => ['destination_id' => $destination->id],
        ]);

        $stop = ItineraryStop::fromFields(
            ['destination_id' => $destination->id],
            $destinations,
            $places
        );

        $this->assertNotNull($stop);
        $this->assertTrue($stop->isAttraction());
        $this->assertSame($destination->id, $stop->destination->id);
        $this->assertSame(90, $stop->durationMinutes());
        $this->assertSame(375.0, $stop->snapshotCost());
        $this->assertSame($destination->name, $stop->displayName());
        $this->assertSame(16.4120, $stop->latitude());
        $this->assertSame(120.5960, $stop->longitude());
        $this->assertTrue($stop->hasKnownLocation());
    }

    public function test_unknown_ids_resolve_to_nothing_rather_than_a_stop(): void
    {
        [$destinations, $places] = ItineraryStop::preload([
            '1' => ['destination_id' => 999999],
        ]);

        $this->assertNull(ItineraryStop::fromFields(
            ['destination_id' => 999999],
            $destinations,
            $places
        ));

        // A row pointing at nothing at all is not a custom entry either — a
        // custom entry carries something the traveller typed.
        $this->assertNull(ItineraryStop::fromFields([], $destinations, $places));
    }

    public function test_a_meal_draft_takes_the_places_type_when_none_is_given(): void
    {
        $place = $this->place(['type' => Place::TYPE_FOOD]);

        [$destinations, $places] = ItineraryStop::preload([
            '-1' => ['place_id' => $place->id],
        ]);

        $stop = ItineraryStop::fromFields(['place_id' => $place->id], $destinations, $places);

        $this->assertNotNull($stop);
        $this->assertSame(ItineraryItem::TYPE_MEAL, $stop->kind);
        $this->assertSame($place->name, $stop->displayName());
    }

    public function test_a_hotel_is_a_lodging_with_no_visit_length_and_no_price_when_unpriced(): void
    {
        $hotel = $this->place([
            'type' => Place::TYPE_LODGING,
            'price_min' => null,
            'price_max' => null,
            'recommended_minutes' => 0,
        ]);

        [$destinations, $places] = ItineraryStop::preload([
            '-1' => ['place_id' => $hotel->id],
        ]);

        $stop = ItineraryStop::fromFields(['place_id' => $hotel->id], $destinations, $places);

        $this->assertNotNull($stop);
        $this->assertSame(ItineraryItem::TYPE_LODGING, $stop->kind);
        $this->assertSame(0, $stop->durationMinutes());
        $this->assertNull(
            $stop->snapshotCost(),
            'An unpriced hotel reports unknown here; the column fallback lives in the editor, not the seam.'
        );
    }

    public function test_a_custom_draft_carries_what_the_traveller_typed(): void
    {
        [$destinations, $places] = ItineraryStop::preload([]);

        $stop = ItineraryStop::fromFields(
            [
                'custom_name' => 'Our booking at the Mountain Lodge',
                'custom_latitude' => 16.4120,
                'custom_longitude' => 120.5960,
            ],
            $destinations,
            $places
        );

        $this->assertNotNull($stop);
        $this->assertSame(ItineraryItem::TYPE_CUSTOM, $stop->kind);
        $this->assertSame('Our booking at the Mountain Lodge', $stop->displayName());
        $this->assertTrue($stop->hasKnownLocation());
        $this->assertNull($stop->snapshotCost());
    }

    public function test_a_stored_item_resolves_through_the_items_own_readers(): void
    {
        $item = $this->item([
            'item_type' => ItineraryItem::TYPE_MEAL,
            'destination_id' => null,
            'place_id' => $this->place(['name' => 'Cafe Oro'])->id,
        ]);

        $stop = ItineraryStop::forItem($item->fresh());

        $this->assertNotNull($stop);
        $this->assertSame('Cafe Oro', $stop->displayName());
        $this->assertSame($item->fresh()->latitude(), $stop->latitude());
        $this->assertSame($item->fresh()->longitude(), $stop->longitude());
    }

    public function test_kind_labels_read_the_same_in_every_view(): void
    {
        $this->assertSame('Attraction', $this->item()->kindLabel());

        $meal = $this->item([
            'item_type' => ItineraryItem::TYPE_MEAL,
            'destination_id' => null,
            'place_id' => $this->place()->id,
        ]);

        $this->assertSame('Where to eat', $meal->kindLabel());

        $hotel = $this->item([
            'item_type' => ItineraryItem::TYPE_LODGING,
            'destination_id' => null,
            'place_id' => $this->place(['type' => Place::TYPE_LODGING])->id,
        ]);

        $this->assertSame('Where to stay', $hotel->kindLabel());

        $custom = $this->item([
            'item_type' => ItineraryItem::TYPE_CUSTOM,
            'destination_id' => null,
            'custom_name' => 'Ours',
        ]);

        $this->assertSame('Your stop', $custom->kindLabel());
    }

    public function test_a_stored_item_whose_place_is_gone_resolves_to_nothing(): void
    {
        $place = $this->place();

        $item = $this->item([
            'item_type' => ItineraryItem::TYPE_MEAL,
            'destination_id' => null,
            'place_id' => $place->id,
        ]);

        $place->delete();

        $this->assertNull(ItineraryStop::forItem($item->fresh()));
    }

    public function test_preload_reads_both_tables_keyed_by_id(): void
    {
        $destination = $this->destination();
        $place = $this->place();

        [$destinations, $places] = ItineraryStop::preload([
            '1' => ['destination_id' => $destination->id, 'place_id' => $place->id],
        ]);

        $this->assertSame($destination->id, $destinations->get($destination->id)->id);
        $this->assertSame($place->id, $places->get($place->id)->id);
    }

    // -------------------------------------------------------------- helpers

    private function destination(array $overrides = []): Destination
    {
        return Destination::create(array_merge([
            'name' => 'Test Destination',
            'slug' => 'test-destination-'.uniqid(),
            'description' => 'A destination used by a test.',
            'province' => 'Benguet',
            'municipality' => 'Baguio City',
            'latitude' => 16.4120,
            'longitude' => 120.5960,
            'budget_level' => 'economy',
            'entrance_fee' => 0,
            'estimated_cost' => 0,
            'recommended_minutes' => 60,
        ], $overrides));
    }

    private function place(array $overrides = []): Place
    {
        static $sequence = 0;

        $sequence++;

        return Place::create(array_merge([
            'name' => 'Test Place '.$sequence,
            'slug' => 'test-stop-place-'.$sequence,
            'type' => Place::TYPE_FOOD,
            'province' => 'Benguet',
            'municipality' => 'Baguio City',
            'latitude' => 16.4120,
            'longitude' => 120.5960,
            'budget_level' => 'economy',
            'source_url' => 'https://example.test/stop-'.$sequence,
        ], $overrides));
    }

    private function item(array $overrides = []): ItineraryItem
    {
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

        return ItineraryItem::create(array_merge([
            'itinerary_day_id' => $day->id,
            'destination_id' => $this->destination()->id,
            'sort_order' => 1,
        ], $overrides));
    }
}
