<?php

namespace Tests\Feature;

use App\Domain\Matches\MatchScore;
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
 * WHY THE MATCH SCORE IS THE NUMBER IT IS.
 *
 * The score was printed as a bare percentage everywhere it appears — on the
 * recommendations card, on the generation form's candidates, and as an
 * "average match" on every trip. Every input for a sentence already existed
 * (`matched_tags` on the row, the weights on the profile), so the black box
 * was the presentation and never a missing calculation.
 *
 * The invariants:
 *
 * - **THE FLOOR IS STATED, NOT JUST APPLIED.** 30 of every 100 points is a
 *   fixed grant, so "30% match" reads as a bad score to anyone who has not
 *   been told the scale starts there.
 * - **THE SENTENCE RECONCILES WITH THE NUMBER BESIDE IT.** The points
 *   attributed to interests are read back off the stored score, never
 *   recomputed, so rounding cannot make the two disagree.
 * - **A GUEST READS THE NUMBER AND NOT THE TRAVELLER.** The trip's hero is a
 *   shared partial; the explanation describes the owner's own interest
 *   weights, so it is `@auth`-guarded while the percentage stays visible.
 */
class MatchScoreExplanationTest extends TestCase
{
    use RefreshDatabase;

    private User $traveller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traveller = User::factory()->create();

        TravelProfile::create([
            'user_id' => $this->traveller->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 2,
            'preferred_region' => null,
        ]);

