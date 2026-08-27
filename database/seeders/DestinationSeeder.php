<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\Tag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DestinationSeeder extends Seeder
{
    public function run(): void
    {
        $destinations = [
            [
                'name' => 'Anilao Coast',
                'description' => 'A coastal destination known for diving, snorkeling, and relaxing seaside activities.',
                'province' => 'Batangas',
                'municipality' => 'Mabini',
                'latitude' => 13.7412,
                'longitude' => 120.9115,
                'budget_level' => 'mid-range',
                'entrance_fee' => 150,
                'estimated_cost' => 1800,
                'recommended_minutes' => 240,
                'tags' => ['Beach', 'Adventure', 'Relaxation', 'Photography', 'Water Activities'],
            ],
            [
                'name' => 'Pinto Art Museum',
                'description' => 'An art museum and garden complex featuring Filipino contemporary art and architecture.',
                'province' => 'Rizal',
                'municipality' => 'Antipolo',
                'latitude' => 14.5874,
                'longitude' => 121.1756,
                'budget_level' => 'mid-range',
                'entrance_fee' => 250,
                'estimated_cost' => 900,
                'recommended_minutes' => 180,
                'tags' => ['Cultural', 'Photography', 'Relaxation', 'Family-Friendly'],
            ],
            [
                'name' => 'Baguio Heritage Walk',
                'description' => 'A city-based cultural route that connects heritage buildings, parks, markets, and local food spots.',
                'province' => 'Benguet',
                'municipality' => 'Baguio City',
                'latitude' => 16.4023,
                'longitude' => 120.5960,
                'budget_level' => 'economy',
                'entrance_fee' => 0,
                'estimated_cost' => 850,
                'recommended_minutes' => 240,
                'tags' => ['Mountain', 'Historical', 'Cultural', 'City', 'Food and Dining', 'Budget-Friendly'],
            ],
            [
                'name' => 'Caliraya Adventure Area',
                'description' => 'A lakeside and countryside destination suited to outdoor activities and group trips.',
                'province' => 'Laguna',
                'municipality' => 'Lumban',
                'latitude' => 14.3290,
                'longitude' => 121.4740,
                'budget_level' => 'mid-range',
                'entrance_fee' => 200,
                'estimated_cost' => 1500,
                'recommended_minutes' => 300,
                'tags' => ['Nature', 'Adventure', 'Countryside', 'Family-Friendly', 'Water Activities'],
            ],
            [
                'name' => 'Las Casas Filipinas de Acuzar',
                'description' => 'A heritage resort featuring reconstructed Spanish-era Filipino houses and cultural experiences.',
                'province' => 'Bataan',
                'municipality' => 'Bagac',
                'latitude' => 14.5986,
                'longitude' => 120.3927,
                'budget_level' => 'premium',
                'entrance_fee' => 1500,
                'estimated_cost' => 4500,
                'recommended_minutes' => 360,
                'tags' => ['Historical', 'Cultural', 'Photography', 'Romantic', 'Relaxation'],
            ],
            [
                'name' => 'Masungi Georeserve',
                'description' => 'A conservation area with guided trails, limestone formations, and nature-based activities.',
                'province' => 'Rizal',
                'municipality' => 'Baras',
                'latitude' => 14.6044,
                'longitude' => 121.3634,
                'budget_level' => 'premium',
                'entrance_fee' => 1500,
                'estimated_cost' => 3500,
                'recommended_minutes' => 300,
                'tags' => ['Nature', 'Adventure', 'Mountain', 'Photography', 'Camping'],
            ],
        ];

        foreach ($destinations as $data) {
            $tagNames = $data['tags'];
            unset($data['tags']);

            $destination = Destination::updateOrCreate(
                ['slug' => Str::slug($data['name'])],
                [...$data, 'is_active' => true]
            );

            $tagIds = Tag::whereIn('name', $tagNames)->pluck('id');
            $destination->tags()->sync($tagIds);
        }
    }
}