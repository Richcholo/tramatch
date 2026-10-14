<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\Tag;
use Database\Seeders\Concerns\ReadsCatalogueRows;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class LuzonLocationsCsvSeeder extends Seeder
{
    use ReadsCatalogueRows;

    public function run(): void
    {
        $path = database_path('data/luzon-locations-clean.csv');

        if (!File::exists($path)) {
            throw new RuntimeException(
                'CSV file not found: database/data/luzon-locations-clean.csv'
            );
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the CSV file.');
        }

        $rawHeaders = fgetcsv($handle);

        if ($rawHeaders === false) {
            fclose($handle);
            throw new RuntimeException('The CSV file has no header row.');
        }

        $headers = array_map(
            fn ($header) => $this->normalizeHeader($header),
            $rawHeaders
        );

        $imported = 0;

        DB::transaction(function () use (
            $handle,
            $headers,
            &$imported
        ) {
            while (($values = fgetcsv($handle)) !== false) {
                if (
                    count($values) === 1
                    && trim((string) $values[0]) === ''
                ) {
                    continue;
                }

                $values = array_pad($values, count($headers), null);
                $row = array_combine($headers, $values);

                if (!$row) {
                    continue;
                }

                $name = $this->clean($row['name'] ?? null);

                if ($name === '') {
                    continue;
                }

                $province = $this->clean($row['province'] ?? null);
                $municipality = $this->clean($row['municipality'] ?? null);
                $budgetLevel = $this->normalizeBudget(
                    $row['budget-level'] ?? null
                );

                $entranceFee = $this->parseNumber(
                    $row['entrance-fee'] ?? null
                );

                $closedDays = $this->normalizeClosedDays(
                    $row['closed-days'] ?? null
                );

                $dailyHours = $this->normalizeDailyHours(
                    $row['daily-hours'] ?? null
                );

                $openingTime = $this->cleanTime(
                    $row['opening-time'] ?? null
                );

                $closingTime = $this->cleanTime(
                    $row['closing-time'] ?? null
                );

                $estimatedCost = $this->parseNumber(
                    $row['estimated-cost'] ?? null
                );

                if ($estimatedCost <= 0) {
                    $estimatedCost = $this->placeholderCost(
                        $budgetLevel,
                        $entranceFee
                    );
                }

                $recommendedMinutes = (int) round(
                    $this->parseNumber(
                        $row['recommended-minutes'] ?? null
                    )
                );

                if ($recommendedMinutes <= 0) {
                    $recommendedMinutes = 120;
                }

                $latitude = $this->parseNumber(
                    $row['latitude'] ?? null
                );

                $longitude = $this->parseNumber(
                    $row['longitude'] ?? null
                );

                if ($latitude === 0.0 || $longitude === 0.0) {
                    continue;
                }

                $tagSlugs = collect([
                    $row['tag-1'] ?? null,
                    $row['tag-2'] ?? null,
                    $row['tag-3'] ?? null,
                ])
                    ->map(fn ($tag) => Str::slug((string) $tag))
                    ->filter()
                    ->unique()
                    ->values();

                $tagIds = $tagSlugs
                    ->map(function ($slug) {
                        $tag = Tag::firstOrCreate(
                            ['slug' => $slug],
                            ['name' => Str::headline($slug)]
                        );

                        return $tag->id;
                    })
                    ->all();

                $slug = Str::slug($name);

                $description = $this->buildDescription(
                    $name,
                    $province,
                    $municipality,
                    $budgetLevel,
                    $tagSlugs->all(),
                    $row['fee-operational-notes'] ?? null
                );

                $destination = Destination::firstOrNew([
                    'slug' => $slug,
                ]);

                $hoursVerified = $row['hours-verified'] ?? null;
                $hoursVerifiedAt = $this->clean($hoursVerified) !== ''
                    ? Carbon::parse($this->clean($hoursVerified))->startOfDay()
                    : null;

                $destination->fill([
                    'name' => $name,
                    'description' => $description,
                    'province' => $province,
                    'municipality' => $municipality,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'budget_level' => $budgetLevel,
                    'entrance_fee' => $entranceFee,
                    'entrance_fee_display' => $this->clean(
                        $row['entrance-fee-display'] ?? null
                    ),
                    'local_transportation' => $this->clean(
                        $row['local-transportation'] ?? null
                    ),
                    'typical_food_drinks' => $this->clean(
                        $row['typical-food-drinks'] ?? null
                    ),
                    'activities' => $this->clean(
                        $row['activities'] ?? null
                    ),
                    'estimated_cost' => $estimatedCost,
                    'estimated_cost_display' => $this->clean(
                        $row['estimated-cost-display'] ?? null
                    ),
                    'pricing_type' => $this->clean(
                        $row['pricing-type'] ?? null
                    ),
                    'fee_operational_notes' => $this->clean(
                        $row['fee-operational-notes'] ?? null
                    ),
                    'recommended_minutes' => $recommendedMinutes,
                    'recommended_minutes_display' => $this->clean(
                        $row['recommended-minutes-display'] ?? null
                    ),
                    'possible_expenses' => $this->clean(
                        $row['possible-expenses'] ?? null
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
                    'hours_source_url' => $this->cleanOrNull(
                        $row['hours-source-url'] ?? null
                    ),
                    'hours_source_label' => $this->cleanOrNull(
                        $row['hours-source-label'] ?? null
                    ),
                    'hours_note' => $this->cleanOrNull(
                        $row['hours-note'] ?? null
                    ),
                    'operating_status' => $this->normalizeOperatingStatus(
                        $row['operating-status'] ?? null
                    ),
                    'is_active' => true,
                ]);

                if ($hoursVerifiedAt !== null) {
                    $destination->last_verified_at = $hoursVerifiedAt;
                }

                if (!$destination->exists) {
                    $destination->image_url = null;
                }

                $destination->save();
                $destination->tags()->sync($tagIds);

                $imported++;
            }
        });

        fclose($handle);

        $this->command?->info(
            "Imported {$imported} Luzon destinations."
        );
    }

    private function parseNumber(mixed $value): float
    {
        if ($value === null) {
            return 0;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return 0;
        }

        $numbers = preg_match_all(
            '/\d[\d,]*(?:\.\d+)?/',
            $value,
            $matches
        )
            ? $matches[0]
            : [];

        if (empty($numbers)) {
            return 0;
        }

        $numbers = array_map(
            fn ($number) => (float) str_replace(',', '', $number),
            $numbers
        );

        return round(array_sum($numbers) / count($numbers), 2);
    }

    private function placeholderCost(
        string $budgetLevel,
        float $entranceFee
    ): float {
        $baseCost = match ($budgetLevel) {
            'economy' => 750,
            'mid-range' => 1800,
            'premium' => 3500,
            default => 750,
        };

        return round($baseCost + $entranceFee, 2);
    }

    private function buildDescription(
        string $name,
        string $province,
        string $municipality,
        string $budgetLevel,
        array $tagSlugs,
        mixed $notes
    ): string {
        $tagNames = collect($tagSlugs)
            ->map(fn ($slug) => Str::headline($slug))
            ->implode(', ');

        $description = "{$name} is a {$budgetLevel} destination in {$municipality}, {$province}.";

        if ($tagNames !== '') {
            $description .= " It is suited for {$tagNames}.";
        }

        $notes = $this->clean($notes);

        if ($notes !== '') {
            $description .= " {$notes}";
        }

        return $description;
    }
}
