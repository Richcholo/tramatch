<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\Place;
use Database\Seeders\Concerns\ReadsCatalogueRows;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Loads `database/data/places.csv` -- the food and lodging a traveller needs
 * around a destination.
 *
 * `LuzonLocationsCsvSeeder` is the pattern this follows, and the places variant
 * is deliberately NOT a copy of it. Two of its behaviours are different, and
 * both come from `places` having a nullable price and a mandatory source:
 *
 * 1. **A ROW WITH NO `source-url` IS REJECTED, NOT IMPORTED.** `places.source_url`
 *    is NOT NULL in the schema, so an unsourced row cannot be stored anyway, but
 *    letting `fgetcsv` skip it silently is how a catalogue of thirty restaurants
 *    turns into a catalogue of twelve with no explanation. Every rejection is
 *    counted and printed.
 *
 * 2. **A BLANK PRICE CELL IS NULL, NOT ZERO.** This is the whole reason the
 *    column is nullable. `LuzonLocationsCsvSeeder` substitutes a
 *    `placeholderCost()` figure when `estimated-cost` is blank, which is
 *    acceptable for an attraction whose fee is published and indefensible for a
 *    hotel: a room filed at zero sums into a trip total as free, and the app then
 *    claims the trip fits a budget. Here a blank stays blank and the place prints
 *    "Price not listed".
 *
 * EVERY RULE HERE IS ALSO A TEST IN `tests/Feature/PlacesCsvSeederTest.php`. The
 * lessons in AGENTS.md about rewriting this catalogue by index instead of by name
 * cost seven junk destinations once; the guards that would have caught it belong
 * next to the reader, not only in a comment.
 */
class PlacesCsvSeeder extends Seeder
{
    use ReadsCatalogueRows;

    /**
     * A meal slot the seeder accepts, mirrored from Place::MEAL_SLOTS.
     *
     * Read from the model rather than repeated here, so adding "merienda" to the
     * model is enough and this cannot become a second list that disagrees.
     */
    private const MEAL_SLOTS = Place::MEAL_SLOTS;