        // Beaches 3 and Hiking 2: an uneven split, so a sentence that
        // flattens them into a count cannot pass.
        $this->interest('Beaches', 3);
        $this->interest('Hiking', 2);
    }

    // ------------------------------------------------------- the recommendations card

    public function test_a_card_explains_its_score_with_the_numbers_behind_it(): void
    {
        $place = $this->destination('Saud Beach');
        $this->tags($place, 'Beaches', 'Hiking');
        $this->like($place);

        $html = $this->recommendationsHtml();

        // 5 of 5 interest points are shared, so preference is 100 and the
        // score is the ceiling: 30 floor + 70 earned.
        $this->assertStringContainsString('100%', $html);
        $this->assertStringContainsString('is the 30 starting points', $html);
        $this->assertStringContainsString('plus 70 of the 70', $html);
        $this->assertStringContainsString('5 of your 5 interest points', $html);
        $this->assertStringContainsString('Beaches and Hiking', $html);
    }

    /**
     * THE SENTENCE IS ARITHMETIC, NOT DECORATION: the points it credits to
     * interests plus the floor are the score that is printed beside it. Read
     * back off the score precisely so this cannot drift.
     */
    public function test_the_explanation_reconciles_with_the_score_it_explains(): void
    {
        $place = $this->destination('Nagsasa Cove');
        $this->tags($place, 'Hiking');
        $this->like($place);

        $scored = app(RecommendationService::class)
            ->recommend($this->traveller)
            ->first();

        $explanation = app(MatchScore::class)->explain($scored, $this->traveller->travelProfile);

        $this->assertEquals(
            $explanation['score'],
            round($explanation['earned'] + MatchScore::FLOOR, 2)
        );

        // 2 of 5 points shared = 40% preference = 28 earned + 30 = 58.
        $this->assertEquals(2, $explanation['matched_weight']);
        $this->assertEquals(5, $explanation['total_weight']);
        $this->assertEquals(58.0, $explanation['score']);
        $this->assertStringContainsString('58%', $explanation['reason']);
        $this->assertStringContainsString('plus 28 of the 70', $explanation['reason']);
    }

    /**
     * THE FLOOR THE EXPLANATION QUOTES IS THE FLOOR THE SCORE USES.
     *
     * Two constants in two classes — `MatchScore::FLOOR` and the `+ 30` in
     * `RecommendationService::score()`. If one is retuned and the other is
     * not, every sentence on every page describes a scale the numbers do not
     * live on, and the numbers themselves keep working perfectly.
     */
    public function test_the_floor_the_explanation_quotes_is_the_floor_the_score_uses(): void
    {
        $place = $this->destination('Kapampangan Table');
        $this->tags($place, 'Museums');
        $this->like($place);

        $candidate = app(RecommendationService::class)
            ->candidates($this->traveller)
            ->firstWhere('id', $place->id);

        // Nothing shared, so the score IS the floor — nothing added on top.
        $this->assertEquals(MatchScore::FLOOR, (float) $candidate->match_score);

        $explanation = app(MatchScore::class)
            ->explain($candidate, $this->traveller->travelProfile);

        $this->assertTrue($explanation['shares_nothing']);
        $this->assertSame(0.0, $explanation['earned']);
        $this->assertStringContainsString('is the floor every place starts on', $explanation['reason']);
        $this->assertStringContainsString('shares none of your listed interests', $explanation['reason']);
    }

    // ------------------------------------------------------------- the generation form

    /**
     * THE ROW WITH NO TAG CHIPS IS THE ONE THAT NEEDS THE SENTENCE. A
     * candidate carrying shared interests shows those interests as chips right
     * beside the score, so its number is already visibly earned; the 30% row
     * is the bare number the generation form used to print on its own.
     */
    public function test_the_floor_says_itself_where_nothing_else_explains_it(): void
    {
        $place = $this->destination('Kapampangan Table');
        $this->tags($place, 'Museums');

        // WITH an area: the form lists nothing at all until a province is
        // chosen, and a floor sentence on a province picker would be nothing
        // at all.
        $html = (string) $this->actingAs($this->traveller)
            ->get(route('itineraries.create', ['area' => 'Benguet']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Kapampangan Table', $html);
        $this->assertStringContainsString('is the floor every place starts on', $html);
        $this->assertStringContainsString('shares none of your listed interests', $html);
    }

    public function test_a_candidate_with_shared_interests_is_left_to_its_own_chips(): void
    {
        $place = $this->destination('Saud Beach');
        $this->tags($place, 'Beaches');

        $html = (string) $this->actingAs($this->traveller)
            ->get(route('itineraries.create', ['area' => 'Benguet']))
            ->assertOk()
            ->getContent();

        // The chips beside the score already say what earned it; a sentence
        // repeating that on every row would be noise.
        $this->assertStringNotContainsString('is the floor every place starts on', $html);
    }

    // --------------------------------------------------------------- the trip average

    public function test_a_trip_explains_what_its_average_match_is(): void
    {
        $trip = $this->trip();

        $html = (string) $this->actingAs($this->traveller)
            ->get(route('itineraries.show', $trip))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('average match', $html);
        $this->assertStringContainsString('every place starts on 30', $html);
        $this->assertStringContainsString('earns up to 70 more', $html);
        $this->assertStringContainsString('across 2 stop(s)', $html);
    }

    public function test_the_trip_list_explains_it_too(): void
    {
        $this->trip();

        $html = (string) $this->actingAs($this->traveller)
            ->get(route('itineraries.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('earns up to 70 more', $html);
    }

    /**
     * A GUEST GETS THE PERCENTAGE AND NOT THE TRAVELLER.
     *
     * The hero is the shared partial, so this is a privacy boundary and not a
     * styling one: the sentence describes the owner's interest weights — how
     * many points they carry out of how many — which is nobody's business on
     * a public link. The number itself stays, because it is already on the
     * shared page.
     */
    public function test_a_share_guest_reads_the_number_and_not_the_explanation(): void
    {
        $trip = $this->trip();
        $token = $trip->sharedToken();

        $html = (string) $this->get(route('itineraries.shared', [
            'itinerary' => $trip,
            'token' => $token,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('% average match', $html);
        $this->assertStringNotContainsString('earns up to 70 more', $html);
        $this->assertStringNotContainsString('interest points', $html);
    }

    // ------------------------------------------------------------------- fixtures

    private function recommendationsHtml(): string
    {
        return (string) $this->actingAs($this->traveller)
            ->get(route('recommendations.index'))
            ->assertOk()
            ->getContent();
    }

    private function trip(): Itinerary
    {
        return Itinerary::create([
            'user_id' => $this->traveller->id,
            'title' => 'Benguet weekend',
            'area' => 'Benguet',
            'budget_level' => 'economy',
            'trip_duration_days' => 2,
            'match_score' => 58,
            'total_estimated_cost' => 400,
        ]);
    }

    private function interest(string $name, int $weight): Tag
    {
        $tag = Tag::firstOrCreate(['name' => $name, 'slug' => str($name)->slug()]);

        $this->traveller->travelProfile->tags()->attach($tag->id, ['weight' => $weight]);

        return $tag;
    }

    private function destination(string $name): Destination
    {
        return Destination::create([
            'name' => $name,
            'slug' => str($name)->slug(),
            'description' => 'A place.',
            'province' => 'Benguet',
            'municipality' => 'Baguio',
            'latitude' => 16.3540,
            'longitude' => 120.5860,
            'budget_level' => 'economy',
            'estimated_cost' => 100,
            'recommended_minutes' => 90,
            'is_featured' => true,
            'is_active' => true,
        ]);
    }

    private function tags(Destination $destination, string ...$names): void
    {
        foreach ($names as $name) {
            $tag = Tag::firstOrCreate(['name' => $name, 'slug' => str($name)->slug()]);

            $destination->tags()->attach($tag->id);
        }
    }

    private function like(Destination $destination): void
    {
        DestinationSwipe::create([
            'user_id' => $this->traveller->id,
            'destination_id' => $destination->id,
            'action' => 'liked',
        ]);
    }
}
