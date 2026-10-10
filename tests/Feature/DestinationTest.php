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
            ->assertSee('role="combobox"', false)
            ->assertSee('role="listbox"', false)
            ->assertSee('data-value="Antipolo"', false)
            ->assertSee('data-value="Rizal"', false)
            ->assertSee('data-value="Antipolo Art Walk"', false)
            ->assertDontSee('data-value="Old Town"', false);
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

    public function test_destination_pagination_uses_six_items_when_requested_for_mobile(): void
    {
        $user = User::factory()->create();

        for ($index = 1; $index <= 7; $index++) {
            $this->createDestination("Destination {$index}", 'Rizal', 'Antipolo');
        }

        $response = $this
            ->actingAs($user)
            ->get(route('destinations.index', ['per_page' => 6]));

        $response
            ->assertOk()
            ->assertSee('data-per-page="6"', false)
            ->assertSee('per_page=6', false);

        $this->assertSame(6, substr_count((string) $response->getContent(), 'data-destination-card'));

        $secondPage = $this
            ->actingAs($user)
            ->get(route('destinations.index', ['per_page' => 6, 'page' => 2]));

        $secondPage
            ->assertOk()
            ->assertSee('per_page=6', false);

        $this->assertSame(1, substr_count((string) $secondPage->getContent(), 'data-destination-card'));
    }

    public function test_destination_pagination_rejects_unsupported_page_sizes(): void
    {
        $this->get(route('destinations.index', ['per_page' => 5]))
            ->assertInvalid('per_page');
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
