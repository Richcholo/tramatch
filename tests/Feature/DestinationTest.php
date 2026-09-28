<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationTest extends TestCase
{
    use RefreshDatabase;

    public function test_destination_search_suggests_locations_and_matches_municipalities(): void
    {
        $user = User::factory()->create();
        $destination = $this->createDestination('Antipolo Art Walk', 'Rizal', 'Antipolo');
        $this->createDestination('Hidden Falls', 'Laguna', 'Old Town', false);

        $response = $this
            ->actingAs($user)
            ->get(route('destinations.index', ['search' => 'Antipolo']));

        $response
            ->assertOk()
            ->assertSee($destination->name)
            ->assertSee('list="destination-location-options"', false)
            ->assertSee('value="Antipolo"', false)
            ->assertSee('value="Rizal"', false)
            ->assertSee('value="Antipolo Art Walk"', false)
            ->assertDontSee('value="Old Town"', false);
    }

    public function test_destination_pagination_shows_numbered_pages(): void
    {
        $user = User::factory()->create();

        for ($index = 1; $index <= 10; $index++) {
            $this->createDestination("Destination {$index}", 'Rizal', 'Antipolo');
        }

        $response = $this
            ->actingAs($user)
            ->get(route('destinations.index'));

        $response
            ->assertOk()
            ->assertSee('aria-label="Page 1"', false)
            ->assertSee('aria-label="Go to page 2"', false)
            ->assertSee('page=2', false);
    }

    private function createDestination(
        string $name,
        string $province,
        string $municipality,
        bool $isActive = true
    ): Destination {
        return Destination::create([
            'name' => $name,
            'slug' => str()->slug($name),
            'description' => "A place in {$municipality}.",
            'province' => $province,
            'municipality' => $municipality,
            'latitude' => 14.0,
            'longitude' => 121.0,
            'budget_level' => 'economy',
            'estimated_cost' => 1000,
            'recommended_minutes' => 120,
            'is_active' => $isActive,
        ]);
    }
}
