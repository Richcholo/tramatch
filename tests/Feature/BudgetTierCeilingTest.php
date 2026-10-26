<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetTierCeilingTest extends TestCase
{
    use RefreshDatabase;

    private function destination(string $name, string $tier): Destination
    {
        return Destination::create([
            'name' => $name,
            'slug' => str()->slug($name),
            'province' => 'Benguet',
            'municipality' => 'Baguio',
            'budget_level' => $tier,
            'recommended_minutes' => 60,
            'estimated_cost' => 100,
            'description' => 'A place.',
            'latitude' => 16.41,
            'longitude' => 120.6,
        ]);
    }

    public function test_the_tier_list_is_a_ceiling_derived_from_the_ordered_tiers(): void
    {
        $this->assertSame(['economy'], Destination::affordableTiers('economy'));
        $this->assertSame(['economy', 'mid-range'], Destination::affordableTiers('mid-range'));
        $this->assertSame(['economy', 'mid-range', 'premium'], Destination::affordableTiers('premium'));
    }

    public function test_a_premium_travellers_deck_includes_every_cheaper_tier(): void
    {
        $user = User::factory()->create();

        TravelProfile::create([
            'user_id' => $user->id,
            'budget_level' => 'premium',
            'group_size' => 2,
            'trip_duration_days' => 1,
        ]);

        $tag = Tag::create(['name' => 'Nature', 'slug' => 'nature']);
        $user->travelProfile->tags()->attach($tag->id, ['weight' => 3]);

        foreach (['economy', 'mid-range', 'premium'] as $tier) {
            $this->destination(ucfirst($tier).' Place', $tier)->tags()->attach($tag->id);
        }

        $names = app(\App\Services\SwipeDeckService::class)
            ->cards($user)
            ->pluck('name')
            ->all();

        sort($names);

        $this->assertSame(['Economy Place', 'Mid-range Place', 'Premium Place'], $names);
    }

    public function test_an_economy_traveller_never_sees_a_dearer_tier(): void
    {
        $user = User::factory()->create();

        TravelProfile::create([
            'user_id' => $user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 1,
        ]);

        $tag = Tag::create(['name' => 'Nature', 'slug' => 'nature']);
        $user->travelProfile->tags()->attach($tag->id, ['weight' => 3]);

        foreach (['economy', 'mid-range', 'premium'] as $tier) {
            $this->destination(ucfirst($tier).' Place', $tier)->tags()->attach($tag->id);
        }

        $names = app(\App\Services\SwipeDeckService::class)
            ->cards($user)
            ->pluck('name')
            ->all();

        $this->assertSame(['Economy Place'], $names);
    }

    public function test_saving_the_budget_ceiling_drops_a_cached_recommendation_set(): void
    {
        $user = User::factory()->create();

        $profile = TravelProfile::create([
            'user_id' => $user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 1,
        ]);

        $tag = Tag::create(['name' => 'Nature', 'slug' => 'nature']);
        $profile->tags()->attach($tag->id, ['weight' => 3]);

        $destination = $this->destination('Economy Place', 'economy');
        $destination->tags()->attach($tag->id);

        \App\Models\DestinationSwipe::create([
            'user_id' => $user->id,
            'destination_id' => $destination->id,
            'action' => 'liked',
        ]);

        $service = app(\App\Services\RecommendationService::class);

        $this->assertCount(1, $service->recommend($user));

        // Cache is warm. Changing the tier to premium must forget it, so the
        // next read reflects the new profile rather than the old one.
        $this->actingAs($user)->put(route('preferences.update'), [
            'budget_level' => 'premium',
            'group_size' => 2,
            'trip_duration_days' => 1,
            'weights' => [$tag->id => 3],
        ]);

        // Preferences save wipes swipes (documented reset), so the honest
        // check is the cache key itself: it must not still hold a set.
        $this->assertNull(\Illuminate\Support\Facades\Cache::get(
            \App\Services\RecommendationService::CACHE_KEY_PREFIX.$user->id
        ));
    }
}
