<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\Tag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class LuzonLocationsCsvSeeder extends Seeder
{
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

    private function normalizeHeader(?string $header): string
    {
        $header = trim((string) $header);
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);

        return Str::slug($header);
    }

    private function clean(mixed $value): string
    {
        return preg_replace(
            '/\s+/',
            ' ',
            trim((string) $value)
        );
    }

    private function cleanTime(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return preg_match('/\A(\d{1,2}):(\d{2})\z/', $value, $match) === 1
            ? sprintf('%02d:%02d', (int) $match[1], (int) $match[2])
            : null;
    }

    private function normalizeClosedDays(mixed $value): array
    {
        $days = array_map(
            fn ($day) => strtolower(trim((string) $day)),
            is_array($value)
                ? $value
                : explode(',', (string) $value)
        );

        $days = array_values(array_intersect(Destination::daySlugs(), $days));

        return array_values(array_unique($days));
    }

    private function normalizeHoursKind(
        mixed $value,
        ?array $dailyHours,
        ?string $openingTime,
        ?string $closingTime
    ): ?string {
        $value = strtolower(trim((string) $value));

        if (in_array($value, Destination::HOURS_KINDS, true)) {
            return $value;
        }

        if ($dailyHours !== null) {
            return Destination::HOURS_PER_DAY;
        }

        if ($openingTime === '00:00' && $closingTime === '23:59') {
            return Destination::HOURS_ALWAYS_OPEN;
        }

        return null;
    }

    private function cleanOrNull(mixed $value): ?string
    {
        $value = $this->clean($value);

        return $value === '' ? null : $value;
    }

    private function normalizeDailyHours(mixed $value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode(trim($value), true);

            if (!is_array($decoded)) {
                return null;
            }

            $value = $decoded;
        }

        if (!is_array($value)) {
            return null;
        }

        $hours = [];

        foreach (Destination::daySlugs() as $day) {
            $window = $value[$day] ?? null;

            if (is_string($window)) {
                $window = $this->parseTimeRange($window);
            }

            if (!is_array($window)) {
                continue;
            }

            if (($window['closed'] ?? false) === true) {
                $hours[$day] = ['closed' => true];

                continue;
            }

            $open = $this->cleanTime($window['open'] ?? null);
            $close = $this->cleanTime($window['close'] ?? null);

            if ($open === null || $close === null || $open === $close) {
                continue;
            }

            $hours[$day] = ['open' => $open, 'close' => $close];
        }

        return $hours === [] ? null : $hours;
    }

    private function parseTimeRange(string $value): ?array
    {
        if (preg_match(
            '/\A(\d{1,2}:\d{2})\s*(?:-|–|to)\s*(\d{1,2}:\d{2})\z/i',
            trim($value),
            $match
        ) !== 1) {
            return null;
        }

        return ['open' => $match[1], 'close' => $match[2]];
    }

    private function normalizeOperatingStatus(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace([' ', '-'], '_', $value);

        return in_array(
            $value,
            ['open', 'temporarily_closed', 'permanently_closed', 'unknown'],
            true
        )
            ? $value
            : 'unknown';
    }

    private function normalizeBudget(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace(['–', '—', ' '], '-', $value);

        return in_array(
            $value,
            ['economy', 'mid-range', 'premium'],
            true
        )
            ? $value
            : 'economy';
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