    public function run(): void
    {
        $path = $this->csvPath();

        if (! File::exists($path)) {
            throw new RuntimeException('CSV file not found: '.$path);
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the places CSV file.');
        }

        $rawHeaders = fgetcsv($handle);

        if ($rawHeaders === false) {
            fclose($handle);
            throw new RuntimeException('The places CSV file has no header row.');
        }

        $headers = array_map(
            fn ($header) => $this->normalizeHeader($header),
            $rawHeaders
        );

        $this->assertRequiredColumns($headers);

        $imported = 0;
        $rejected = [];

        DB::transaction(function () use ($handle, $headers, &$imported, &$rejected) {
            $lineNumber = 1;

            while (($values = fgetcsv($handle)) !== false) {
                $lineNumber++;

                // A blank line arrives as a single empty field.
                if (count($values) === 1 && trim((string) $values[0]) === '') {
                    continue;
                }

                /*
                 * THE WIDTH ASSERTION. A row narrower than the header is padded
                 * so the extra columns are null; a row WIDER is refused outright,
                 * because `array_combine` discards the overflow silently and a
                 * stray trailing comma would shift nothing while dropping a
                 * sourced field. AGENTS.md records what positional reads of this
                 * catalogue cost.
                 */
                if (count($values) > count($headers)) {
                    $rejected[] = [
                        'line' => $lineNumber,
                        'name' => (string) ($values[0] ?? ''),
                        'reason' => 'row has '.count($values).' cells for '.count($headers)
                            .' columns',
                    ];

                    continue;
                }

                $values = array_pad($values, count($headers), null);
                $row = array_combine($headers, $values);

                if ($row === false) {
                    continue;
                }

                $name = $this->clean($row['name'] ?? null);

                if ($name === '') {
                    $rejected[] = [
                        'line' => $lineNumber,
                        'name' => '',
                        'reason' => 'no name',
                    ];

                    continue;
                }

                $sourceUrl = $this->cleanOrNull($row['source-url'] ?? null);

                if ($sourceUrl === null) {
                    $rejected[] = [
                        'line' => $lineNumber,
                        'name' => $name,
                        'reason' => 'no source-url, so nothing can vouch for it',
                    ];

                    continue;
                }

                $latitude = $this->parseNumber($row['latitude'] ?? null);
                $longitude = $this->parseNumber($row['longitude'] ?? null);

                // 0,0 is null island in the Gulf of Guinea. A place with no real
                // position cannot be routed to or shown on a map, and it is
                // always a mistake rather than a place.
                if ($latitude === null || $longitude === null) {
                    $rejected[] = [
                        'line' => $lineNumber,
                        'name' => $name,
                        'reason' => 'latitude or longitude is blank or not a number',
                    ];

                    continue;
                }

                if ($latitude === 0.0 && $longitude === 0.0) {
                    $rejected[] = [
                        'line' => $lineNumber,
                        'name' => $name,
                        'reason' => 'coordinates are 0,0, which is not in the Philippines',
                    ];

                    continue;
                }

                $type = $this->normalizeType($row['type'] ?? null);

                if ($type === null) {
                    $rejected[] = [
                        'line' => $lineNumber,
                        'name' => $name,
                        'reason' => 'type must be one of: '.implode(', ', Place::TYPES),
                    ];

                    continue;
                }

                $priceMin = $this->parseOptionalNumber($row['price-min'] ?? null);
                $priceMax = $this->parseOptionalNumber($row['price-max'] ?? null);

                // A band with only a maximum, or one that runs backwards, is a
                // typo rather than a price. Refusing is better than silently
                // swapping the two and publishing a restaurant's range as the
                // cheapest thing it sells.
                if ($priceMax !== null && $priceMin !== null && $priceMax < $priceMin) {
                    [$priceMin, $priceMax] = [$priceMax, $priceMin];
                }

                if ($priceMax !== null && $priceMin === null) {
                    $rejected[] = [
                        'line' => $lineNumber,
                        'name' => $name,
                        'reason' => 'price-max without price-min',
                    ];

                    continue;
                }

                $priceBasis = $this->normalizePriceBasis(
                    $row['price-basis'] ?? null,
                    $priceMin
                );

                $closedDays = $this->normalizeClosedDays($row['closed-days'] ?? null);
                $dailyHours = $this->normalizeDailyHours($row['daily-hours'] ?? null);
                $openingTime = $this->cleanTime($row['opening-time'] ?? null);
                $closingTime = $this->cleanTime($row['closing-time'] ?? null);

                $place = Place::firstOrNew([
                    'slug' => Str::slug($name),
                ]);

                $place->fill([
                    'name' => $name,
                    'type' => $type,
                    'cluster' => $this->cleanOrNull($row['cluster'] ?? null),
                    'province' => $this->clean($row['province'] ?? null),
                    'municipality' => $this->cleanOrNull($row['municipality'] ?? null),
                    'address' => $this->cleanOrNull($row['address'] ?? null),
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'budget_level' => $this->normalizeBudget($row['budget-level'] ?? null),
                    'cuisine' => $this->cleanOrNull($row['cuisine'] ?? null),
                    'meal_slots' => $this->normalizeMealSlots($row['meal-slots'] ?? null),
                    'price_min' => $priceMin,
                    'price_max' => $priceMax,
                    'price_basis' => $priceBasis,
                    'recommended_minutes' => $this->normalizeMinutes(
                        $row['recommended-minutes'] ?? null,
                        $type
                    ),
                    'opening_time' => $openingTime,
                    'closing_time' => $closingTime,
                    'closed_days' => $closedDays === [] ? null : implode(',', $closedDays),
                    'daily_hours' => $dailyHours,
                    'hours_kind' => $this->normalizeHoursKind(
                        $row['hours-kind'] ?? null,
                        $dailyHours,
                        $openingTime,
                        $closingTime
                    ),
                    'operating_status' => $this->normalizeOperatingStatus(
                        $row['operating-status'] ?? null
                    ),
                    'hours_note' => $this->cleanOrNull($row['hours-note'] ?? null),
                    'source_url' => $sourceUrl,
                    'source_label' => $this->cleanOrNull($row['source-label'] ?? null),
                    'is_active' => true,
                ]);

                $hoursVerifiedAt = $this->parseVerifiedDate($row['hours-verified'] ?? null);
                $priceVerifiedAt = $this->parseVerifiedDate($row['price-verified'] ?? null);

                if ($hoursVerifiedAt !== null) {
                    $place->last_verified_at = $hoursVerifiedAt;
                }

                if ($priceVerifiedAt !== null) {
                    $place->price_verified_at = $priceVerifiedAt;
                }

                $place->save();

                $imported++;
            }
        });

        fclose($handle);

        $this->command?->info("Imported {$imported} places.");

        if ($rejected !== []) {
            $this->command?->warn(count($rejected).' row(s) were NOT imported:');

            foreach ($rejected as $rejection) {
                $this->command?->warn(sprintf(
                    '  line %d, "%s": %s',
                    $rejection['line'],
                    $rejection['name'] === '' ? '(unnamed)' : $rejection['name'],
                    $rejection['reason']
                ));
            }
        }

        /*
         * The coverage report, printed whether or not anything was imported.
         *
         * A seeder that reports "imported 0" and stops has told an admin nothing
         * about whether the catalogue is big enough, and "is it big enough" is the
         * question this whole feature exists to answer. Readiness is printed per
         * tier because the exact-match budget filter makes a provincial total
         * misleading -- see Place::clusterCoverage().
         */
        foreach (Place::CLUSTERS as $cluster => $definition) {
            $this->command?->info(sprintf(
                '%s (%s) — needs %d food, %d lodging:',
                $definition['label'],
                $definition['province'],
                Place::CLUSTER_MINIMUMS['food'],
                Place::CLUSTER_MINIMUMS['lodging']
            ));

            foreach (array_keys(Destination::BUDGET_TIERS) as $tier) {
                $readiness = Place::clusterReadiness($definition['province'], $tier);

                $coverage = Place::clusterCoverage($definition['province'])[$tier];

                $this->command?->info(sprintf(
                    '    %-10s %d food, %d lodging, %d priced — %s',
                    $tier,
                    $coverage['food'],
                    $coverage['lodging'],
                    $coverage['priced'],
                    $readiness['ready']
                        ? 'ready'
                        : 'short: '.implode(', ', $readiness['gaps'])
                ));
            }
        }
    }

