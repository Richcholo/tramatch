<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\Tag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class LuzonLocationsCsvSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/luzon-locations.csv');

        if (!File::exists($path)) {
            throw new RuntimeException(
                'CSV file not found: database/data/luzon-locations.csv'
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

                $name = trim((string) ($row['name'] ?? ''));

                if ($name === '') {
                    continue;
                }

                $province = trim((string) ($row['province'] ?? ''));
                $municipality = trim((string) ($row['municipality'] ?? ''));

                $budgetLevel = $this->normalizeBudget(
                    $row['budget-level'] ?? null
                );

                $entranceFee = $this->parseMoney(
                    $row['entrance-fee'] ?? null
                );

                $estimatedCost = $this->parseMoney(
                    $row['estimated-cost'] ?? null
                );

                if ($estimatedCost <= 0) {
                    $estimatedCost = $this->placeholderCost(
                        $budgetLevel,
                        $entranceFee
                    );
                }

                $recommendedMinutes = $this->parseInteger(
                    $row['recommended-minutes'] ?? null
                );

                if ($recommendedMinutes <= 0) {
                    $recommendedMinutes = 120;
                }

                $latitude = $this->parseDecimal(
                    $row['latitude'] ?? null
                );

                $longitude = $this->parseDecimal(
                    $row['longitude'] ?? null
                );

                if ($latitude === null || $longitude === null) {
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

                $destination = Destination::updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $name,
                        'description' => $description,
                        'province' => $province,
                        'municipality' => $municipality,
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'budget_level' => $budgetLevel,
                        'entrance_fee' => $entranceFee,
                        'estimated_cost' => $estimatedCost,
                        'recommended_minutes' => $recommendedMinutes,
                        'opening_time' => null,
                        'closing_time' => null,
                        'image_url' => null,
                        'is_active' => true,
                    ]
                );

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

    private function parseMoney(mixed $value): float
    {
        if ($value === null) {
            return 0;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return 0;
        }

        $cleaned = preg_replace('/[^\d.-]/', '', $value);

        if ($cleaned === '' || !is_numeric($cleaned)) {
            return 0;
        }

        return round((float) $cleaned, 2);
    }

    private function parseInteger(mixed $value): int
    {
        if ($value === null || trim((string) $value) === '') {
            return 0;
        }

        return (int) round((float) $value);
    }

    private function parseDecimal(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
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

        $notes = trim((string) $notes);

        if ($notes !== '') {
            $description .= " {$notes}";
        }

        return $description;
    }
}