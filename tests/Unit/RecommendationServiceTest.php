<?php

namespace Tests\Unit;

use App\Models\Destination;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\DestinationSwipe;

class RecommendationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_ranks_matching_destinations(): void
    {
        $user = User::factory()->create();
        $profile = TravelProfile::create([
            'user_id' => $user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 2,
        ]);

        $beach = Tag::create(['name' => 'Beach', 'slug' => 'beach']);
        $nature = Tag::create(['name' => 'Nature', 'slug' => 'nature']);

        $best = Destination::create([
            'name' => 'Best Match',
            'slug' => 'best-match',
            'description' => 'A beach destination.',
            'province' => 'Batangas',
            'latitude' => 13.7,
            'longitude' => 120.9,
            'budget_level' => 'economy',
            'estimated_cost' => 1000,
            'recommended_minutes' => 120,
        ]);

        $other = Destination::create([
            'name' => 'Other Match',
            'slug' => 'other-match',
            'description' => 'A nature destination.',
            'province' => 'Laguna',
            'latitude' => 14.3,
            'longitude' => 121.4,
            'budget_level' => 'economy',
            'estimated_cost' => 1000,
            'recommended_minutes' => 120,
        ]);

        $best->tags()->attach($beach);
        $other->tags()->attach($nature);
        $profile->tags()->attach($beach, ['weight' => 3]);
        
        DestinationSwipe::create([
            'user_id' => $user->id,
            'destination_id' => $best->id,
            'action' => 'liked',
        ]);

        $results = app(RecommendationService::class)->recommend($user);

        $this->assertCount(1, $results);
        $this->assertSame($best->id, $results->first()->id);
        $this->assertSame(100.0, (float) $results->first()->match_score);
    }

    public function test_it_excludes_destinations_outside_the_budget(): void
    {
        $user = User::factory()->create();
        $profile = TravelProfile::create([
            'user_id' => $user->id,
            'budget_level' => 'economy',
            'group_size' => 1,
            'trip_duration_days' => 1,
        ]);

        $tag = Tag::create(['name' => 'Beach', 'slug' => 'beach']);
        $destination = Destination::create([
            'name' => 'Premium Beach',
            'slug' => 'premium-beach',
            'description' => 'A premium destination.',
            'province' => 'Batangas',
            'latitude' => 13.7,
            'longitude' => 120.9,
            'budget_level' => 'premium',
            'estimated_cost' => 5000,
            'recommended_minutes' => 120,
        ]);

        $destination->tags()->attach($tag);
        $profile->tags()->attach($tag, ['weight' => 3]);

        $this->assertCount(0, app(RecommendationService::class)->recommend($user));
    }
}