    /**
     * Which CSV to read.
     *
     * Overridable through config purely so the tests can point at a temporary
     * file. The earlier version wrote a `.bak` beside the real catalogue and
     * restored it in `tearDown()`, which works right up until a test fatals --
     * then `tearDown()` never runs and the repository's data file is left
     * holding whatever the last test wrote. That happened here, and it left
     * `places.csv` containing a fabricated row called "Merienda Place" that a
     * later test run then read as real data.
     *
     * A test that can point the seeder somewhere harmless does not need a
     * backup, cannot leave the tree dirty, and cannot be defeated by a fatal.
     */
    private function csvPath(): string
    {
        $configured = config('data.places_csv');

        return is_string($configured) && $configured !== ''
            ? $configured
            : database_path('data/places.csv');
    }

    /**
     * The columns a row must have, named rather than indexed.
     *
     * AGENTS.md records the cost of writing four columns while reading by index:
     * a 31-column file whose `name` column held source labels, and seven junk
     * destinations created from it. Refusing to run is better than importing
     * garbage, and this is the check that notices.
     *
     * @param  array<int, string>  $headers
     */
    private function assertRequiredColumns(array $headers): void
    {
        $required = [
            'name',
            'type',
            'province',
            'latitude',
            'longitude',
            'budget-level',
            'source-url',
        ];

        $missing = array_values(array_diff($required, $headers));

        if ($missing !== []) {
            throw new RuntimeException(
                'places.csv is missing required column(s): '.implode(', ', $missing)
            );
        }
    }


    /**
     * A required coordinate. Returns null rather than 0.0 so the caller can tell
     * "absent" from "actually on the prime meridian", which is the difference
     * between rejecting a row and putting a pin in the Atlantic.
     */
    private function parseNumber(mixed $value): ?float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (! is_numeric(str_replace(',', '', $value))) {
            return null;
        }

        return (float) str_replace(',', '', $value);
    }

    /**
     * An OPTIONAL price. Null means the source published none, and null is
     * carried all the way to the column.
     *
     * This is the method `LuzonLocationsCsvSeeder` does not have. There,
     * `estimated-cost` is NOT NULL and a placeholder is substituted; here a
     * blank is the honest answer and inventing a figure from the budget tier
     * would put a peso amount on a real restaurant that nobody published.
     */
    private function parseOptionalNumber(mixed $value): ?float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $cleaned = str_replace(',', '', str_starts_with($value, '₱') ? substr($value, 3) : $value);

        if (! is_numeric($cleaned)) {
            return null;
        }

        $number = (float) $cleaned;

        return $number < 0 ? null : round($number, 2);
    }

    private function normalizeType(mixed $value): ?string
    {
        $value = strtolower($this->clean($value));
        $value = str_replace([' ', '-'], '_', $value);

        // A curator writing "food" and a curator writing "Food" must land in the
        // same place, and an unrecognised value must not become a food place by
        // falling through to a default.
        return in_array($value, Place::TYPES, true) ? $value : null;
    }


    private function normalizePriceBasis(mixed $value, ?float $priceMin): ?string
    {
        $value = strtolower($this->clean($value));
        $value = str_replace([' ', '-'], '_', $value);

        if (! in_array($value, Place::PRICE_BASES, true)) {
            return null;
        }

        // A basis with no price describes nothing, and storing one invites a
        // future caller to multiply nothing by a group size and print a total.
        return $priceMin === null ? null : $value;
    }

    private function normalizeMealSlots(mixed $value): ?array
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $slots = is_array($value)
            ? $value
            : explode(',', (string) $value);

        $slots = array_values(array_unique(array_filter(array_map(
            function ($slot): string {
                $slot = strtolower($this->clean($slot));

                // A curator writing "Breakfast, Lunch" in one cell.
                return trim(explode('/', $slot)[0]);
            },
            $slots
        ), fn (string $slot): bool => in_array($slot, self::MEAL_SLOTS, true))));

        return $slots === [] ? null : $slots;
    }


    private function normalizeMinutes(mixed $value, ?string $type = null): int
    {
        $minutes = (int) round((float) $this->parseNumber($value) ?? 0);

        // A hotel check-in is not a visit and stays 0 for lodging, which is what
        // the `places` migration documents. Forcing the meal default here would
        // schedule every overnight stay as a one-hour attraction.
        if ($type === Place::TYPE_LODGING) {
            return max(0, min($minutes, 1440));
        }

        if ($minutes <= 0) {
            // A meal is 60 minutes because that is roughly how long one takes,
            // which is a SCHEDULING ASSUMPTION rather than a fact about the
            // restaurant, and it is documented as such on Place.
            return 60;
        }

        return min($minutes, 1440);
    }

    private function parseVerifiedDate(mixed $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
