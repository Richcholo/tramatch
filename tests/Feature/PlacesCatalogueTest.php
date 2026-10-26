<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\Place;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Day 1's schema and the two models built on it.
 *
 * Three kinds of test, and the split is deliberate:
 *
 *   - the migration guards, which exist because `add_carousel_fields_to_
 *     destinations_table` had to be rewritten after it was deleted from disk and
 *     the server still had the columns;
 *   - the refactor, which moved ~330 lines of opening-hours logic out of
 *     `Destination` into a shared trait. The ONLY evidence that was a pure move
 *     is that every existing caller still agrees, so these assert the trait
 *     answers identically AND that `Destination` and `Place` cannot diverge;
 *   - the new semantics, which are the parts with no prior test to lean on:
 *     a nullable price, four stop shapes, and per-tier cluster coverage.
 */
class PlacesCatalogueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ------------------------------------------------------------------
     * The migrations
     * ------------------------------------------------------------------
     */

    /**
     * THE SERVER SCENARIO THIS FILE WAS WRITTEN FOR: a database that already has
     * these columns, because an earlier version of the migration ran there and
     * was then deleted from disk. Laravel reads the new filename as unrecorded and
     * tries to add columns that are already there:
     *
     *     SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name
     *
     * which aborts `migrate` and leaves the server unable to deploy code that only
     * ever needed defaulted columns.
     *
     * So `up()` is called twice, which is exactly that situation, and it must be a
     * no-op rather than an exception. `down()` is called twice for the same
     * reason from the other side: an unguarded inverse of a guarded step fails on
     * exactly the environments where the guard mattered.
     *
     * Falsified by removing a `hasColumn()` guard or a `dropIfExists()`.
     */
    public function test_the_itinerary_item_migration_is_safe_where_the_columns_already_exist(): void
    {
        $migration = require database_path(
            'migrations/2026_10_14_091000_extend_itinerary_items_for_places.php'
        );

        // Already applied by RefreshDatabase, so this is the second run.
        $migration->up();

        foreach ([
            'item_type',
            'place_id',
            'route_status',
            'is_locked',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('itinerary_items', $column),
                'The second up() removed or corrupted '.$column.'.'
            );
        }

        $migration->down();

        // And a down() on a database that no longer has them.
        $migration->down();

        $this->assertFalse(
            Schema::hasColumn('itinerary_items', 'item_type'),
            'down() did not remove the columns it is responsible for.'
        );

        // Leave the schema as this test found it for the ones that run after.
        $migration->up();
    }

    /**
     * The same guard question for `places`, where the guard is `hasTable()`.
     *
     * Worth pinning separately because the two migrations guard differently: one
     * adds columns to a table that always existed, the other creates a table. A
     * `CREATE TABLE` with no guard is the more obvious shape of the failure.
     */
    public function test_the_places_migration_is_safe_where_the_table_already_exists(): void
    {
        $migration = require database_path(
            'migrations/2026_10_14_090000_create_places_table.php'
        );

        $migration->up();

        $this->assertTrue(Schema::hasTable('places'));

        $migration->down();

        $this->assertFalse(Schema::hasTable('places'));

        // And again on a database where it never existed, which is the far
        // likelier case for a migration that has never been deleted.
        $migration->down();

        $migration->up();

        $this->assertTrue(Schema::hasTable('places'));
    }

    public function test_every_column_the_itinerary_item_migration_adds_is_created(): void
    {
        foreach ([
            'item_type',
            'place_id',
            'custom_name',
            'custom_address',
            'custom_latitude',
            'custom_longitude',
            'route_distance_meters',
            'route_duration_minutes',
            'route_provider',
            'route_status',
            'is_locked',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('itinerary_items', $column),
                'itinerary_items.'.$column.' was not created.'
            );
        }
    }

    public function test_the_places_table_is_created_with_the_columns_the_catalogue_needs(): void
    {
        $this->assertTrue(Schema::hasTable('places'));

        foreach ([
            'slug',
            'type',
            'cluster',
            'province',
            'latitude',
            'longitude',
            'budget_level',
            'meal_slots',
            'price_min',
            'price_max',
            'price_basis',
            'source_url',
            'is_active',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('places', $column),
                'places.'.$column.' was not created.'
            );
        }
    }

    /**
     * A PRICES COLUMN MUST BE ABLE TO BE NULL, and this is the reason the whole
     * table is shaped the way it is.
     *
     * `destinations.estimated_cost` is `decimal default 0`, so "nobody verified
     * this" and "this is free" are the same stored value there. A hotel filed at 0
     * is summed into a trip total as free, and the app then asserts the trip fits
     * a budget. The fix is structural: NULL, and `Place::hasVerifiedPrice()`.
     *
     * Falsified by defaulting `price_min` to 0.
     */
    public function test_a_place_price_can_be_unknown_rather_than_zero(): void
    {
        $place = Place::create($this->placeAttributes(['price_min' => null, 'price_max' => null]));

        $fresh = $place->fresh();

        $this->assertNull($fresh->price_min);
        $this->assertFalse(
            $fresh->hasVerifiedPrice(),
            'An unpriced place must not report a price. Zero is a price; null is not.'
        );
        $this->assertSame('Price not listed', $fresh->priceLabel());
        $this->assertNull($fresh->priceMidpoint());
        $this->assertNull(
            $fresh->costFor(people: 4),
            'A place with no verified price must contribute nothing to a trip total, not zero.'
        );
    }

    /**
     * ------------------------------------------------------------------
     * The shared opening-hours behaviour
     * ------------------------------------------------------------------
     */

    /**
     * `Destination` and `Place` must not be able to disagree about what a window
     * means. They read the same trait, so this is not really testing the code --
     * it is pinning the decision that they SHARE it, which is the thing a later
     * contributor is most likely to undo by copy-pasting a method back into one
     * of them.
     *
     * Falsified by reimplementing `hoursForDay()` on `Place`.
     */
    public function test_a_place_and_a_destination_read_the_same_hours_rules(): void
    {
        $both = [
            'opening_time' => '09:00',
            'closing_time' => '18:00',
            'closed_days' => 'monday',
        ];

        $destination = $this->destination($both);
        $place = Place::create($this->placeAttributes($both));

        // Sunday, inside the window.
        $this->assertSame(
            ['open' => '09:00', 'close' => '18:00'],
            $destination->hoursForDay(now()->next('Sunday'))
        );
        $this->assertSame(
            $destination->hoursForDay(now()->next('Sunday')),
            $place->hoursForDay(now()->next('Sunday'))
        );

        // Monday, explicitly closed.
        $this->assertNull($destination->hoursForDay(now()->next('Monday')));
        $this->assertNull($place->hoursForDay(now()->next('Monday')));
        $this->assertSame(
            $destination->closedDaysLabel(),
            $place->closedDaysLabel()
        );

        $this->assertSame(Destination::daySlugs(), Place::daySlugs());
        $this->assertSame(Destination::HOURS_KINDS, Place::HOURS_KINDS);
    }

    /**
     * THE STATIC CALLS ARE STILL STATIC.
     *
     * `Destination::daySlugs()`, `Destination::formatTime()` and
     * `Destination::HOURS_*` are read as class members by the seeder, the crawler,
     * the admin Blade form and the tests. A trait serves them on the using class,
     * but nothing else in this file would notice if that stopped being true, and
     * the failure would be a fatal in the crawler rather than a test failure.
     *
     * Falsified by making the trait members non-static or private.
     */
    public function test_the_hours_api_is_still_reachable_statically_on_destination(): void
    {
        $this->assertSame(
            ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            Destination::daySlugs()
        );

        $this->assertSame('09:00', Destination::formatTime('09:00:00'));
        $this->assertNull(Destination::formatTime('99:99'));
        $this->assertContains(Destination::HOURS_REGISTRATION, Destination::HOURS_KINDS);
    }

    /**
     * A place with NO hours is `unknown`, not `closed`.
     *
     * The distinction is the whole reason `hasAnyHours()` exists on a model that
     * serves restaurants: telling a traveller a hotel with no hours data is shut is
     * a fabricated fact about somewhere they may be booking a room.
     */
    public function test_a_place_with_no_hours_is_unknown_and_not_closed(): void
    {
        $place = Place::create($this->placeAttributes([
            'opening_time' => null,
            'closing_time' => null,
            'closed_days' => null,
        ]));

        $this->assertFalse($place->hasAnyHours());
        $this->assertNull($place->hoursForDay(now()));
        $this->assertNull($place->formatHours());
    }

    /**
     * ------------------------------------------------------------------
     * Price basis -- the panel's "budget" question
     * ------------------------------------------------------------------
     */

    public function test_a_price_is_always_labelled_with_the_unit_it_is_a_price_of(): void
    {
        $band = ['price_min' => 250, 'price_max' => 1200];

        $this->assertSame(
            '₱250–₱1,200 a head, per meal',
            Place::create($this->placeAttributes([
                ...$band,
                'price_basis' => Place::PRICE_PER_PERSON_MEAL,
            ]))->priceLabel()
        );

        $this->assertSame(
            '₱4,500 per room, per night',
            Place::create($this->placeAttributes([
                'price_min' => 4500,
                'price_max' => 4500,
                'price_basis' => Place::PRICE_PER_ROOM_NIGHT,
            ]))->priceLabel()
        );

        // A band with no basis is a number nobody can act on, and saying so is
        // better than guessing which unit was meant.
        $this->assertSame(
            '₱250–₱1,200 (unit not recorded)',
            Place::create($this->placeAttributes([...$band, 'price_basis' => null]))->priceLabel()
        );
    }

    /**
     * A ROOM IS A ROOM. Multiplying a room rate by group size is how a four-person
     * trip is quoted at four times the actual hotel bill, and it is the specific
     * error that makes a budget figure indefensible.
     *
     * Falsified by scaling `per_room_night` by `$people`.
     */
    public function test_a_room_night_is_not_multiplied_by_the_number_of_travellers(): void
    {
        $hotel = Place::create($this->placeAttributes([
            'type' => Place::TYPE_LODGING,
            'price_min' => 4500,
            'price_max' => 4500,
            'price_basis' => Place::PRICE_PER_ROOM_NIGHT,
        ]));

        // Two people, one room, two nights.
        $this->assertSame(9000.0, $hotel->costFor(people: 2, nights: 2, rooms: 1));

        // Six people STILL one room, two nights: still 9,000. The traveller
        // decides how many rooms they need; this basis cannot make that decision.
        $this->assertSame(9000.0, $hotel->costFor(people: 6, nights: 2, rooms: 1));

        // And two rooms is 18,000, because rooms is the lever that exists.
        $this->assertSame(18000.0, $hotel->costFor(people: 6, nights: 2, rooms: 2));

        // Meals, by contrast, DO scale with the group.
        $restaurant = Place::create($this->placeAttributes([
            'price_min' => 300,
            'price_max' => 300,
            'price_basis' => Place::PRICE_PER_PERSON_MEAL,
        ]));

        $this->assertSame(1800.0, $restaurant->costFor(people: 6, nights: 2, rooms: 1));
    }

    public function test_a_meal_slot_that_is_not_a_real_meal_is_dropped_rather_than_stored(): void
    {
        $place = Place::create($this->placeAttributes([
            'meal_slots' => ['lunch', 'Lunch', 'brunch', 'midnight snack'],
        ]));

        $this->assertSame(
            ['lunch'],
            $place->normalisedMealSlots(),
            'Unrecognised slots must degrade the list, not render as a meal.'
        );
    }

    /**
     * An unlisted restaurant is not thereby unable to feed anyone.
     *
     * Missing `meal_slots` is a missing FIELD, and reading it as "serves nothing"
     * would leave a traveller with a restaurant they could not plan lunch at
     * anywhere. So an empty list answers true for every slot.
     */
    public function test_a_food_place_with_no_recorded_meal_slots_can_still_take_any_meal(): void
    {
        $place = Place::create($this->placeAttributes(['meal_slots' => null]));

        $this->assertSame([], $place->normalisedMealSlots());
        $this->assertTrue($place->servesMeal('lunch'));
        $this->assertTrue($place->servesMeal('dinner'));

        $restricted = Place::create($this->placeAttributes(['meal_slots' => ['breakfast']]));

        $this->assertTrue($restricted->servesMeal('breakfast'));
        $this->assertFalse($restricted->servesMeal('dinner'));
    }

    /**
     * ------------------------------------------------------------------
     * Cluster coverage -- "the locations are too small"
     * ------------------------------------------------------------------
     */

    /**
     * COVERAGE IS COUNTED PER BUDGET TIER, and this is the load-bearing detail of
     * the whole feature.
     *
     * Each tier's number is what a traveller on that tier can actually plan
     * with. Tiers are a CEILING (ADR 0005): an economy traveller can use only
     * economy rows, a premium traveller all three. A single summed coverage
     * number would report the strongest cluster while hiding that an economy
     * traveller can eat in far fewer of its places than the total suggests.
     *
     * Falsified by returning one total per province, and by counting rows by
     * exact tier — which would print 1 for premium while the deck deals it all
     * three.
     */
    public function test_cluster_coverage_is_reported_per_budget_tier_not_as_a_total(): void
    {
        // Two economy restaurants, one premium one.
        Place::create($this->placeAttributes(['budget_level' => 'economy']));
        Place::create($this->placeAttributes(['budget_level' => 'economy']));
        Place::create($this->placeAttributes(['budget_level' => 'premium']));

        $coverage = Place::clusterCoverage('Benguet');

        $this->assertSame(2, $coverage['economy']['food']);
        $this->assertSame(
            2,
            $coverage['mid-range']['food'],
            'Mid-range can afford the economy rows, so it inherits them.'
        );
        $this->assertSame(
            3,
            $coverage['premium']['food'],
            'Premium can afford all three tiers, which is what its ceiling means.'
        );
    }

    /**
     * A place filed under a tier nobody declared is counted NOWHERE.
     *
     * Counting it into the nearest tier would invent coverage that does not exist,
     * and this report is the thing that decides whether a cluster is ready.
     */
    public function test_a_place_in_an_undeclared_tier_is_counted_nowhere(): void
    {
        Place::create($this->placeAttributes(['budget_level' => 'luxury']));

        $coverage = Place::clusterCoverage('Benguet');

        $this->assertSame(0, $coverage['economy']['food']);
        $this->assertSame(0, $coverage['mid-range']['food']);
        $this->assertSame(0, $coverage['premium']['food']);
    }

    /**
     * An ARCHIVED place is not coverage. `is_active = false` is how this project
     * retires a row and it is what every public listing reads, so counting a
     * retired restaurant would report a cluster as ready while a traveller sees
     * three restaurants instead of six.
     */
    public function test_an_archived_place_does_not_count_towards_coverage(): void
    {
        Place::create($this->placeAttributes(['is_active' => false]));

        $this->assertSame(0, Place::clusterCoverage('Benguet')['economy']['food']);
    }

    /**
     * Readiness reports the GAPS, not a bare false.
     *
     * "Is this cluster ready" is always "for which tiers, and what is missing", and
     * a boolean throws that away. Lodging is the binding constraint: food can be
     * left unpriced and a trip still works, but a cluster with nowhere to sleep
     * cannot be planned at all.
     */
    public function test_readiness_names_what_is_missing_rather_than_only_saying_no(): void
    {
        Place::create($this->placeAttributes([
            'budget_level' => 'economy',
            'price_min' => 250,
            'price_basis' => Place::PRICE_PER_PERSON_MEAL,
        ]));

        $readiness = Place::clusterReadiness('Benguet', 'economy');

        $this->assertFalse($readiness['ready']);
        $this->assertStringContainsString('of 6 needed', $readiness['gaps']['food']);
        $this->assertStringContainsString('of 3 needed', $readiness['gaps']['lodging']);
    }

    /**
     * A ready cluster is a real one, and this proves the check can pass rather
     * than only fail -- a readiness test that has never seen a ready cluster is
     * indistinguishable from one that always returns false.
     */
    public function test_a_fully_stocked_cluster_reads_as_ready(): void
    {
        for ($i = 0; $i < Place::CLUSTER_MINIMUMS['food']; $i++) {
            Place::create($this->placeAttributes([
                'type' => Place::TYPE_FOOD,
                'price_min' => 250,
                'price_basis' => Place::PRICE_PER_PERSON_MEAL,
            ]));
        }

        for ($i = 0; $i < Place::CLUSTER_MINIMUMS['lodging']; $i++) {
            Place::create($this->placeAttributes([
                'type' => Place::TYPE_LODGING,
                'price_min' => 4500,
                'price_basis' => Place::PRICE_PER_ROOM_NIGHT,
            ]));
        }

        $readiness = Place::clusterReadiness('Benguet', 'economy');

        $this->assertTrue($readiness['ready'], json_encode($readiness['gaps']));
        $this->assertSame([], $readiness['gaps']);
    }

    /**
     * ------------------------------------------------------------------
     * The pilot clusters
     * ------------------------------------------------------------------
     */

    /**
     * THE PANEL SAID "THE LOCATIONS ARE TOO SMALL", and the answer is dense
     * areas rather than scattered ones. This pins WHICH ones, and the reason
     * for each is in `Place::CLUSTERS`.
     *
     * It reads the CSV, NOT the database. The catalogue's source of truth is
     * `database/data/luzon-locations-clean.csv`, which is committed; a test that
     * seeded fixtures of its own would only be checking its own fixtures, and
     * would keep passing if the real catalogue lost Baguio.
     *
     * Both halves of the density argument are checked: enough destinations in the
     * province at all, and at least two of them in the SAME municipality -- so a
     * day in a cluster is not four hours of road.
     */
    public function test_the_three_pilot_clusters_point_at_provinces_the_catalogue_actually_has(): void
    {
        $this->assertSame(
            ['baguio', 'antipolo', 'paoay', 'vigan', 'sagada'],
            array_keys(Place::CLUSTERS),
            'The pilot cluster set changed. Re-read the CLUSTERS docblock: it argues for '
            .'these three on grounds of density, and that argument has to be '
            .'re-made rather than inherited.'
        );

        [$header, $rows] = $this->readCatalogueCsv();

        $provinceIndex = $this->columnIndex($header, 'province');
        $municipalityIndex = $this->columnIndex($header, 'municipality');

        foreach (Place::CLUSTERS as $cluster => $definition) {
            $inProvince = array_filter(
                $rows,
                fn (array $row): bool => ($row[$provinceIndex] ?? null) === $definition['province']
            );

            $this->assertGreaterThanOrEqual(
                4,
                count($inProvince),
                'Cluster "'.$cluster.'" targets '.$definition['province'].', which has only '
                .count($inProvince).' destinations in the catalogue.'
            );

            $perMunicipality = array_count_values(array_map(
                fn (array $row): string => (string) ($row[$municipalityIndex] ?? ''),
                $inProvince
            ));

            $densest = max($perMunicipality);

            $this->assertGreaterThanOrEqual(
                2,
                $densest,
                'Cluster "'.$cluster.'" has no municipality with more than one destination, '
                .'so a trip there is one day out rather than a place to stay.'
            );
        }
    }

    /**
     * The exact-match budget filter makes a province-wide total MISLEADING, and
     * this is the arithmetic that makes the point concrete rather than asserted.
     *
     * Benguet has eight destinations, which reads as the strongest province in the
     * catalogue. But the tiers are 5 economy / 3 mid-range, and every service in
     * this app filters on EQUALITY, so a mid-range traveller can see three of the
     * eight and an economy traveller five. This is why `clusterCoverage()` reports
     * per tier, and it is measured from the real CSV.
     */
    public function test_a_province_wide_count_overstates_coverage_under_an_exact_match_filter(): void
    {
        [$header, $rows] = $this->readCatalogueCsv();

        $provinceIndex = $this->columnIndex($header, 'province');
        $budgetIndex = $this->columnIndex($header, 'budget_level');

        $benguet = array_values(array_filter(
            $rows,
            fn (array $row): bool => ($row[$provinceIndex] ?? null) === 'Benguet'
        ));

        $byTier = array_count_values(array_map(
            fn (array $row): string => (string) ($row[$budgetIndex] ?? ''),
            $benguet
        ));

        $this->assertGreaterThan(
            max($byTier),
            count($benguet),
            'If the tiers were already even, this premise would no longer hold and the '
            .'per-tier coverage report would have to be re-argued.'
        );

        $this->assertLessThan(
            count($benguet),
            $byTier['mid-range'],
            'A mid-range traveller sees fewer than the province total under an exact match.'
        );
    }

    /**
     * ------------------------------------------------------------------
     * The four stop shapes on an itinerary item
     * ------------------------------------------------------------------
     */

    /**
     * EVERY STOP THAT EXISTED BEFORE THIS MIGRATION IS STILL AN ATTRACTION.
     *
     * `item_type` defaults to 'attraction' so no backfill was needed, and this is
     * the assertion that the default was right: a row inserted with no
     * `item_type` must not read as null and fall through every reader below.
     *
     * Falsified by changing the column default.
     */
    public function test_a_stop_with_no_explicit_type_is_an_attraction(): void
    {
        // Raw insert on purpose. Eloquent always sends `item_type` because the
        // model declares it in `$attributes`, so an Eloquent create could never
        // reach the database default -- and the database default is the thing that
        // protects the 435 rows that already exist.
        $day = ItineraryDay::create([
            'itinerary_id' => Itinerary::create([
                'user_id' => User::factory()->create()->id,
                'title' => 'A trip',
                'area' => 'Benguet',
                'budget_level' => 'economy',
                'trip_duration_days' => 1,
            ])->id,
            'day_number' => 1,
        ]);

        $id = DB::table('itinerary_items')->insertGetId([
            'itinerary_day_id' => $day->id,
            'destination_id' => $this->destination()->id,
            'sort_order' => 1,
        ]);

        $item = ItineraryItem::find($id);

        $this->assertSame(
            ItineraryItem::TYPE_ATTRACTION,
            $item->item_type,
            'A row inserted without item_type must fall back to the column default.'
        );
        $this->assertTrue($item->isAttraction());
        $this->assertSame(
            ItineraryItem::ROUTE_UNRESOLVED,
            $item->route_status,
            'route_status must default to unresolved, never to resolved.'
        );
    }

    /**
     * `destination_id` IS NULLABLE, and without this migration a meal, a hotel and
     * a traveller's own booking cannot be saved at all.
     *
     * The bug is that `repair_itinerary_tables` looks like it made the column
     * nullable but its `hasColumn()` guard skips it on any database that ran the
     * create migration first, so it has never done anything. This is the test that
     * would have caught that, and it asserts the nullability rather than the
     * column's existence.
     *
     * Falsified by reverting the corrective migration.
     */
    public function test_an_itinerary_stop_can_exist_without_a_destination(): void
    {
        $nullable = collect(Schema::getColumns('itinerary_items'))
            ->firstWhere('name', 'destination_id')['nullable'] ?? false;

        $this->assertTrue(
            (bool) $nullable,
            'destination_id is NOT NULL, so a meal stop or a custom entry cannot be saved.'
        );

        $item = $this->item([
            'item_type' => ItineraryItem::TYPE_MEAL,
            'destination_id' => null,
            'place_id' => Place::create($this->placeAttributes())->id,
        ]);

        $this->assertNull($item->fresh()->destination_id);
    }

    public function test_a_meal_stop_reads_the_places_name_and_not_the_destinations(): void
    {
        $item = $this->item([
            'item_type' => ItineraryItem::TYPE_MEAL,
            'destination_id' => null,
            'place_id' => Place::create($this->placeAttributes(['name' => 'Cafe Oro']))->id,
        ]);

        $this->assertSame('Cafe Oro', $item->placeName());
        $this->assertTrue($item->isMeal());
    }

    /**
     * A traveller's OWN entry has no catalogue row at all, which is the point of
     * it: they already have a hotel booked.
     *
     * The name comes from `custom_name`, and when it is missing the stop says so
     * rather than rendering blank.
     */
    public function test_a_custom_stop_is_named_from_what_the_traveller_typed(): void
    {
        $item = $this->item([
            'item_type' => ItineraryItem::TYPE_CUSTOM,
            'destination_id' => null,
            'custom_name' => 'Our booking at the Mountain Lodge',
        ]);

        $this->assertSame('Our booking at the Mountain Lodge', $item->placeName());

        $unnamed = $this->item([
            'item_type' => ItineraryItem::TYPE_CUSTOM,
            'destination_id' => null,
            'custom_name' => null,
        ]);

        $this->assertSame('Your own stop', $unnamed->placeName());
    }

    /**
     * HALF A POSITION IS NOT A POSITION.
     *
     * A custom entry typed with an address and no pin has no coordinates, and the
     * honest outcome is a leg the routing step cannot resolve -- not a coordinate
     * guessed from the street name, which would put a map pin and a route on a
     * place we cannot locate. A caller checking only latitude would put that pin at
     * 0 degrees longitude.
     */
    public function test_a_custom_stop_without_a_pin_cannot_be_routed_from(): void
    {
        $item = $this->item([
            'item_type' => ItineraryItem::TYPE_CUSTOM,
            'destination_id' => null,
            'custom_address' => 'Session Road, Baguio',
            'custom_latitude' => 16.4120,
            'custom_longitude' => null,
        ]);

        $this->assertSame(16.4120, $item->latitude());
        $this->assertNull($item->longitude());
        $this->assertFalse(
            $item->hasKnownLocation(),
            'One coordinate is not a position, and a half-known one must not be routed.'
        );

        $pinned = $this->item([
            'item_type' => ItineraryItem::TYPE_CUSTOM,
            'destination_id' => null,
            'custom_latitude' => 16.4120,
            'custom_longitude' => 120.5960,
        ]);

        $this->assertTrue($pinned->hasKnownLocation());
    }

    /**
     * A PLACE DELETED OUTRIGHT MUST NOT TAKE THE STOP WITH IT.
     *
     * `place_id` is `nullOnDelete` while `destination_id` cascades, and the
     * difference is deliberate: a restaurant closes and an admin retires the row,
     * and cascading would silently delete stops out of somebody's saved trip.
     * That is the exact loss `ItineraryEditor::removeDay()` refuses to make. The
     * stop survives, keeps its type, and says plainly that the place is gone.
     */
    public function test_deleting_a_place_leaves_the_stop_and_says_it_is_gone(): void
    {
        $place = Place::create($this->placeAttributes());

        $item = $this->item([
            'item_type' => ItineraryItem::TYPE_MEAL,
            'destination_id' => null,
            'place_id' => $place->id,
        ]);

        $place->delete();

        $survivor = $item->fresh();

        $this->assertNotNull($survivor, 'The stop was deleted along with the place.');
        $this->assertNull($survivor->place_id);
        $this->assertTrue($survivor->isMeal(), 'The stop still knows it was a meal.');
        $this->assertSame(
            'Place no longer in the catalogue',
            $survivor->placeName(),
            'It must not render blank or throw.'
        );
    }

    /**
     * AN UNRESOLVED LEG MUST NOT READ AS ZERO MINUTES.
     *
     * `route_status` defaults to 'unresolved' and `routeSummary()` returns null,
     * deliberately NOT falling back to `travel_minutes_from_previous` -- that
     * column is the flat 45-minute estimate every stop in this app used to carry,
     * and substituting it is the exact claim the panel called out.
     *
     * Falsified by making `routeSummary()` fall back to the estimate.
     */
    public function test_an_unresolved_leg_reports_nothing_rather_than_a_zero_minute_estimate(): void
    {
        $item = $this->item([
            'route_status' => ItineraryItem::ROUTE_UNRESOLVED,
            'travel_minutes_from_previous' => 45,
            'route_distance_meters' => null,
            'route_duration_minutes' => null,
        ]);

        $this->assertSame(ItineraryItem::ROUTE_UNRESOLVED, $item->fresh()->route_status);
        $this->assertNull(
            $item->routeSummary(),
            'The flat schedule estimate must never stand in for a routed distance.'
        );

        $routed = $this->item([
            'route_status' => ItineraryItem::ROUTE_RESOLVED,
            'route_distance_meters' => 1450,
            'route_duration_minutes' => 7,
        ]);

        $this->assertSame('1.5 km · 7 min', $routed->routeSummary());
    }

    /**
     * `resolved` WITH NO NUMBERS IS NOT A ROUTE. Day 6-7 writes the status and
     * both figures together or writes neither, and this pins the reader that
     * refuses the half-written state rather than formatting a null.
     */
    public function test_a_resolved_leg_without_figures_reports_nothing(): void
    {
        $item = $this->item([
            'route_status' => ItineraryItem::ROUTE_RESOLVED,
            'route_distance_meters' => null,
            'route_duration_minutes' => null,
        ]);

        $this->assertNull($item->routeSummary());
    }

    /**
     * Durations read the same way in the item, the map tooltip and the PDF.
     * Three rounding rules for one journey is how a trip prints 75 min here and
     * "1 h 15" there.
     */
    public function test_durations_round_once_and_are_shared_by_every_surface(): void
    {
        $this->assertSame('45 min', ItineraryItem::durationLabel(45));
        $this->assertSame('1 h', ItineraryItem::durationLabel(60));
        $this->assertSame('1 h 20 min', ItineraryItem::durationLabel(80));
        $this->assertSame('2 h 5 min', ItineraryItem::durationLabel(125));
    }

    /**
     * ------------------------------------------------------------------
     * Nothing is callable that is not testable and budgeted
     * ------------------------------------------------------------------
     */

    /**
     * THE DAY-1 GUARD HAS DONE ITS JOB. It used to refuse a planning API on
     * the grounds that no client, no test and no quota budget existed; the
     * itinerary suggest has since shipped all three (client, suite, one-request
     * budget), so the guard is rewritten rather than deleted: vendor names no
     * longer belong in route URIs at all, and the one endpoint that does exist
     * must be there under the name that says what it does.
     */
    public function test_the_assist_is_the_only_planning_endpoint_and_everything_is_named(): void
    {
        $vendorNamed = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'ai-plan')
                || str_contains($route->uri(), 'gemini')
                || str_contains($route->uri(), 'generate-with-ai'));

        $this->assertTrue(
            $vendorNamed->isEmpty(),
            'The client exists, so any NEW endpoint should be named '
            .'for what it does, not for the vendor: '.$vendorNamed->implode(', ')
        );

        $this->assertNotNull(Route::getRoutes()->getByName('itineraries.llm-suggest'));
    }

    /**
     * Neither key may be readable from the browser. This walks the routes that
     * render a page and asserts the Gemini key never appears in the response.
     */
    public function test_the_gemini_key_never_reaches_a_rendered_page(): void
    {
        config(['services.gemini.key' => 'sk-test-must-never-be-rendered']);

        $response = $this->actingAs(User::factory()->create())->get('/destinations');

        $response->assertOk();
        $this->assertStringNotContainsString(
            'sk-test-must-never-be-rendered',
            $response->getContent(),
            'A Gemini key reached the browser. In shipped JavaScript it is a public key.'
        );
    }

    /**
     * Config/planning.php states the split between the model and the server. This
     * pins it as data, because the claim is made in a demo and the client is the
     * code that could quietly break it.
     *
     * WRITTEN FOR THE ITINERARY SUGGEST. The model chooses selection and one
     * sentence per pick; the server owns membership of the catalogue and the
     * trip's province, and the only budget is ONE request — no repair loop
     * anywhere to cap.
     */
    public function test_the_model_is_recorded_as_choosing_while_the_server_checks(): void
    {
        $this->assertSame(
            ['selection', 'explanation'],
            config('planning.authority.model_decides')
        );

        foreach (['membership', 'coordinates', 'price', 'opening_hours', 'distance'] as $field) {
            $this->assertContains(
                $field,
                config('planning.authority.server_decides'),
                $field.' is server-owned and must not be read out of a model reply.'
            );
        }

        $this->assertSame('driving', config('planning.transport.profile'));
    }

    // -------------------------------------------------------------- helpers

    /**
     * The catalogue's source of truth, read from the CSV rather than from the
     * database.
     *
     * `database/data/luzon-locations-clean.csv` is committed and is what
     * `LuzonLocationsCsvSeeder` loads, so a test that seeds its own rows would be
     * checking its own fixtures and would keep passing if the real catalogue lost
     * Baguio.
     *
     * Returns `[$header, $rows]`. The header is returned SEPARATELY and not as
     * `$rows[0]`: an earlier version sliced the header off with `array_slice(..., 1)`
     * and then read `$rows[0]` as the header, which made `array_search` return
     * false and failed the test with "The CSV lost its province column" -- a
     * diagnostic that pointed at the CSV when the bug was in the reader.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
     */
    private function readCatalogueCsv(): array
    {
        $csv = database_path('data/luzon-locations-clean.csv');

        $this->assertFileExists($csv);

        $lines = file($csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertNotEmpty($lines, 'The catalogue CSV is empty.');

        $parsed = array_map('str_getcsv', $lines);

        $header = array_map('trim', array_shift($parsed));

        return [$header, array_map(
            fn (array $row): array => array_pad($row, count($header), null),
            $parsed
        )];
    }

    /**
     * A column's position, by NAME.
     *
     * Never a hard-coded index. `AGENTS.md` records what a positional reader cost
     * this repo: writing four new columns with `array_merge($header, [...])` while
     * still reading by index produced a 31-column file whose `name` column held
     * source labels and notes, and re-seeding created seven junk destinations.
     *
     * @param  array<int, string>  $header
     */
    private function columnIndex(array $header, string $column): int
    {
        $index = array_search($column, $header, true);

        $this->assertIsInt(
            $index,
            'The catalogue CSV has no "'.$column.'" column. Header: '.implode(', ', $header)
        );

        return $index;
    }

    private function placeAttributes(array $overrides = []): array
    {
        static $sequence = 0;

        $sequence++;

        return array_merge([
            'name' => 'Test Place '.$sequence,
            'slug' => 'test-place-'.$sequence,
            'type' => Place::TYPE_FOOD,
            'province' => 'Benguet',
            'municipality' => 'Baguio City',
            'latitude' => 16.4120,
            'longitude' => 120.5960,
            'budget_level' => 'economy',
            'source_url' => 'https://example.test/place/'.$sequence,
        ], $overrides);
    }

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

    private function item(array $overrides = []): ItineraryItem
    {
        $user = User::factory()->create();

        $itinerary = Itinerary::create([
            'user_id' => $user->id,
            'title' => 'A trip',
            'area' => 'Benguet',
            // NOT NULL in the create migration. The `repair_itinerary_tables`
            // step guards on hasColumn and therefore leaves it as the create
            // migration made it, so a helper that omits it fails on the test
            // database and works on MySQL. Worth knowing.
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