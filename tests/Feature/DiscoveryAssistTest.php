<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE DISCOVERY ASSIST, AND THE ONLY THING IN THIS APP THAT CALLS GEMINI.
 *
 * "Stuck? Ask for a nudge" takes the deck's own candidate list — already
 * filtered to the traveller's tier and their decided cards — sends it with the
 * interest weights and the tier to Gemini over one request, and shows the
 * picks back ON THE PAGE THE FORM LANDS ON.
 *
 * The invariants, all of them about honesty rather than about cleverness:
 *
 * - **A PROMPT IS NOT ENFORCEMENT.** Every id in the reply must be one of the
 *   ids that were sent, and each surviving pick is re-read from the catalogue
 *   — active, still published, in the traveller's tier — before anything is
 *   displayed. An invented id is dropped and counted, never shown.
 * - **ONE REQUEST, NEVER A LOOP.** Even when the upstream fails. The count is
 *   read from `planning.limits.assist_attempts`, which is what makes the
 *   config claim load-bearing rather than a number on a page.
 * - **A FREE TIER IS A BUDGET.** A double click is refused by the local
 *   cooldown WITHOUT a second wire call, and the failure states are named to
 *   the traveller rather than logged to nothing.
 * - **NO PERSONAL DATA LEAVES.** The prompt carries interests, tier and
 *   catalogue rows — free-tier content is used to improve Google's products,
 *   so no name and no email goes out.
 * - **A CONTROL THAT CANNOT WORK IS NOT OFFERED.** Without a configured key
 *   the button is not rendered, and the config read is a presence check that
 *   never leaks the key.
 */
class DiscoveryAssistTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $other;

    /** @var Collection<int, Destination> */
    private Collection $candidates;

    private Destination $premium;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.key' => 'test-gemini-key']);

        $this->user = User::factory()->create();
        $this->other = User::factory()->create();

        $profile = TravelProfile::create([
            'user_id' => $this->user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 2,
            // NO REGION PREFERENCE by default, so every economy candidate
            // deals. A region filter is a server-side candidate decision of
            // its own and has the dedicated prompt test below.
            'preferred_region' => null,
        ]);

        $light = Tag::create(['name' => 'Nature', 'slug' => 'nature']);
        $museum = Tag::create(['name' => 'Museums', 'slug' => 'museums']);

        /*
         * Three candidates the deck deals, all economy, all carrying the
         * tags the profile weights. A fourth exists in another tier: it is
         * REAL and ACTIVE but the deck never deals it, so a model reply
         * naming it tests the tier boundary, not the unknown id.
         */
        $this->candidates = collect([
            $this->candidate('Burnham Park', 'Baguio', 'Benguet', [$light], 60),
            $this->candidate('Mines View Park', 'Baguio', 'Benguet', [$light], 45),
            $this->candidate('Pinto Art Museum', 'Antipolo', 'Rizal', [$museum], 250),
        ]);

        $this->premium = $this->candidate('The Manor', 'Baguio', 'Benguet', [$light], 9000);

        $this->premium->update(['budget_level' => 'mid-range']);

        $profile->tags()->attach($light, ['weight' => 3]);
        $profile->tags()->attach($museum, ['weight' => 1]);

        // A second traveller with their OWN profile: the per-traveller
        // cooldown test needs someone legitimate to ask alongside $this->user.
        $otherProfile = TravelProfile::create([
            'user_id' => $this->other->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 2,
            'preferred_region' => null,
        ]);

        $otherProfile->tags()->attach($light, ['weight' => 2]);
    }

    // ------------------------------------------------------------ the reply

    #[Test]
    public function a_valid_reply_shows_its_picks_and_labels_the_model_words(): void
    {
        $this->fakeGemini([
            'picks' => [
                ['id' => $this->candidates[1]->id, 'why' => 'The view deck rewards someone who rated nature highest.'],
                ['id' => $this->candidates[0]->id, 'why' => 'A walkable lake park suits a first Baguio afternoon.'],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('discover.assist'))
            ->assertRedirect(route('discover.index'));

        $this->assertSame('ok', $response->getSession()->get('assist_result.status'));

        $page = $this->followRedirects($response)->assertOk()->getContent();

        $this->assertStringContainsString('Mines View Park', $page);
        $this->assertStringContainsString('Burnham Park', $page);
        $this->assertStringContainsString('Gemini', $page);

        // The one thing the model contributes is a sentence, and it travels
        // with the label that says it is unchecked — the panel never implies
        // our catalogue verified it.
        $this->assertStringContainsString("the model's own words, unchecked", $page);

        // Ask-over and the rejected count are part of the honest panel.
        $this->assertStringContainsString($this->candidates->count().' places', $page);
    }

    #[Test]
    public function an_invented_id_is_dropped_and_counted(): void
    {
        $this->fakeGemini([
            'picks' => [
                ['id' => 999999, 'why' => 'A place this app has never heard of.'],
                ['id' => $this->candidates[0]->id, 'why' => 'This one is real.'],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('discover.assist'))
            ->assertRedirect(route('discover.index'));

        $session = $response->getSession();

        // The real pick still shows; the invented one is neither rendered nor
        // silently forgotten.
        $this->assertSame('ok', $session->get('assist_result.status'));
        $this->assertSame(1, count($session->get('assist_result.suggestions')));

        $page = $this->followRedirects($response)->assertOk()->getContent();

        $this->assertStringContainsString('Burnham Park', $page);

        // The honest arithmetic over the whole reply: "named minus shown".
        // One pick displayed, one invented id held back, and the page says so
        // instead of letting a short list read as the whole reply.
        $this->assertStringContainsString('1 of the picks it named was held back', $page);
        $this->assertStringContainsString('not displayed', $page);
    }

    #[Test]
    public function a_reply_of_only_invented_ids_is_a_reported_failure(): void
    {
        $this->fakeGemini([
            'picks' => [
                ['id' => 999999, 'why' => 'Not even close to a real row.'],
            ],
        ]);

        $page = $this->followRedirects(
            $this->actingAs($this->user)->post(route('discover.assist'))
        )->assertOk()->getContent();

        $this->assertStringNotContainsString('data-assist-panel', $page);
        $this->assertStringContainsString('did not name any place from the catalogue', $page);
    }

    #[Test]
    public function a_real_destination_outside_the_tier_is_refused(): void
    {
        $this->fakeGemini([
            'picks' => [
                ['id' => $this->premium->id, 'why' => 'Luxury is not this trip though.'],
            ],
        ]);

        $page = $this->followRedirects(
            $this->actingAs($this->user)->post(route('discover.assist'))
        )->assertOk()->getContent();

        $this->assertStringNotContainsString('The Manor', $page, 'A pick outside the budget tier reached the panel.');
        $this->assertStringContainsString('did not name any place from the catalogue', $page);
    }

    #[Test]
    public function a_parked_or_decided_destination_is_never_suggested(): void
    {
        // A like takes the card out of the deck, so it is also out of the
        // candidate list the prompt is built from.
        DestinationSwipe::create([
            'user_id' => $this->user->id,
            'destination_id' => $this->candidates[0]->id,
            'action' => 'liked',
        ]);

        $this->fakeGemini([
            'picks' => [
                ['id' => $this->candidates[0]->id, 'why' => 'Already decided, so out of bounds.'],
            ],
        ]);

        $page = $this->followRedirects(
            $this->actingAs($this->user)->post(route('discover.assist'))
        )->assertOk()->getContent();

        $this->assertStringNotContainsString('Burnham Park', $page);
        $this->assertStringContainsString('did not name any place from the catalogue', $page);
    }

    // ------------------------------------------------------------ the prompt

    #[Test]
    public function the_prompt_carries_the_catalogue_and_no_personal_data(): void
    {
        $this->fakeGemini([
            'picks' => [
                ['id' => $this->candidates[0]->id, 'why' => 'Real pick, ignored text.'],
            ],
        ]);

        $this->actingAs($this->user)->post(route('discover.assist'));

        $this->assertCount(1, $this->capturedRequests);

        $body = (string) $this->capturedRequests[0]->body();

        // The catalogue rows and the interest weights are the point of the
        // prompt, so the feature is dead without them.
        $this->assertStringContainsString('Burnham Park', $body);
        $this->assertStringContainsString('budget tier \"economy\"', $body);
        $this->assertStringContainsString('Nature (weight 3)', $body);
        $this->assertStringContainsString('picks', $body);

        // NO PERSONAL DATA. Free-tier content is used to improve Google's
        // products, so a traveller's name and email do not travel.
        $this->assertStringNotContainsString($this->user->name, $body);
        $this->assertStringNotContainsString($this->user->email, $body);
    }

    #[Test]
    public function a_region_preference_thins_the_candidates_before_the_prompt(): void
    {
        $traveller = User::factory()->create();

        $profile = TravelProfile::create([
            'user_id' => $traveller->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 2,
            'preferred_region' => 'Baguio',
        ]);

        $profile->tags()->attach(
            Tag::where('slug', 'nature')->first(),
            ['weight' => 2]
        );

        $this->fakeGemini([
            'picks' => [
                ['id' => $this->candidates[0]->id, 'why' => 'Inside the region.'],
            ],
        ]);

        $this->actingAs($traveller)->post(route('discover.assist'));

        $this->assertCount(1, $this->capturedRequests);

        // The region is ASKED for in the prompt AND enforced before it: the
        // Antipolo museum never reaches the model as a candidate, because a
        // region rule the server has already applied is stronger than one the
        // model is asked to honour.
        $body = (string) $this->capturedRequests[0]->body();

        $this->assertStringContainsString('region preference \"Baguio\"', $body);
        $this->assertStringNotContainsString('Pinto Art Museum', $body);
        $this->assertStringContainsString('Burnham Park', $body);
    }

    #[Test]
    public function the_reply_is_capped_and_deduplicated(): void
    {
        $this->fakeGemini([
            'picks' => [
                ['id' => $this->candidates[0]->id, 'why' => 'First seat.'],
                ['id' => $this->candidates[0]->id, 'why' => 'Repeat of the same row.'],
                ['id' => $this->candidates[1]->id, 'why' => 'Second seat.'],
                ['id' => $this->candidates[2]->id, 'why' => 'Third seat.'],
                ['id' => 999999, 'why' => 'Fourth, invented.'],
            ],
        ]);

        $session = $this->actingAs($this->user)
            ->post(route('discover.assist'))
            ->assertRedirect(route('discover.index'))
            ->getSession();

        $result = $session->get('assist_result');

        // Three real picks shown, in the model's own order, with the invented
        // fourth and the duplicate counted as named-but-not-shown.
        $this->assertSame(3, count($result['suggestions']));
        $this->assertSame($this->candidates[0]->id, $result['suggestions'][0]['destination']->id);
        $this->assertSame($this->candidates[1]->id, $result['suggestions'][1]['destination']->id);
        $this->assertSame($this->candidates[2]->id, $result['suggestions'][2]['destination']->id);
        $this->assertSame(2, $result['rejected']);
    }

    #[Test]
    public function a_pick_with_no_usable_reason_is_not_a_recommendation(): void
    {
        $this->fakeGemini([
            'picks' => [
                ['id' => $this->candidates[0]->id, 'why' => '   '],
            ],
        ]);

        $page = $this->followRedirects(
            $this->actingAs($this->user)->post(route('discover.assist'))
        )->assertOk()->getContent();

        $this->assertStringContainsString('did not name any place from the catalogue', $page);
    }

    // --------------------------------------------------------------- budget

    #[Test]
    public function one_request_is_made_even_when_the_upstream_fails(): void
    {
        $this->fakeHttp(Http::response('Server exploded', 500));

        $page = $this->followRedirects(
            $this->actingAs($this->user)->post(route('discover.assist'))
        )->assertOk()->getContent();

        // The count is read from the config the demo claims, which is what
        // keeps the config claim load-bearing: a retry loop added five lines
        // under this test fails it twice.
        $this->assertSame(
            (int) config('planning.limits.assist_attempts'),
            count($this->capturedRequests)
        );

        $this->assertStringContainsString('not answering', $page);
        $this->assertStringContainsString('Try again shortly', $page);
    }

    #[Test]
    public function a_throttled_reply_reports_the_free_tier_honestly(): void
    {
        $this->fakeHttp(Http::response('rate limited', 429));

        $page = $this->followRedirects(
            $this->actingAs($this->user)->post(route('discover.assist'))
        )->assertOk()->getContent();

        $this->assertStringContainsString('out of free requests', $page);
        $this->assertStringNotContainsString('Gemini', $page);
    }

    #[Test]
    public function a_second_click_inside_the_cooldown_is_refused_without_a_wire_call(): void
    {
        $this->fakeGemini([
            'picks' => [
                ['id' => $this->candidates[0]->id, 'why' => 'The only real ask.'],
            ],
        ]);

        $this->actingAs($this->user)->post(route('discover.assist'));

        $this->assertCount(1, $this->capturedRequests);

        $page = $this->followRedirects(
            $this->actingAs($this->user)->post(route('discover.assist'))
        )->assertOk()->getContent();

        // The cooldown is spent locally: a free tier measured in requests per
        // minute is not to be hammered by a double click reporting a refusal.
        $this->assertCount(1, $this->capturedRequests);
        $this->assertStringContainsString('You just asked for a suggestion', $page);
        $this->assertStringContainsString('try again in', $page);
    }

    #[Test]
    public function a_cooldown_is_recorded_for_an_erroring_attempt_too(): void
    {
        $this->fakeHttp(Http::response('down', 500));
        $this->actingAs($this->user)->post(route('discover.assist'));

        $this->fakeGemini([
            'picks' => [
                ['id' => $this->candidates[0]->id, 'why' => 'Would have been a fine pick.'],
            ],
        ]);

        $page = $this->followRedirects(
            $this->actingAs($this->user)->post(route('discover.assist'))
        )->assertOk()->getContent();

        // A failure spent the free tier's good will too; an immediate retry
        // against a host that just answered 500 doubles the pressure.
        $this->assertCount(1, $this->capturedRequests);
        $this->assertStringContainsString('You just asked for a suggestion', $page);
    }

    #[Test]
    public function a_second_traveller_is_not_kept_waiting_for_the_first(): void
    {
        $this->fakeGemini([
            'picks' => [
                ['id' => $this->candidates[0]->id, 'why' => 'Per-traveller ask.'],
            ],
        ]);

        $this->actingAs($this->user)->post(route('discover.assist'));
        $this->actingAs($this->other)->post(route('discover.assist'));

        // The cooldown keys on the traveller, not the server: two people
        // asking is two requests, not a shared queue.
        $this->assertCount(2, $this->capturedRequests);
    }

    // --------------------------------------------------------------- shapes

    #[Test]
    public function a_reply_that_is_not_the_json_shape_reports_its_own_state(): void
    {
        $this->fakeHttp(Http::response([
            'candidates' => [
                ['content' => ['parts' => [['text' => 'I would suggest Burnham Park, honestly.']]]],
            ],
        ]));

        $page = $this->followRedirects(
            $this->actingAs($this->user)->post(route('discover.assist'))
        )->assertOk()->getContent();

        $this->assertStringContainsString('did not come back in the shape we asked for', $page);
        $this->assertCount(1, $this->capturedRequests);
    }

    #[Test]
    public function a_traveller_with_no_profile_never_reaches_the_wire(): void
    {
        $profileless = User::factory()->create();

        $response = $this->actingAs($profileless)
            ->post(route('discover.assist'));

        // The deck page redirects a profile-less traveller to preferences
        // before anything; the assist owes them the same fence, so no model
        // call is spent on someone whose interests the request could not
        // carry.
        $response->assertRedirect(route('preferences.edit'));
        $this->assertCount(0, $this->capturedRequests);
    }

    #[Test]
    public function a_profiled_traveller_with_an_empty_tier_pool_is_told_so(): void
    {
        foreach ($this->candidates as $candidate) {
            $candidate->update(['is_active' => false]);
        }

        $page = $this->followRedirects(
            $this->actingAs($this->user)->post(route('discover.assist'))
        )->assertOk()->getContent();

        $this->assertStringContainsString('no places left to choose a suggestion from', $page);
        $this->assertCount(0, $this->capturedRequests);
    }

    // -------------------------------------------------------- the affordance

    #[Test]
    public function the_button_is_offered_only_when_a_key_exists(): void
    {
        $page = (string) $this->actingAs($this->user)->get(route('discover.index'))->getContent();

        $this->assertStringContainsString('Ask for a nudge', $page, 'A configured deployment should offer the button.');

        config(['services.gemini.key' => null]);

        $page = (string) $this->actingAs($this->user)->get(route('discover.index'))->getContent();

        $this->assertStringNotContainsString('Ask for a nudge', $page, 'A control that cannot work should not be rendered.');
        // The rest of the page is untouched by the missing key.
        $this->assertStringContainsString('Reset deck', $page);

        // And a POST past a missing button is refused honestly, not fatalled.
        $page = $this->followRedirects(
            $this->actingAs($this->user)->post(route('discover.assist'))
        )->assertOk()->getContent();

        $this->assertStringContainsString('not set up on this deployment', $page);
    }

    #[Test]
    public function the_gemini_key_never_reaches_the_discover_page(): void
    {
        config(['services.gemini.key' => 'sk-test-must-never-be-rendered']);

        $page = (string) $this->actingAs($this->user)->get(route('discover.index'))->getContent();

        $this->assertStringNotContainsString('sk-test-must-never-be-rendered', $page);
    }

    // -------------------------------------------------------------- helpers

    private array $capturedRequests = [];

    private function candidate(string $name, string $municipality, string $province, array $tags, float $fee): Destination
    {
        $destination = Destination::create([
            'name' => $name,
            'slug' => str($name)->slug(),
            'description' => 'A place in Luzon worth the walk.',
            'province' => $province,
            'municipality' => $municipality,
            'latitude' => 16.4,
            'longitude' => 120.6,
            'budget_level' => 'economy',
            'estimated_cost' => $fee,
            'recommended_minutes' => 90,
            'is_active' => true,
            'is_featured' => true,
        ]);

        foreach ($tags as $tag) {
            $destination->tags()->attach($tag);
        }

        return $destination;
    }

    private function fakeGemini(array $picks): void
    {
        $this->fakeHttp(Http::response([
            'candidates' => [
                ['content' => ['parts' => [['text' => json_encode($picks)]]]],
            ],
        ]));
    }

    private function fakeHttp($response): void
    {
        // Deliberately NOT resetting $this->capturedRequests here: a test
        // that swaps fakes mid-way (failure, then a good reply) needs the
        // TOTAL wire calls, which is what the budget claims are made about.
        Http::fake(function (Request $request) use ($response) {
            $this->capturedRequests[] = $request;

            return $response;
        });
    }
}
