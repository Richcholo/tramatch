<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\DestinationSwipe;

class ItineraryGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_generate_an_itinerary(): void
    {
        $user = User::factory()->create();
        $profile = TravelProfile::create([
            'user_id' => $user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 1,
        ]);

        $tag = Tag::create(['name' => 'Nature', 'slug' => 'nature']);
        $destination = Destination::create([
            'name' => 'Nature Park',
            'slug' => 'nature-park',
            'description' => 'A nature park.',
            'province' => 'Laguna',
            'latitude' => 14.3,
            'longitude' => 121.4,
            'budget_level' => 'economy',
            'estimated_cost' => 800,
            'recommended_minutes' => 120,
        ]);

        $destination->tags()->attach($tag);
        $profile->tags()->attach($tag, ['weight' => 3]);
        
        DestinationSwipe::create([
            'user_id' => $user->id,
            'destination_id' => $destination->id,
            'action' => 'liked',
        ]);
        $response = $this->actingAs($user)->post(route('itineraries.store'), [
            'title' => 'Weekend nature trip',
            'start_date' => now()->addDay()->toDateString(),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('itineraries', ['user_id' => $user->id, 'title' => 'Weekend nature trip']);
        $this->assertDatabaseHas('itinerary_items', ['destination_id' => $destination->id]);
    }
}