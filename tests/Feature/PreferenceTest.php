<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preferred_region_suggests_active_destination_provinces_and_municipalities(): void
    {
        $user = User::factory()->create();
        $this->createDestination('Batangas', 'Mabini');
        $this->createDestination('Dormant Province', 'Dormant Town', false);

        $response = $this
            ->actingAs($user)
            ->get(route('preferences.edit'));

        $response
            ->assertOk()
            ->assertSee('list="preferred-region-options"', false)
            ->assertSee('value="Batangas"', false)
            ->assertSee('value="Mabini"', false)
            ->assertDontSee('Dormant Province', false)
            ->assertSee('Choose a suggestion or type any specific place.');
    }

    public function test_user_can_save_a_custom_preferred_region(): void
    {
        $user = User::factory()->create();
        $tag = Tag::create([
            'name' => 'Nature',
            'slug' => 'nature',
        ]);

        $response = $this
            ->actingAs($user)
            ->put(route('preferences.update'), [
                'budget_level' => 'economy',
                'group_size' => 2,
                'trip_duration_days' => 3,
                'preferred_region' => 'Quiet Cove',
                'weights' => [$tag->id => 2],
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('discover.index'));

        $this->assertDatabaseHas('travel_profiles', [
            'user_id' => $user->id,
            'preferred_region' => 'Quiet Cove',
        ]);
    }

    private function createDestination(string $province, string $municipality, bool $isActive = true): Destination
    {
        return Destination::create([
            'name' => "{$municipality} Destination",
            'slug' => strtolower(str_replace(' ', '-', "{$municipality}-destination")),
            'description' => 'A destination for preference suggestions.',
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
