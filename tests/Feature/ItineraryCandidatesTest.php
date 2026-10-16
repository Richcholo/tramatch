<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\Itinerary;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use App\Services\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR 0004: generation ranks the catalogue; swipes rank within it.
 *
 * The defect this file exists for was measured, not imagined. Generation drew
 * from `recommend()`, which is liked-only, so the reachable trip length was
 * "liked destinations in one province divided by three". Benguet — the densest
 * province in a 100-row catalogue — has thirteen rows, so a traveller who liked
 * ALL THIRTEEN and asked for seven days got five of them, with days six and
 * seven empty. Doing everything right was not enough.
 *
 * `test_the_ceiling_is_no_longer_the_swipes` is the regression that matters.
 * The others pin the three deliberate rules, because "show everything" would
 * have been an easier change and a worse one.
 */
class ItineraryCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tag $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $profile = TravelProfile::create([
            'user_id' => $this->user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 7,
        ]);

        $this->tag = Tag::create(['name' => 'Nature', 'slug' => 'nature']);
        $profile->tags()->attach($this->tag, ['weight' => 3]);

        // Benguet's real size. Thirteen is not a round number chosen for the
        // test; it is the count the catalogue CSV actually has.
        foreach (range(1, 13) as $index) {
            $this->destination("Benguet {$index}", 'Benguet', ['nature' => true]);
        }

        $this->destination('Elsewhere One', 'Ifugao', ['nature' => true]);
        $this->destination('Mid Market', 'Benguet', ['nature' => true], 'mid-range');
    }

    // ------------------------------------------------------- the ceiling

    public function test_the_ceiling_is_no_longer_the_swipes(): void
    {
        // A traveller who has seen three of thirteen. Under the liked-only gate
        // this produced one day's worth of plan.
        $this->like($this->benguet()[0]);
        $this->like($this->benguet()[1]);
        $this->like($this->benguet()[2]);

        $this->actingAs($this->user)
            ->post(route('itineraries.store'), [
                'title' => 'A whole week',
                'area' => 'Benguet',
                'days' => 7,
                'destination_ids' => $this->benguet()->pluck('id')->all(),
            ])
            ->assertRedirect();

        $placed = $this->user->itineraries()->firstOrFail()->days()->withCount('items')->get()->sum('items_count');

        // All thirteen, not the three they swiped. Days five onward are still
        // empty, and that is the honest limit: thirteen destinations at three
        // stops a day is four and a third days. The ceiling is now what the
        // catalogue holds rather than what the traveller happened to tap.
        $this->assertSame(13, $placed);
    }

    public function test_a_destination_the_traveller_never_swiped_can_be_planned(): void
    {
        $this->like($this->benguet()[0]);

        $never = $this->benguet()[7];

        $this->assertDatabaseMissing('destination_swipes', ['destination_id' => $never->id]);

        $this->actingAs($this->user)
            ->post(route('itineraries.store'), [
                'title' => 'Planned without liking it first',
                'area' => 'Benguet',
                'days' => 1,
                'destination_ids' => [$never->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('itinerary_items', ['destination_id' => $never->id]);
    }

    // ------------------------------------------------------ the rules

    public function test_liked_destinations_come_first_and_are_marked(): void
    {
        $liked = $this->benguet()[9];
        $this->like($liked);

        $candidates = app(RecommendationService::class)->candidates($this->user, 'Benguet');

        $this->assertTrue($candidates->first()->is_liked, 'the liked destination did not sort first');
        $this->assertTrue($candidates->firstWhere('id', $liked->id)->is_liked);

        // Every candidate is marked either way, so the view can never render an
        // unmarked row as though it were one of the traveller's own picks.
        foreach ($candidates as $candidate) {
            $this->assertIsBool($candidate->is_liked);
        }
    }

    public function test_a_passed_destination_is_excluded_and_refused(): void
    {
        $passed = $this->benguet()[4];

        DestinationSwipe::create([
            'user_id' => $this->user->id,
            'destination_id' => $passed->id,
            'action' => 'passed',
        ]);

        $candidates = app(RecommendationService::class)->candidates($this->user, 'Benguet');

        $this->assertFalse($candidates->contains('id', $passed->id), 'a passed destination was offered as a candidate');

        // And the POST refuses it even if the browser sends it anyway, because
        // "not this one" is the one answer the traveller gave deliberately.
        $this->actingAs($this->user)
            ->post(route('itineraries.store'), [
                'title' => 'Against their better judgement',
                'area' => 'Benguet',
                'days' => 1,
                'destination_ids' => [$passed->id],
            ])
            ->assertSessionHasErrors('destination_ids');

        $this->assertSame(0, Itinerary::count());
    }

    public function test_the_area_and_the_budget_both_still_narrow_it(): void
    {
        $candidates = app(RecommendationService::class)->candidates($this->user, 'Benguet');

        // One seeded row is mid-range, and the traveller is economy: it is not a
        // candidate even though it is active and in the chosen province.
        $this->assertFalse($candidates->contains('name', 'Mid Market'));
        $this->assertFalse($candidates->contains('province', 'Ifugao'));

        $everywhere = app(RecommendationService::class)->candidates($this->user);

        $this->assertTrue($everywhere->contains('province', 'Ifugao'), 'an unfiltered pool must span provinces, or the province list stays a few liked places');
    }

    public function test_a_candidate_matching_none_of_the_interests_still_scores_rather_than_disappearing(): void
    {
        $untagged = $this->destination('Untagged Spot', 'Benguet');

        $candidate = app(RecommendationService::class)
            ->candidates($this->user, 'Benguet')
            ->firstWhere('id', $untagged->id);

        $this->assertNotNull($candidate, 'a destination matching nothing was filtered out of the pool entirely');

        // The formula's floor, not a null: the generator range-checks
        // match_score and a null would fail every selected row.
        $this->assertSame(30.0, (float) $candidate->match_score);
    }

    // ------------------------------------------------------ the surface

    public function test_the_form_offers_a_province_the_traveller_liked_nothing_in(): void
    {
        // No swipes at all. Under the old gate this page was a dead end with a
        // single placeholder option and no link to Discover anywhere on it.
        $this->actingAs($this->user)
            ->get(route('itineraries.create'))
            ->assertOk()
            ->assertSee('value="Benguet"', false)
            ->assertSee('value="Ifugao"', false);

        // And choosing one actually produces destinations, which is the part
        // that used to be empty: every one of these has zero swipes.
        $this->actingAs($this->user)
            ->get(route('itineraries.create', ['area' => 'Ifugao']))
            ->assertOk()
            ->assertSee('Elsewhere One')
            ->assertSee('data-destination-checkbox', false);
    }

    public function test_the_recommendations_page_stays_liked_only(): void
    {
        $this->like($this->benguet()[0]);

        // ADR 0004 widened generation, not "your liked places". Widening both
        // would make that page disagree with its own heading.
        $recommendations = app(RecommendationService::class)->recommend($this->user);

        $this->assertCount(1, $recommendations);
        $this->assertSame(
            $this->benguet()[0]->id,
            $recommendations->first()->id
        );
    }

    // ---------------------------------------------------------- helpers

    private function destination(
        string $name,
        string $province,
        array $tags = [],
        string $budget = 'economy'
    ): Destination {
        $destination = Destination::create([
            'name' => $name,
            'slug' => str($name)->slug(),
            'description' => 'A place.',
            'province' => $province,
            'latitude' => 16.4,
            'longitude' => 120.6,
            'budget_level' => $budget,
            'estimated_cost' => 100,
            'recommended_minutes' => 90,
        ]);

        foreach (array_keys($tags) as $tag) {
            $destination->tags()->attach($this->tag);
        }

        return $destination;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Destination>
     */
    private function benguet()
    {
        return Destination::where('province', 'Benguet')
            ->where('budget_level', 'economy')
            ->orderBy('name')
            ->get();
    }

    private function like(Destination $destination): void
    {
        DestinationSwipe::create([
            'user_id' => $this->user->id,
            'destination_id' => $destination->id,
            'action' => 'liked',
        ]);
    }
}