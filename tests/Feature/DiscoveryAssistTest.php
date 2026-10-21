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
use Illuminate\Support\Facades\Log;
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

    /**
     * THE OUTPUT BUDGET IS SENT FROM CONFIG, AND IT IS BIG ENOUGH TO THINK IN.
     *
     * This is not a style check on a constant. These models emit reasoning
     * tokens before the answer and BOTH are billed against `maxOutputTokens`,
     * so a budget sized to the answer alone truncates the JSON — the live
     * "success on the API side, no output" report. Measured on a 3-pick reply:
     * 577 thoughts against 112 answer tokens.
     *
     * It asserts the config key is what reaches the wire, so shrinking
     * `planning.limits.assist_output_tokens` is a deliberate act with a failing
     * test, not a silent re-break. Falsified by hard-coding 2048 back into the
     * client: this reads 2048, the assertion wants the config value.
     */
    #[Test]
    public function the_output_budget_reaches_the_wire_from_config(): void
    {
        $this->fakeGemini([
            'picks' => [
                ['id' => $this->candidates[0]->id, 'why' => 'Budget probe.'],
            ],
        ]);

        $this->actingAs($this->user)->post(route('discover.assist'));

        $this->assertCount(1, $this->capturedRequests);

        $sent = json_decode((string) $this->capturedRequests[0]->body(), true);

        $this->assertSame(
            (int) config('planning.limits.assist_output_tokens'),
            $sent['generationConfig']['maxOutputTokens'] ?? null,
        );

        // The floor that makes the number mean anything: the measured thoughts
        // alone (577) plus a full answer have to fit, or a truncation is
        // inevitable rather than unlikely.
        $this->assertGreaterThan(2048, (int) config('planning.limits.assist_output_tokens'));
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

    /**
     * A REPLY THAT RAN OUT OF ROOM IS NOT A REPLY OF THE WRONG SHAPE.
     *
     * THE FAILURE THIS EXISTS FOR, reported live as "it's success on the API
     * side, but doesn't really output anything". These models THINK before they
     * answer and the thoughts are billed against the SAME `maxOutputTokens`
     * budget, so a budget too small leaves the JSON cut off mid-string, and
     * `json_decode()` returns null for exactly the same reason a malformed
     * reply does. Both were reported as "did not come back in the shape we
     * asked for" — a false statement about a reply that was in the right
     * shape. Measured: `maxOutputTokens: 256` yields
     * `finishReason: MAX_TOKENS`, `thoughtsTokenCount: 243`, and the raw text
     * `'Here is the JSON requested:\n\`\`\`'`.
     *
     * `finishReason` is the ONLY field that tells the two apart, and nothing in
     * the app read it before this. Falsified by deleting the check: the page
     * then claims the shape was wrong again.
     */
    #[Test]
    public function a_reply_cut_off_by_the_output_limit_is_its_own_state(): void
    {
        // The measured truncation, byte for byte: the model started writing a
        // fence around its answer and the budget ran out.
        $this->fakeHttp(Http::response([
            'candidates' => [
                [
                    'content' => ['parts' => [['text' => "Here is the JSON requested:\n```"]]],
                    'finishReason' => 'MAX_TOKENS',
                ],
            ],
            'usageMetadata' => [
                'candidatesTokenCount' => 9,
                'thoughtsTokenCount' => 243,
            ],
        ]));

        $page = $this->followRedirects(
            $this->actingAs($this->user)->post(route('discover.assist'))
        )->assertOk()->getContent();

        // The honest sentence, and NOT the shape sentence it used to borrow.
        $this->assertStringContainsString('ran long and was cut off', $page);
        $this->assertStringNotContainsString('did not come back in the shape we asked for', $page);
        $this->assertCount(1, $this->capturedRequests);
    }

    /**
     * THE TOKEN COUNTS ARE THE PROOF, SO THE LOG CARRIES THEM.
     *
     * A truncation has exactly one cause — the thoughts ate the budget — and
     * `usageMetadata` is the only place that shows it. Without it the log says
     * "truncated" and leaves the next reader to guess whether the prompt grew
     * or the budget shrank. `finishReason` alone names the fact; these numbers
     * explain it.
     */
    #[Test]
    public function a_truncated_reply_logs_the_token_counts_that_explain_it(): void
    {
        $this->fakeHttp(Http::response([
            'candidates' => [
                [
                    'content' => ['parts' => [['text' => "Here is the JSON requested:\n```"]]],
                    'finishReason' => 'MAX_TOKENS',
                ],
            ],
            'usageMetadata' => [
                'candidatesTokenCount' => 9,
                'thoughtsTokenCount' => 243,
            ],
        ]));

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $channel, array $context) {
                $this->assertSame('planning.gemini_truncated', $channel);
                $this->assertSame('MAX_TOKENS', $context['finish_reason']);
                $this->assertSame(243, $context['usage']['thoughtsTokenCount']);
                $this->assertSame(9, $context['usage']['candidatesTokenCount']);

                return true;
            });

        $this->actingAs($this->user)->post(route('discover.assist'));
    }

    // ------------------------------------------------------ the diagnosis

    /**
     * THE LOG LINE THAT TURNS A RETIRED MODEL INTO A LOG READ.
     *
     * THE FAILURE THIS EXISTS FOR. The first live assist failure was a 404:
     * "This model models/gemini-2.5-flash is no longer available to new users."
     * `upstream` is one kind covering a retired model, a refused key and an
     * outage, the traveller's copy names none of them, and the status was
     * thrown away on the floor — so telling those three apart meant hand-running
     * a cURL probe over SSH against a host reachable only through the terminal.
     * The diagnostic is the whole point: this asserts the provider's own words
     * reach the log, because the status alone leaves the guess intact.
     *
     * The key must NOT be in the context. A log file is read by more people than
     * an `.env` is, and a credential leak in a diagnostics line is the failure
     * mode a "just log the request" fix walks straight into.
     */
    #[Test]
    public function an_upstream_refusal_logs_the_status_and_the_providers_words(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $channel, array $context) {
                $this->assertSame('planning.gemini_upstream', $channel);
                $this->assertSame(404, $context['status']);

                // The exact sentence Google answered the live probe with.
                $this->assertStringContainsString(
                    'no longer available to new users',
                    (string) $context['provider_message']
                );

                $this->assertStringNotContainsString(
                    'test-gemini-key',
                    json_encode($context)
                );

                return true;
            });

        $this->fakeHttp(Http::response([
            'error' => [
                'code' => 404,
                'message' => 'This model models/gemini-2.5-flash is no longer available to new users.',
                'status' => 'NOT_FOUND',
            ],
        ], 404));

        $this->followRedirects(
            $this->actingAs($this->user)->post(route('discover.assist'))
        )->assertOk();
    }

    /**
     * AND THE TRAVELLER IS STILL TOLD NOTHING INTERNAL. The log line is for
     * whoever runs the deployment; the page is for the person who clicked. A
     * fix that made the 404 visible in the message would trade a debuggable
     * silence for a leaked implementation detail.
     */
    #[Test]
    public function a_retired_model_does_not_leak_the_model_id_to_the_traveller(): void
    {
        $this->fakeHttp(Http::response([
            'error' => [
                'code' => 404,
                'message' => 'This model models/gemini-2.5-flash is no longer available to new users.',
            ],
        ], 404));

        $page = $this->followRedirects(
            $this->actingAs($this->user)->post(route('discover.assist'))
        )->assertOk()->getContent();

        $this->assertStringContainsString('not answering', $page);
        $this->assertStringNotContainsString('gemini', $page);
        $this->assertStringNotContainsString('no longer available', $page);
    }

    /**
     * THE DEFAULT IN config/services.php MUST BE A MODEL THAT ANSWERS TODAY.
     *
     * It read `gemini-2.5-flash` and shipped, so every deployment that never
     * set GEMINI_MODEL asked for a retired model and got a 404 dressed as an
     * outage. A default is not a pin — a retirement is still an `.env` change —
     * but "you left it blank" must not be indistinguishable from "the service
     * is down", which is exactly what a retired default makes true.
     *
     * Asserted on the SHIPPED default with GEMINI_MODEL unset, so this fails
     * when the fallback rots rather than when the env happens to be set.
     */
    #[Test]
    public function the_configured_default_model_is_not_a_retired_one(): void
    {
        $services = file_get_contents(base_path('config/services.php'));

        $this->assertMatchesRegularExpression(
            "/'model'\s*=>\s*env\(\s*'GEMINI_MODEL'\s*,\s*'(?<model>[^']+)'\s*\)/",
            $services,
            'config/services.php no longer has a readable GEMINI_MODEL default.'
        );

        preg_match(
            "/'model'\s*=>\s*env\(\s*'GEMINI_MODEL'\s*,\s*'(?<model>[^']+)'\s*\)/",
            $services,
            $matches
        );

        // The two the live probe measured as retired for a current key. A third
        // retirement belongs here too, which is the point: this list is the
        // record of what Google has taken away.
        $this->assertNotContains(
            $matches['model'],
            ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.5-flash-latest'],
            'The default model is one Google has retired for new credentials.'
        );
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

    // ------------------------------------------------------- the feedback UI

    /**
     * A TWENTY-SECOND REQUEST WITH NO FEEDBACK IS INDISTINGUISHABLE FROM A
     * DEAD BUTTON.
     *
     * Reported verbatim: "there should be a popup window or something to tell
     * if it's loading or not ... I can't see what's supposed to happen". Both
     * of the assist's outcomes already rendered somewhere -- the panel below
     * the fold, the toast in a corner that takes no layout space -- and neither
     * is where a traveller who just pressed a button is looking.
     *
     * The dialog needs a loading mark, a success mark and a failure mark, and
     * the submit hook that opens it. Asserted on the ELEMENTS, not on the
     * script's prose: a component that mentions a spinner in a comment and
     * renders none is the failure this file keeps meeting.
     */
    #[Test]
    public function the_assist_carries_a_loading_and_an_outcome_dialog(): void
    {
        $html = (string) $this->actingAs($this->user)->get(route('discover.index'))->getContent();
        $dom = $this->dom($html);

        $dialog = $dom->query('//*[@data-assist-feedback]')->item(0);

        $this->assertNotNull(
            $dialog,
            'the assist renders no feedback dialog, so a click on it reports nothing while it waits'
        );

        $this->assertSame('dialog', $dialog->nodeName, 'the feedback overlay must be a <dialog>, so Escape closes it.');

        foreach (['loading', 'ok', 'error'] as $mark) {
            $this->assertSame(
                1,
                $dom->query('//*[@data-assist-mark="'.$mark.'"]')->length,
                'the feedback dialog has no "'.$mark.'" mark to show'
            );
        }

        // The form the script hooks. Without this exact attribute the dialog
        // never opens on submit and the feature is markup that does nothing.
        $this->assertSame(
            1,
            $dom->query('//form[@data-assist-form]')->length,
            'the assist form is not marked for the feedback script, so the dialog never opens'
        );

        // And the script itself, in the same response.
        $this->assertStringContainsString('data-assist-feedback-script', $html);
    }

    /**
     * A SUCCESS OPENS THE DIALOG WITH THE CHECK MARK.
     *
     * The outcome is settled SERVER-SIDE into `data-assist-outcome` and the
     * script only reveals the matching mark. That split is the point: the
     * failure sentence belongs to the controller's `assistMessage()`, and a
     * script composing its own would be a second copy of it.
     */
    #[Test]
    public function a_successful_nudge_lands_with_the_success_outcome(): void
    {
        $this->fakeGemini([
            'picks' => [
                ['id' => $this->candidates[0]->id, 'why' => 'A garden in the middle of the city.'],
            ],
        ]);

        $html = $this->followRedirects(
            $this->actingAs($this->user)->post(route('discover.assist'))
        )->assertOk()->getContent();

        $dialog = $this->dom($html)->query('//*[@data-assist-feedback]')->item(0);

        $this->assertNotNull($dialog);
        $this->assertSame(
            'ok',
            $dialog->getAttribute('data-assist-outcome'),
            'a successful assist must arrive knowing it succeeded, or the dialog shows a spinner forever'
        );
    }

    /**
     * AND A FAILURE OPENS IT WITH THE CROSS.
     *
     * This is the state that was invisible before: a 503 from an overloaded
     * model was a five-second corner toast, and a traveller watching the button
     * saw only that the page came back.
     */
    #[Test]
    public function a_failed_nudge_lands_with_the_failure_outcome(): void
    {
        $this->fakeHttp(Http::response([
            'error' => ['code' => 503, 'message' => 'This model is currently experiencing high demand.'],
        ], 503));

        $html = $this->followRedirects(
            $this->actingAs($this->user)->post(route('discover.assist'))
        )->assertOk()->getContent();

        $dialog = $this->dom($html)->query('//*[@data-assist-feedback]')->item(0);

        $this->assertNotNull($dialog);
        $this->assertSame(
            'error',
            $dialog->getAttribute('data-assist-outcome'),
            'a failed assist must arrive knowing it failed'
        );

        // The sentence the traveller reads inside the dialog is the
        // controller's own, not a second copy written for the popup.
        $this->assertStringContainsString('not answering right now', $html);
    }

    /**
     * AN ORDINARY PAGE LOAD HAS NO OUTCOME, AND THAT IS LOAD-BEARING.
     *
     * The dialog is rendered on every visit -- it has to be, because a
     * redirect lands on this page and the markup cannot be added after the
     * fact without JavaScript building it. So the resting state must be
     * "no outcome", or opening the deck would flash a tick or a cross at
     * someone who had not asked for anything.
     */
    #[Test]
    public function a_page_load_with_no_assist_behind_it_declares_no_outcome(): void
    {
        $dialog = $this->dom(
            (string) $this->actingAs($this->user)->get(route('discover.index'))->getContent()
        )->query('//*[@data-assist-feedback]')->item(0);

        $this->assertNotNull($dialog);
        $this->assertSame(
            '',
            (string) $dialog->getAttribute('data-assist-outcome'),
            'the deck declares an assist outcome on an untouched page load, so the dialog would open on arrival'
        );
    }

    /**
     * ONE FAILURE, ONE SENTENCE, TWO PLACES THAT MUST AGREE.
     *
     * The corner toast is the fallback for scripting-off and for browsers
     * without `<dialog>`; the centre dialog is the default. Both read
     * `assistMessage()`, and this holds them to the same string -- the shape
     * of drift being a failure whose popup and toast describe it differently
     * and nobody noticing which one is right.
     *
     * `assertSame` inside the session assertion rather than two separate
     * `assertStringContainsString`s on the page: the page carries both, so the
     * loose version passes while they disagree.
     */
    #[Test]
    public function the_failure_sentence_is_the_same_in_the_dialog_and_the_toast(): void
    {
        $this->fakeHttp(Http::response('Server exploded', 500));

        $this->actingAs($this->user)
            ->post(route('discover.assist'))
            ->assertRedirect(route('discover.index'));

        $fromDialog = session('assist_message');
        $fromToast = session('toast_message');

        $this->assertIsString(
            $fromDialog,
            'the failure was flashed without `assist_message`, so the dialog cannot say what went wrong'
        );
        $this->assertIsString(
            $fromToast,
            'the failure was flashed without `toast_message`, so the scripting-off fallback is gone'
        );

        // `assertSame` rather than two `assertStringContainsString`s on the
        // page: the page carries both sentences, so the loose version passes
        // while they disagree.
        $this->assertSame(
            $fromToast,
            $fromDialog,
            'the toast and the dialog describe one failure two ways'
        );

        // And the sentence is the controller's own, not a placeholder that
        // happens to match.
        $this->assertStringContainsString('not answering right now', $fromDialog);
    }

    // -------------------------------------------------------------- helpers

    private array $capturedRequests = [];

    private function dom(string $html): \DOMXPath
    {
        $document = new \DOMDocument();

        // libxml's HTML parser is noisy about the modern markup in this app
        // (void elements, SVG) and every complaint is irrelevant here; the
        // structure is what is being read.
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();

        return new \DOMXPath($document);
    }

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
