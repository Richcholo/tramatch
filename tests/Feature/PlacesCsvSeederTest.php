<?php

namespace Tests\Feature;

use App\Models\Place;
use Database\Seeders\PlacesCsvSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The places seeder.
 *
 * This file exists because AGENTS.md records what happened the last time this
 * catalogue was rewritten without guards. A 31-column CSV whose `name` column
 * held source labels created seven junk destinations, and the repair that
 * followed silently lost eight legitimate ones because a regex character class
 * did not cover accented names. Both failures are allowed for here, deliberately,
 * and every guard below is one of them.
 */
class PlacesCsvSeederTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * A TEMPORARY CSV, pointed at by config, rather than the repository's own
         * `places.csv`.
         *
         * The first version of this file copied `places.csv` to a `.bak` and
         * restored it in tearDown(). That works right up until a test fatals, and
         * then tearDown() never runs: a missing-method fatal in the seeder left the
         * repository's catalogue file holding a fabricated "Merienda Place" row,
         * which the next run read as real data and asserted against.
         *
         * A test that cannot write the file it is testing cannot corrupt it.
         */
        $this->path = sys_get_temp_dir().'/tramatch-places-test-'.getmypid().'.csv';

        config(['data.places_csv' => $this->path]);
    }

    protected function tearDown(): void
    {
        if (File::exists($this->path)) {
            File::delete($this->path);
        }

        parent::tearDown();
    }

    /**
     * THE SHIPPED FILE CARRIES THE RESEARCHED CATALOGUE: 35 food and lodging
     * places across the three pilot clusters, added 2026-10-14.
     *
     * It used to be a header with no rows, which was the correct state while
     * curation had not started — a seed file full of plausible-looking names
     * would have been fabricated businesses. These rows are the opposite: each
     * was researched against a real source, and the ones that could not be
     * verified (Go Hotels Baguio, which has no Baguio branch; Macy's Diner and
     * Emilio's, which do not exist; Casa de Robles, which has no published
     * coordinates) were replaced or left out rather than invented.
     *
     * This reads the REAL file, read-only, because the question is about what
     * ships. Every other test in this class runs against a temporary copy.
     */
    public function test_the_shipped_catalogue_carries_the_researched_places(): void
    {
        $lines = array_filter(
            file(database_path('data/places.csv'), FILE_IGNORE_NEW_LINES),
            fn ($line) => trim($line) !== ''
        );

        // Header plus 36 researched rows. If this count changed, a row was added
        // or removed — check the CSV diff, not just this number.
        $this->assertCount(37, $lines);
    }

    /**
     * EVERY SHIPPED ROW HAS A SOURCE, because a restaurant with no traceable
     * page behind it does not get an entry.
     */
    public function test_every_shipped_place_row_carries_a_source(): void
    {
        $handle = fopen(database_path('data/places.csv'), 'r');
        $raw = fgetcsv($handle);

        $headers = array_map(fn ($header) => Str::slug((string) $header), $raw);

        $sourceIndex = array_search('source-url', $headers, true);
        $nameIndex = array_search('name', $headers, true);

        $this->assertIsInt($sourceIndex);
        $this->assertIsInt($nameIndex);

        $unsourced = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (trim((string) ($row[$sourceIndex] ?? '')) === '') {
                $unsourced[] = $row[$nameIndex] ?? '(unnamed)';
            }
        }

        fclose($handle);

        $this->assertSame(
            [],
            $unsourced,
            'These rows have no source and must not ship: '.implode(', ', $unsourced)
        );
    }

    public function test_the_shipped_header_carries_every_column_the_seeder_requires(): void
    {
        $handle = fopen(database_path('data/places.csv'), 'r');
        $raw = fgetcsv($handle);
        fclose($handle);

        $headers = array_map(fn ($header) => Str::slug((string) $header), $raw);

        foreach ([
            'name',
            'type',
            'province',
            'latitude',
            'longitude',
            'budget-level',
            'source-url',
        ] as $column) {
            $this->assertContains(
                $column,
                $headers,
                'places.csv has no "'.$column.'" column, so the seeder would refuse to run.'
            );
        }

        // And a header with no rows at all seeds to nothing rather than failing.
        File::put($this->path, implode(',', $this->rowHeader())."\n");

        $this->runSeeder();

        $this->assertSame(0, Place::count());
    }

    public function test_a_sourced_row_is_imported(): void
    {
        $this->writeCsv([
            $this->row([
                'name' => 'Cafe Oro',
                'type' => 'food',
                'province' => 'Benguet',
                'latitude' => '16.4120',
                'longitude' => '120.5960',
                'source-url' => 'https://example.test/cafe-oro',
            ]),
        ]);
        $this->runSeeder();

        $place = Place::first();

        $this->assertNotNull($place);
        $this->assertSame('Cafe Oro', $place->name);
        $this->assertSame('cafe-oro', $place->slug);
        $this->assertSame(Place::TYPE_FOOD, $place->type);
        $this->assertSame('https://example.test/cafe-oro', $place->source_url);
    }

    /**
     * AN UNSOURCED ROW IS REJECTED, NOT IMPORTED.
     *
     * `places.source_url` is NOT NULL, so the row cannot be stored anyway -- but
     * letting it vanish silently is how a thirty-row CSV becomes a twelve-row
     * catalogue with nobody noticing which eighteen went missing.
     */
    public function test_a_row_without_a_source_is_rejected(): void
    {
        $this->writeCsv([
            $this->row([
                'name' => 'Anonymous Restaurant',
                'type' => 'food',
                'province' => 'Benguet',
                'latitude' => '16.4120',
                'longitude' => '120.5960',
                'source-url' => '',
            ]),
            $this->row([
                'name' => 'Sourced Restaurant',
                'type' => 'food',
                'province' => 'Benguet',
                'latitude' => '16.4120',
                'longitude' => '120.5960',
                'source-url' => 'https://example.test/sourced',
            ]),
        ]);
        $this->runSeeder();

        $this->assertSame(1, Place::count());
        $this->assertSame('Sourced Restaurant', Place::first()->name);
    }

    /**
     * A BLANK PRICE IS NULL AND NOT ZERO, and this is the whole reason the
     * column is nullable.
     *
     * `LuzonLocationsCsvSeeder` substitutes a `placeholderCost()` figure derived
     * from the budget tier when `estimated-cost` is blank. That is acceptable for
     * an attraction with a published fee and indefensible for a hotel: a room
     * filed at zero sums into a trip total as free, and the app then claims the
     * trip fits a budget.
     *
     * Falsified by reintroducing `placeholderCost()` here.
     */
    public function test_a_blank_price_is_unknown_and_is_never_guessed_from_the_tier(): void
    {
        $this->writeCsv([
            $this->row([
                'name' => 'Unpriced Hotel',
                'type' => 'lodging',
                'province' => 'Benguet',
                'latitude' => '16.4120',
                'longitude' => '120.5960',
                'budget-level' => 'premium',
                'price-min' => '',
                'price-max' => '',
                'source-url' => 'https://example.test/unpriced',
            ]),
        ]);
        $this->runSeeder();

        $hotel = Place::first();

        $this->assertNull($hotel->price_min, 'A premium hotel must not be given a price.');
        $this->assertNull($hotel->price_max);
        $this->assertNull($hotel->price_basis);
        $this->assertFalse($hotel->hasVerifiedPrice());
        $this->assertSame('Price not listed', $hotel->priceLabel());
        $this->assertNull($hotel->costFor(2, 2, 1));
    }

    /**
     * A BASIS WITH NO PRICE IS DROPPED, because a unit with no figure attached
     * invites a later caller to multiply nothing by a group size.
     */
    public function test_a_price_basis_without_a_price_is_not_stored(): void
    {
        $this->writeCsv([
            $this->row([
                'name' => 'Basis Only',
                'type' => 'lodging',
                'province' => 'Benguet',
                'latitude' => '16.4120',
                'longitude' => '120.5960',
                'price-min' => '',
                'price-basis' => 'per_room_night',
                'source-url' => 'https://example.test/basis-only',
            ]),
        ]);
        $this->runSeeder();

        $this->assertNull(Place::first()->price_basis);
    }

    public function test_a_price_band_is_read_with_its_peso_sign_and_thousands_separator(): void
    {
        $this->writeCsv([
            $this->row([
                'name' => 'Room Rates',
                'type' => 'lodging',
                'province' => 'Benguet',
                'latitude' => '16.4120',
                'longitude' => '120.5960',
                'price-min' => '₱4,500',
                'price-max' => '6,000',
                'price-basis' => 'per room night',
                'source-url' => 'https://example.test/rates',
            ]),
        ]);
        $this->runSeeder();

        $place = Place::first();

        $this->assertSame(4500.0, (float) $place->price_min);
        $this->assertSame(6000.0, (float) $place->price_max);
        $this->assertSame(Place::PRICE_PER_ROOM_NIGHT, $place->price_basis);
        $this->assertSame('₱4,500–₱6,000 per room, per night', $place->priceLabel());
    }

    /**
     * A BAND THAT RUNS BACKWARDS IS SWAPPED, NOT PUBLISHED.
     *
     * A restaurant's range running minimum-to-maximum matters: publishing 1200 as
     * the cheapest thing it sells is a wrong number presented confidently.
     */
    public function test_a_reversed_price_band_is_swapped_rather_than_rejected(): void
    {
        $this->writeCsv([
            $this->row([
                'name' => 'Reversed Band',
                'type' => 'food',
                'province' => 'Benguet',
                'latitude' => '16.4120',
                'longitude' => '120.5960',
                'price-min' => '1200',
                'price-max' => '250',
                'price-basis' => 'per_person_meal',
                'source-url' => 'https://example.test/reversed',
            ]),
        ]);
        $this->runSeeder();

        $place = Place::first();

        $this->assertSame(250.0, (float) $place->price_min);
        $this->assertSame(1200.0, (float) $place->price_max);
    }

    /**
     * ZERO COORDINATES ARE NULL ISLAND, NOT A PLACE IN THE PHILIPPINES.
     *
     * A missing cell parsing to `0.0` is how a restaurant ends up with a map pin
     * in the Gulf of Guinea that a routing provider happily routes to.
     */
    public function test_blank_or_zero_coordinates_are_rejected(): void
    {
        $this->writeCsv([
            $this->row([
                'name' => 'Null Island',
                'province' => 'Benguet',
                'latitude' => '0',
                'longitude' => '0',
                'source-url' => 'https://example.test/null-island',
            ]),
            $this->row([
                'name' => 'No Coordinates',
                'province' => 'Benguet',
                'latitude' => '',
                'longitude' => '',
                'source-url' => 'https://example.test/no-coords',
            ]),
            $this->row([
                'name' => 'Real Place',
                'province' => 'Benguet',
                'latitude' => '16.4120',
                'longitude' => '120.5960',
                'source-url' => 'https://example.test/real',
            ]),
        ]);
        $this->runSeeder();

        $this->assertSame(1, Place::count());
        $this->assertSame('Real Place', Place::first()->name);
    }

    /**
     * AN UNRECOGNISED TYPE IS REJECTED, NOT DEFAULTED.
     *
     * Defaulting to `food` would let a typo file a shop as a restaurant, and it
     * would do so silently and every time.
     */
    public function test_an_unrecognised_type_is_rejected_rather_than_defaulted(): void
    {
        $this->writeCsv([
            $this->row([
                'name' => 'Mystery Venue',
                'type' => 'shopping mall',
                'province' => 'Benguet',
                'latitude' => '16.4120',
                'longitude' => '120.5960',
                'source-url' => 'https://example.test/mystery',
            ]),
        ]);
        $this->runSeeder();

        $this->assertSame(0, Place::count());
    }

    public function test_a_meal_slot_that_is_not_a_real_meal_is_dropped(): void
    {
        $this->writeCsv([
            $this->row([
                'name' => 'Slots Cafe',
                'type' => 'food',
                'province' => 'Benguet',
                'latitude' => '16.4120',
                'longitude' => '120.5960',
                'meal-slots' => 'Lunch, dinner, midnight snack',
                'source-url' => 'https://example.test/slots',
            ]),
        ]);
        $this->runSeeder();

        $this->assertSame(['lunch', 'dinner'], Place::first()->normalisedMealSlots());
    }

    /**
     * A MEAL SLOT LIST IS A MEAL SLOT LIST, and `merienda` is in it.
     *
     * An earlier list of meal labels left the Filipino afternoon snack out,
     * which is what made it read as imported rather than local.
     */
    public function test_merienda_is_an_accepted_meal_slot(): void
    {
        $this->writeCsv([
            $this->row([
                'name' => 'Merienda Place',
                'type' => 'food',
                'province' => 'Benguet',
                'latitude' => '16.4120',
                'longitude' => '120.5960',
                'meal-slots' => 'merienda',
                'source-url' => 'https://example.test/merienda',
            ]),
        ]);
        $this->runSeeder();

        $place = Place::first();

        $this->assertSame(['merienda'], $place->normalisedMealSlots());
        $this->assertTrue($place->servesMeal('merienda'));
    }

    /**
     * AN ACCENTED NAME SURVIVES THE ROUND TRIP.
     *
     * AGENTS.md: a repair script once dropped eight legitimate rows including
     * every accented name, because the character class did not cover them. This
     * asserts `Pintô` and `Cape Engaño` come through intact and keep their slug.
     */
    public function test_an_accented_name_survives_the_round_trip(): void
    {
        $this->writeCsv([
            $this->row([
                'name' => 'Pintô Art Museum Cafe',
                'type' => 'food',
                'province' => 'Rizal',
                'latitude' => '14.5960',
                'longitude' => '121.1900',
                'source-url' => 'https://example.test/pinto',
            ]),
            $this->row([
                'name' => 'Cape Engaño View House',
                'type' => 'lodging',
                'province' => 'Cagayan',
                'latitude' => '18.5000',
                'longitude' => '121.6000',
                'source-url' => 'https://example.test/engano',
            ]),
        ]);
        $this->runSeeder();

        $names = Place::orderBy('id')->pluck('name');

        $this->assertContains('Pintô Art Museum Cafe', $names);
        $this->assertContains('Cape Engaño View House', $names);

        $this->assertNotNull(
            Place::where('slug', 'pinto-art-museum-cafe')->first(),
            'Str::slug dropped the accent and the row could no longer be matched on re-seed.'
        );
    }

    /**
     * RE-SEEDING IS IDEMPOTENT, matched on slug -- the destinations seeder's rule,
     * because a junk slug CREATES A NEW ROW rather than corrupting a good one.
     */
    public function test_re_seeding_updates_the_same_row_rather_than_duplicating_it(): void
    {
        $this->writeCsv([
            $this->row([
                'name' => 'Cafe Oro',
                'province' => 'Benguet',
                'latitude' => '16.4120',
                'longitude' => '120.5960',
                'source-url' => 'https://example.test/cafe-oro',
                'price-min' => '250',
                'price-basis' => 'per_person_meal',
            ]),
        ]);
        $this->runSeeder();
        $this->runSeeder();

        $this->assertSame(1, Place::count());

        $this->writeCsv([
            $this->row([
                'name' => 'Cafe Oro',
                'province' => 'Benguet',
                'latitude' => '16.4120',
                'longitude' => '120.5960',
                'source-url' => 'https://example.test/cafe-oro',
                'price-min' => '300',
                'price-basis' => 'per_person_meal',
            ]),
        ]);
        $this->runSeeder();

        $this->assertSame(1, Place::count());
        $this->assertSame(300.0, (float) Place::first()->price_min);
    }

    /**
     * A BLANK CELL CLEARS THE VALUE, deliberately.
     *
     * Same rule as the destinations CSV: a withdrawn number must not survive a
     * re-seed as a stale published figure. It is why `price_min` staying NULL is
     * consistent behaviour rather than a gap.
     */
    public function test_a_blank_cell_clears_a_previously_imported_price(): void
    {
        $this->writeCsv([
            $this->row([
                'name' => 'Cleared Price',
                'province' => 'Benguet',
                'latitude' => '16.4120',
                'longitude' => '120.5960',
                'source-url' => 'https://example.test/cleared',
                'price-min' => '250',
                'price-basis' => 'per_person_meal',
            ]),
        ]);
        $this->runSeeder();

        $this->assertSame(250.0, (float) Place::first()->price_min);

        $this->writeCsv([
            $this->row([
                'name' => 'Cleared Price',
                'province' => 'Benguet',
                'latitude' => '16.4120',
                'longitude' => '120.5960',
                'source-url' => 'https://example.test/cleared',
                'price-min' => '',
                'price-basis' => '',
            ]),
        ]);
        $this->runSeeder();

        $this->assertNull(Place::first()->price_min);
        $this->assertNull(Place::first()->price_basis);
    }

    /**
     * A MISSING REQUIRED COLUMN STOPS THE SEED.
     *
     * AGENTS.md: writing four new columns with `array_merge($header, [...])` while
     * still reading by index produced a file whose `name` column held source
     * labels and notes, and re-seeding created seven junk destinations. Refusing
     * to run is the guard that catches it.
     */
    public function test_a_missing_required_column_stops_the_seed(): void
    {
        /*
         * The header keys are HYPHENATED -- `row()` turns `price_min` into
         * `price-min` before returning -- so this filter has to match
         * `source-url` and NOT `source_url`. Filtering on the underscored name
         * removed nothing, the file kept every column, the seed succeeded, and the
         * test failed for a reason that had nothing to do with the guard it exists
         * to check.
         */
        $header = $this->rowHeader();

        $this->assertContains(
            'source-url',
            $header,
            'The fixture no longer builds a source-url column, so this test would '
            .'pass by removing nothing.'
        );

        $withoutSource = array_values(array_filter(
            $header,
            fn (string $column): bool => $column !== 'source-url'
        ));

        $this->assertCount(
            count($header) - 1,
            $withoutSource,
            'Exactly one column should have been removed.'
        );

        File::put(
            $this->path,
            implode(',', $withoutSource)."\n"
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('source-url');
        $this->runSeeder();
    }

    /**
     * A ROW WIDER THAN THE HEADER IS REJECTED RATHER THAN TRUNCATED.
     *
     * `array_combine` discards the overflow silently, so a stray trailing comma
     * would drop a sourced field and leave the row looking complete.
     */
    public function test_a_row_with_too_many_cells_is_rejected(): void
    {
        $row = $this->row([
            'name' => 'Overlong Row',
            'province' => 'Benguet',
            'latitude' => '16.4120',
            'longitude' => '120.5960',
            'source-url' => 'https://example.test/overlong',
        ]);

        $this->writeCsv([implode(',', $row).',extra,more']);
        $this->runSeeder();

        $this->assertSame(0, Place::count());
    }

    /**
     * VERIFICATION DATES ROUND-TRIP, and a bad one is dropped rather than
     * throwing halfway through the import.
     */
    public function test_verification_dates_are_stored_and_a_bad_one_is_dropped(): void
    {
        $this->writeCsv([
            $this->row([
                'name' => 'Dated Place',
                'province' => 'Benguet',
                'latitude' => '16.4120',
                'longitude' => '120.5960',
                'source-url' => 'https://example.test/dated',
                'hours-verified' => '2026-09-27',
                'price-verified' => 'not a date',
            ]),
        ]);
        $this->runSeeder();

        $place = Place::first();

        $this->assertNotNull($place->last_verified_at);
        $this->assertSame('2026-09-27', $place->last_verified_at->toDateString());
        $this->assertNull($place->price_verified_at);
    }

    /**
     * The seeder PRINTS cluster readiness, because "is this cluster big enough
     * to plan a trip in" is the question the panel asked and a row count hides
     * it.
     */
    public function test_the_seeder_reports_cluster_readiness(): void
    {
        File::put($this->path, implode(',', $this->rowHeader())."\n");

        $this->artisan('db:seed', ['--class' => PlacesCsvSeeder::class])
            ->expectsOutputToContain('Baguio')
            ->expectsOutputToContain('Antipolo')
            ->expectsOutputToContain('needs')
            ->assertSuccessful();
    }

    // -------------------------------------------------------------- helpers

    private function runSeeder(): void
    {
        $this->artisan('db:seed', ['--class' => PlacesCsvSeeder::class])
            ->assertSuccessful();
    }

    /**
     * One row, keyed by COLUMN NAME.
     *
     * The header is derived from the keys, so a test cannot drift out of step
     * with the shipped file and every fixture has the same width.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function row(array $overrides = []): array
    {
        $defaults = [
            'name' => '',
            'type' => 'food',
            'cluster' => 'baguio',
            'province' => 'Benguet',
            'municipality' => 'Baguio City',
            'address' => '',
            'latitude' => '16.4120',
            'longitude' => '120.5960',
            'budget_level' => 'economy',
            'cuisine' => '',
            'meal_slots' => 'lunch, dinner',
            'price_min' => '',
            'price_max' => '',
            'price_basis' => '',
            'recommended_minutes' => '60',
            'opening_time' => '10:00',
            'closing_time' => '21:00',
            'closed_days' => '',
            'daily_hours' => '',
            'hours_kind' => '',
            'operating_status' => 'open',
            'hours_note' => '',
            'source_url' => 'https://example.test/place',
            'source_label' => '',
            'hours_verified' => '2026-09-27',
            'price_verified' => '2026-09-27',
        ];

        // Underscores in the fixture become the hyphens the header uses, so a
        // test reads `price_min` and the CSV gets `price-min`.
        $row = [];

        foreach (array_merge($defaults, $overrides) as $column => $value) {
            $row[str_replace('_', '-', $column)] = (string) $value;
        }

        return $row;
    }

    /**
     * @return array<int, string>
     */
    private function rowHeader(): array
    {
        return array_keys($this->row());
    }

    /**
     * @param  array<int, array<string, string>|string>  $rows
     *        a string is written verbatim, which is how the over-wide row is built
     */
    private function writeCsv(array $rows): void
    {
        $lines = [
            implode(',', is_string($rows[0]) ? $this->rowHeader() : array_keys($rows[0])),
        ];

        foreach ($rows as $row) {
            $lines[] = is_string($row) ? $row : implode(',', array_map(
                $this->csvEscape(...),
                array_values($row)
            ));
        }

        File::put($this->path, implode("\n", $lines)."\n");
    }

    /**
     * Quote a cell that contains a comma.
     *
     * THE SAME RULE THE REAL CATALOGUE FOLLOWS, and the first version of this
     * fixture ignored it. `luzon-locations-clean.csv` quotes every field
     * containing a comma, which is how `closed_days` and the descriptive notes
     * survive being comma-separated inside a comma-separated file. Without
     * quoting, a `meal-slots` cell of "lunch, dinner" splits into two cells, the
     * row comes back one cell wider than the header, and the width guard
     * correctly refuses to import it.
     *
     * That is exactly what happened the first time this ran: 19 tests, 5 passing,
     * and every failure downstream of one unquoted comma. The guard was right and
     * the test was wrong, which is the useful outcome of writing both.
     */
    private function csvEscape(string $value): string
    {
        if (! str_contains($value, ',') && ! str_contains($value, '"')) {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }
}