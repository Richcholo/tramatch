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

    /**
     * The suite runs the array cache driver, which never unserialises — so a
     * cache that returns an unusable value could not fail a test until this
     * ran against the database driver the app actually ships with. It 500'd
     * every destination page on the first cache hit when the cache held
     * Eloquent objects (config/cache.php: serializable_classes => false makes
     * every unserialised object __PHP_Incomplete_Class). The cache must hold
     * scalars and the second call must rebuild the same scored models.
     */
    public function test_a_second_recommend_call_survives_a_database_cache_hit(): void
    {
        config(['cache.default' => 'database']);

        $user = User::factory()->create();
        $profile = TravelProfile::create([
            'user_id' => $user->id,
            'budget_level' => 'economy',
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

        $best->tags()->attach([$beach->id, $nature->id]);
        $other->tags()->attach($nature);
        $profile->tags()->attach([$beach->id => ['weight' => 3], $nature->id => ['weight' => 1]]);

        DestinationSwipe::create([
            'user_id' => $user->id,
            'destination_id' => $best->id,
            'action' => 'liked',
        ]);
        DestinationSwipe::create([
            'user_id' => $user->id,
            'destination_id' => $other->id,
            'action' => 'liked',
        ]);

        $service = app(RecommendationService::class);

        // Miss: computes, caches, and returns models.
        $first = $service->recommend($user);
        $this->assertSame($best->id, $first->first()->id);

        // The entry on disk must be the tuple array, not an object.
        $cached = cache()->get('recommendations:'.$user->id);
        $this->assertIsArray($cached);
        $this->assertSame($best->id, $cached[0]['id']);
        $this->assertSame(100.0, $cached[0]['match_score']);
        $this->assertSame([$beach->id, $nature->id], $cached[0]['matched_tag_ids']);

        // Hit: hydrates from the cached scalars. This is the call that 500'd.
        $second = $service->recommend($user);
        $this->assertSame($best->id, $second->first()->id);
        $this->assertSame(100.0, (float) $second->first()->match_score);
        $this->assertSame(
            [$beach->id, $nature->id],
            $second->first()->matched_tags->pluck('id')->all()
        );
        $this->assertNotNull($second->first()->recommendation_reason);
        $this->assertSame($other->id, $second->last()->id);
        $this->assertSame(
            [$nature->id],
            $second->last()->matched_tags->pluck('id')->all()
        );
    }

    /**
     * A database that already holds an entry from the object-caching version
     * must not 500 on the first read: the incomplete object fails the shape
     * check and the set is recomputed and overwritten.
     */
    public function test_an_entry_left_by_the_object_shaped_cache_is_a_miss_not_a_fatal(): void
    {
        config(['cache.default' => 'database']);

        $user = User::factory()->create();
        $profile = TravelProfile::create([
            'user_id' => $user->id,
            'budget_level' => 'economy',
            'trip_duration_days' => 2,
        ]);

        $tag = Tag::create(['name' => 'Beach', 'slug' => 'beach']);
        $destination = Destination::create([
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

        $destination->tags()->attach($tag);
        $profile->tags()->attach($tag, ['weight' => 3]);

        DestinationSwipe::create([
            'user_id' => $user->id,
            'destination_id' => $destination->id,
            'action' => 'liked',
        ]);

        // The old shape: an Eloquent collection under the same key.
        cache()->put(
            'recommendations:'.$user->id,
            collect([$destination]),
            now()->addHour()
        );

        $results = app(RecommendationService::class)->recommend($user);

        $this->assertCount(1, $results);
        $this->assertSame(100.0, (float) $results->first()->match_score);

        // The bad shape has been replaced with the tuple array.
        $this->assertIsArray(cache()->get('recommendations:'.$user->id));
    }
}