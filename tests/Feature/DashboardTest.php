<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_recent_likes_and_saved_trips_without_numbered_stat_labels(): void
    {
        $user = User::factory()->create();
        $user->travelProfile()->create([
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 3,
        ]);
        $destination = Destination::create([
            'name' => 'Antipolo Art Walk',
            'slug' => 'antipolo-art-walk',
            'description' => 'A local art destination.',
            'province' => 'Rizal',
            'municipality' => 'Antipolo',
            'latitude' => 14.0,
            'longitude' => 121.0,
            'budget_level' => 'economy',
            'estimated_cost' => 1000,
            'recommended_minutes' => 120,
            'is_active' => true,
        ]);
        DestinationSwipe::create([
            'user_id' => $user->id,
            'destination_id' => $destination->id,
            'action' => 'liked',
        ]);
        $user->itineraries()->create([
            'title' => 'Rizal Weekend',
            'area' => 'Rizal',
            'start_date' => now()->addWeek()->toDateString(),
            'budget_level' => 'economy',
            'trip_duration_days' => 2,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertSee('Recent activity')
            ->assertSee('Antipolo Art Walk')
            ->assertSee('Rizal Weekend')
            ->assertSee('Liked')
            ->assertSee('Passed')
            ->assertSee('Trips')
            ->assertDontSee('01 / Liked')
            ->assertDontSee('02 / Passed')
            ->assertDontSee('03 / Trips');
    }
}
