<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE EMPTY DECK, AND EVERY SENTENCE ON IT IS TRUE.
 *
 * "You are all caught up." was the one sentence for four different outcomes,
 * and it was handed to a traveller who had swiped NOTHING — a headline about
 * an achievement they had never entered. Four filters can empty the deck
 * (budget tier, region, shared interests, already swiped) and the fix
 * attributes the message to the FIRST filter that emptied it, in the order
 * `cards()` applies them.
 *
 * Two invariants run through every branch:
 *
 * **NO FALLBACK CARDS.** The answer is why the deck is empty plus two ways
 * out, never a synthetic card pretending there is still work in it.
 *
 * **"ALL CAUGHT UP" NEVER APPEARS.** Not in any empty state, for any state of
 * swiping — it was the lie, so the copy is asserted absent in every branch
 * these tests build.
 */
class DeckEmptyStatesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = false;

    // ------------------------------------------------- interests matched nothing

    public function test_nothing_shared_the_interests_is_said_plainly(): void
    {
        $destination = $this->destination('Burnham Park', 'economy');
        $this->destination('Wright Park', 'economy');

        $hiker = Tag::firstOrCreate(['name' => 'Hiking', 'slug' => 'hiking']);
        $destination->tags()->attach($hiker->id);

        $this->actingAsUser(tags: ['Beaches' => 3]);

        $html = $this->deckHtml();

        $this->assertStringContainsString('shares your interests', $html);
        $this->assertStringNotContainsString('all caught up', $html);
        $this->assertStringContainsString('data-swipe-empty', $html);
        $this->assertStringNotContainsString('data-swipe-deck', $html);
    }

    /**
     * THE HONEST GAP IS FACTUAL ACROSS THE WHOLE CATALOGUE.
     *
     * Three romantic places exist — at another budget level. "Nothing shares
     * your interests" ALONE would imply they are not in the catalogue, which
     * is the ADR-0002 problem wearing a friendlier sentence: budget is an
     * exact match, so coverage has to be reported per tier, and the traveller
     * is owed the number of places that DO carry their interests elsewhere.
     */
    public function test_interests_that_match_at_another_budget_say_so(): void
    {
        $this->destination('Burnham Park', 'economy');

        $romantic = Tag::firstOrCreate(['name' => 'Romantic', 'slug' => 'romantic']);
        $luxury = $this->destination('Caliraya Resort', 'luxury');
        $luxury->tags()->attach($romantic->id);

        $this->actingAsUser(tags: ['Romantic' => 2]);

        $html = $this->deckHtml();

        $this->assertStringContainsString('DO carry your interests', $html);
        $this->assertStringContainsString('another budget level', $html);
        $this->assertStringNotContainsString('all caught up', $html);
    }

    // ------------------------------------------------------------ region emptied

    public function test_a_region_that_excludes_everything_is_named(): void
    {
        $destination = $this->destination('Burnham Park', 'economy', 'Benguet');
        $hiker = Tag::firstOrCreate(['name' => 'Hiking', 'slug' => 'hiking']);
        $destination->tags()->attach($hiker->id);

        $this->actingAsUser(tags: ['Hiking' => 3], region: 'Batanes');

        $html = $this->deckHtml();

        $this->assertStringContainsString('Batanes has no economy places', $html);

        // The named count is the claim that can be checked against the page:
        // the region excluded places that DO exist, and the copy says how many.
        $this->assertStringContainsString('1 of them', $html);
        $this->assertStringNotContainsString('all caught up', $html);
    }

    // -------------------------------------------------------------- tier emptied

    public function test_an_empty_tier_says_so_instead_of_claiming_achievement(): void
    {
        $this->actingAsUser(tier: 'luxury', tags: ['Hiking' => 3]);

        $html = $this->deckHtml();

        $this->assertStringContainsString('Nothing is curated for Luxury yet', $html);
        $this->assertStringNotContainsString('all caught up', $html);
        $this->assertStringContainsString('data-swipe-empty', $html);
    }

    // -------------------------------------------------------------- all swiped

    public function test_swiping_everything_is_a_working_state_with_two_ways_out(): void
    {
        $destination = $this->destination('Burnham Park', 'economy');
        $hiker = Tag::firstOrCreate(['name' => 'Hiking', 'slug' => 'hiking']);
        $destination->tags()->attach($hiker->id);

        $this->actingAsUser(tags: ['Hiking' => 3]);

        DestinationSwipe::create([
            'user_id' => $this->userId,
            'destination_id' => $destination->id,
            'action' => 'passed',
        ]);

        $html = $this->deckHtml();

        $this->assertStringContainsString('swiped every place that matched', $html);
        $this->assertStringNotContainsString('all caught up', $html);

        // TWO ways out: the reset (a form — it writes) and the liked list
        // (a link — it does not). An empty state offering ONE exit for
        // both "rewrite my decision" and "go plan from my shortlist"
        // collapses two different intents into one control.
        //
        // The BUTTON LABEL is what is pinned, not `method="POST"`: the
        // layout's logout dialog carries a POST form on every page, and a
        // bare method assertion passed even with this reset removed —
        // measured while falsifying, and exactly the layout-pollution trap
        // ItineraryShareTest already navigates.
        $this->assertStringContainsString('Show me the passed places again', $html);
        $this->assertStringContainsString(route('recommendations.index'), $html);
    }

    /**
     * THE RESET FROM THE EMPTY STATE IS REAL. A button that POSTs to the
     * deck's own reset endpoint and leaves the deck still empty would be
     * dead — the exact shape of the logged-out likes-vs-empty bug the record
     * already warns about.
     */
    public function test_the_reset_from_the_empty_state_rebuilds_the_deck(): void
    {
        $destination = $this->destination('Burnham Park', 'economy');
        $hiker = Tag::firstOrCreate(['name' => 'Hiking', 'slug' => 'hiking']);
        $destination->tags()->attach($hiker->id);

        $this->actingAsUser(tags: ['Hiking' => 3]);

        DestinationSwipe::create([
            'user_id' => $this->userId,
            'destination_id' => $destination->id,
            'action' => 'passed',
        ]);

        $this->post(route('discover.reset'))->assertRedirect();

        $html = $this->deckHtml();

        $this->assertStringContainsString('data-swipe-deck', $html, 'the reset left a swiped deck empty');
    }

    // ------------------------------------------------------ the deck is not empty

    public function test_the_empty_state_is_not_rendered_beside_cards(): void
    {
        $destination = $this->destination('Burnham Park', 'economy');
        $second = $this->destination('Wright Park', 'economy');
        $hiker = Tag::firstOrCreate(['name' => 'Hiking', 'slug' => 'hiking']);
        $destination->tags()->attach($hiker->id);
        $second->tags()->attach($hiker->id);

        $this->actingAsUser(tags: ['Hiking' => 3]);

        DestinationSwipe::create([
            'user_id' => $this->userId,
            'destination_id' => $destination->id,
            'action' => 'passed',
        ]);

        $html = $this->deckHtml();

        $this->assertStringContainsString('data-swipe-deck', $html);
        $this->assertStringNotContainsString('data-swipe-empty', $html);
    }

    /**
     * THE SWIPE LAYER IS THE LAST FILTER, NOT THE ONLY REASON.
     *
     * A traveller with swipes AND no tag overlap gets the interests branch,
     * never "you swiped everything": their swipes were made, but the pool
     * being empty is about what their interests fit. Attribution is by the
     * first empty layer, exactly as the service promises.
     */
    public function test_swipes_do_not_steal_the_credits_for_an_interest_gap(): void
    {
        $destination = $this->destination('Burnham Park', 'economy');
        $hiker = Tag::firstOrCreate(['name' => 'Hiking', 'slug' => 'hiking']);
        $destination->tags()->attach($hiker->id);

        $this->actingAsUser(tags: ['Beaches' => 3]);

        DestinationSwipe::create([
            'user_id' => $this->userId,
            'destination_id' => $destination->id,
            'action' => 'liked',
        ]);

        $html = $this->deckHtml();

        $this->assertStringContainsString('shares your interests', $html);
        $this->assertStringNotContainsString('swiped every place', $html);
    }

    // ---------------------------------------------------------------- the helpers

    private int $userId;

    private function actingAsUser(string $tier = 'economy', array $tags = [], ?string $region = null): void
    {
        $user = User::factory()->create();
        $this->userId = $user->id;

        TravelProfile::create([
            'user_id' => $user->id,
            'budget_level' => $tier,
            'group_size' => 2,
            'trip_duration_days' => 3,
            'preferred_region' => $region,
        ]);

        foreach ($tags as $name => $weight) {
            // firstOrCreate, NOT create: several tests put the SAME tag on both
            // a destination and the traveller, and second create dies on the
            // unique tags.slug index before the assertion is even reached.
            $tag = Tag::firstOrCreate(['name' => $name, 'slug' => str($name)->slug()]);

            $user->travelProfile->tags()->attach($tag->id, ['weight' => $weight]);
        }

        $this->actingAs($user);
    }

    private function destination(
        string $name,
        string $tier,
        string $province = 'Benguet'
    ): Destination {
        return Destination::create([
            'name' => $name,
            'slug' => str($name)->slug(),
            'description' => 'A place.',
            'province' => $province,
            'municipality' => 'Baguio',
            'latitude' => 16.3540,
            'longitude' => 120.5860,
            'budget_level' => $tier,
            'estimated_cost' => 100,
            'recommended_minutes' => 90,
            'is_featured' => true,
            'is_active' => true,
        ]);
    }

    private function deckHtml(): string
    {
        return (string) $this->get(route('discover.index'))->assertOk()->getContent();
    }
}
