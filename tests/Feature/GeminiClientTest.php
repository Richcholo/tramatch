<?php

namespace Tests\Feature;

use App\Services\Planning\GeminiClient;
use App\Services\Planning\GeminiError;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE GEMINI TRANSPORT, TESTED AT ITS OWN LEVEL.
 *
 * These claims used to live in `DiscoveryAssistTest`, which drove them through
 * the deck assist's route. That route is gone and the itinerary suggest is the
 * only caller left, but every claim below is about the CLIENT -- the budget it
 * puts on the wire, the one-request rule, the two failure kinds it must keep
 * apart, and the log lines that made a live 404 diagnosable. Losing the route
 * did not make any of them less true, so they were rewritten here rather than
 * deleted with the file that happened to carry them.
 *
 * The HTTP road stub in the base TestCase matches `router.project-osrm.org*`
 * only, so a Gemini stub registered alongside it is the FIRST match for the
 * Gemini host -- see the note in AGENTS.md about the factory honoring the
 * first matching stub.
 */
class GeminiClientTest extends TestCase
{
    private const KEY = 'test-gemini-key';

    /**
     * The requests the fakes have seen, across any number of stub swaps.
     *
     * NOT reset per fake: a test that answers a failure and then a good reply
     * needs the TOTAL wire count, which is what the one-request claim is about.
     *
     * @var Request[]
     */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->captured = [];

        config(['services.gemini.key' => self::KEY]);
    }

    // ------------------------------------------------------------ the wire

    /**
     * THE OUTPUT BUDGET REACHES THE WIRE, AND IT IS NOT A SMALL NUMBER.
     *
     * A measured 3-pick reply used 577 `thoughtsTokenCount` against 112
     * `candidatesTokenCount`, and both are billed against this one number --
     * so a budget sized to the answer alone truncates the JSON mid-string and
     * the reply is thrown away as malformed when it was in the right shape and
     * simply ran out of room. The floor assertion is what makes the config
     * value mean anything: 2048 was the shipped value and it was wrong.
     */
    #[Test]
    public function the_output_budget_reaches_the_wire_from_config(): void
    {
        $this->answerWith($this->decodedReply([['place_id' => 1, 'reason' => 'Budget probe.']]));

        $this->client()->ask('how many tokens?');

        $this->assertCount(1, $this->captured);

        $sent = json_decode((string) $this->captured[0]->body(), true);

        $this->assertSame(
            (int) config('planning.limits.assist_output_tokens'),
            $sent['generationConfig']['maxOutputTokens'] ?? null,
            'the config budget must be the number the provider is asked for'
        );

        $this->assertGreaterThan(
            2048,
            (int) config('planning.limits.assist_output_tokens'),
            'the floor is the MEASURED thoughts (577) plus a full answer, not a preference'
        );
    }

    /**
     * STRUCTURED OUTPUT IS THE CONTRACT, AND IT IS A WIRE FACT.
     *
     * `responseMimeType: application/json` is what makes the reply text BE the
     * JSON the prompt asked for rather than prose around it. It is asserted on
     * the request rather than trusted from the code, because a client that
     * quietly dropped it would still parse in every test whose stub hand-sets
     * the JSON -- and would fail against the real model.
     */
    #[Test]
    public function structured_output_is_asked_for_on_the_wire(): void
    {
        $this->answerWith($this->decodedReply([]));

        $this->client()->ask('json please');

        $sent = json_decode((string) $this->captured[0]->body(), true);

        $this->assertSame(
            'application/json',
            $sent['generationConfig']['responseMimeType'] ?? null
        );
    }

    /**
     * THE USER-AGENT IS READ THROUGH CONFIG, AND IT NAMES A MAILBOX.
     *
     * The failure this pins: the header used to be built with
     * `env('CRAWLER_CONTACT', 'admin@example.com')` INSIDE the client. `env()`
     * outside a config file returns the default under `config:cache`, which
     * the deployment runs -- so on the one environment where an identifiable
     * client matters, it shipped the placeholder address. Reading it through
     * `config()` is the fix; this is what stops it going back.
     *
     * The routing client lost exactly one request in every three to a missing
     * User-Agent (measured 2026-10-15), so the header is not decoration.
     */
    #[Test]
    public function the_user_agent_reaches_the_wire_and_names_a_mailbox(): void
    {
        $this->answerWith($this->decodedReply([]));

        $this->client()->ask('identify yourself');

        $sent = $this->captured[0];

        $this->assertSame(
            (string) config('services.gemini.user_agent'),
            (string) $sent->header('User-Agent')[0],
            'the UA must come from config, never from env() in the client'
        );

        $this->assertStringContainsString(
            'mailto:',
            (string) $sent->header('User-Agent')[0],
            'an anonymous client is the behaviour that gets a service refusing you'
        );

        $this->assertStringNotContainsString(
            'admin@example.com',
            (string) $sent->header('User-Agent')[0],
            'the placeholder default must never be the shipped address'
        );
    }

    // ------------------------------------------------------- one request

    /**
     * ONE REQUEST PER CALL, EVEN WHEN THE UPSTREAM FAILS.
     *
     * A free tier is a budget, not a suggestion: a retry loop against a 429 or
     * a 5xx is a bot hammering its own quota. The count is asserted on the
     * captured requests rather than on the code, so a retry added five lines
     * under this test fails it.
     */
    #[Test]
    public function one_request_is_made_even_when_the_upstream_fails(): void
    {
        $this->answerWith(Http::response('Server exploded', 500));

        try {
            $this->client()->ask('try again?');
            $this->fail('a 5xx must throw rather than return');
        } catch (GeminiError $e) {
            $this->assertSame('upstream', $e->kind());
        }

        $this->assertCount(1, $this->captured, 'a failing call must not be retried');
    }

    /**
     * NO KEY, NO WIRE. A missing key is a deployment issue, and a traveller
     * clicking again cannot fix it -- so it must not become a request that
     * carries an empty header and gets refused by the provider.
     */
    #[Test]
    public function a_missing_key_never_reaches_the_wire(): void
    {
        config(['services.gemini.key' => '']);

        try {
            $this->client()->ask('anyone home?');
            $this->fail('an unconfigured client must throw');
        } catch (GeminiError $e) {
            $this->assertSame('unconfigured', $e->kind());
        }

        $this->assertCount(0, $this->captured);
    }

    // --------------------------------------------------- the failure kinds

    /**
     * A REPLY THAT RAN OUT OF ROOM IS NOT A REPLY OF THE WRONG SHAPE.
     *
     * THE FAILURE THIS EXISTS FOR, reported live as "it's success on the API
     * side, but doesn't really output anything". These models THINK before they
     * answer and the thoughts are billed against the SAME `maxOutputTokens`
     * budget, so a budget too small leaves the JSON cut off mid-string, and
     * `json_decode()` returns null for exactly the same reason a malformed
     * reply does. Both were reported as one kind -- a false statement about a
     * reply that was in the right shape. Measured: `maxOutputTokens: 256` on
     * `gemini-3-flash-preview` yields `finishReason: MAX_TOKENS`,
     * `thoughtsTokenCount: 243`, and the raw text
     * `'Here is the JSON requested:\n```'`.
     *
     * `finishReason` is the ONLY field that tells the two apart, and nothing in
     * the app read it before this. Falsified by deleting the check: the client
     * then calls a truncation a shape error again.
     */
    #[Test]
    public function a_reply_cut_off_by_the_output_limit_is_its_own_kind(): void
    {
        // The measured truncation, byte for byte: the model started writing a
        // fence around its answer and the budget ran out.
        $this->answerWith(Http::response([
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

        try {
            $this->client()->ask('suggest something');
            $this->fail('a truncated reply must throw');
        } catch (GeminiError $e) {
            $this->assertSame('truncated', $e->kind());
            $this->assertNotSame(
                'unparseable',
                $e->kind(),
                'a reply that ran long is in the right shape and must not be reported as malformed'
            );
        }
    }

    /**
     * THE TOKEN COUNTS ARE THE PROOF, SO THE LOG CARRIES THEM.
     *
     * A truncation has exactly one cause -- the thoughts ate the budget -- and
     * `usageMetadata` is the only place that shows it. Without it the log says
     * "truncated" and leaves the next reader to guess whether the prompt grew
     * or the budget shrank. `finishReason` alone names the fact; these numbers
     * explain it.
     */
    #[Test]
    public function a_truncated_reply_logs_the_token_counts_that_explain_it(): void
    {
        $this->answerWith(Http::response([
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

        try {
            $this->client()->ask('suggest something');
            $this->fail('a truncated reply must throw');
        } catch (GeminiError $e) {
            // The log line is written before the throw, so catching the error
            // is what lets the assertions above on the mock stand.
            $this->assertSame('truncated', $e->kind());
        }
    }

    /**
     * A REPLY THAT IS NOT THE JSON SHAPE IS ITS OWN KIND.
     *
     * Prose where the prompt asked for JSON: the model answered, the wire was
     * fine, and the shape is simply wrong. Distinct from a truncation because
     * nothing ran out -- `finishReason` here is not MAX_TOKENS.
     */
    #[Test]
    public function a_reply_that_is_not_the_json_shape_is_unparseable(): void
    {
        $this->answerWith(Http::response([
            'candidates' => [
                [
                    'content' => ['parts' => [['text' => 'I would suggest Burnham Park, honestly.']]],
                    'finishReason' => 'STOP',
                ],
            ],
        ]));

        try {
            $this->client()->ask('suggest something');
            $this->fail('a prose reply must throw');
        } catch (GeminiError $e) {
            $this->assertSame('unparseable', $e->kind());
        }
    }

    /**
     * A DECODED REPLY COMES BACK AS AN ARRAY.
     *
     * The transport owns the decode, so the caller never sees the JSON string.
     * `DiscoveryAssist` reads `$decoded['suggestions']` directly -- a client
     * that returned a string would move the decode into every caller.
     */
    #[Test]
    public function a_decoded_reply_comes_back_as_an_array(): void
    {
        // The payload IS the reply text, so it carries the same shape the
        // caller reads: a `suggestions` key, not a bare list.
        $this->answerWith($this->decodedReply([
            'suggestions' => [
                ['place_id' => 7, 'reason' => 'Close to the hotel.'],
            ],
        ]));

        $decoded = $this->client()->ask('suggest something');

        $this->assertIsArray($decoded);
        $this->assertSame(7, $decoded['suggestions'][0]['place_id']);
    }

    // ------------------------------------------------------ the diagnosis

    /**
     * THE LOG LINE THAT TURNS A RETIRED MODEL INTO A LOG READ.
     *
     * THE FAILURE THIS EXISTS FOR. The first live assist failure was a 404:
     * "This model models/gemini-2.5-flash is no longer available to new
     * users." `upstream` is one kind covering a retired model, a refused key
     * and an outage, the traveller's message names none of them, and the status
     * was thrown away on the floor -- so telling those three apart meant
     * hand-running a cURL probe over SSH against a host reachable only through
     * the terminal. The diagnostic is the whole point: this asserts the
     * provider's own words reach the log, because the status alone leaves the
     * guess intact.
     *
     * The key must NOT be in the context. A log file is read by more people
     * than an `.env` is, and a credential leak in a diagnostics line is the
     * failure mode a "just log the request" fix walks straight into.
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
                    self::KEY,
                    json_encode($context),
                    'a diagnostics line must never carry the credential it used'
                );

                return true;
            });

        $this->answerWith(Http::response([
            'error' => [
                'code' => 404,
                'message' => 'This model models/gemini-2.5-flash is no longer available to new users.',
                'status' => 'NOT_FOUND',
            ],
        ], 404));

        try {
            $this->client()->ask('suggest something');
            $this->fail('a 404 must throw');
        } catch (GeminiError $e) {
            $this->assertSame('upstream', $e->kind());
        }
    }

    /**
     * A THROTTLED CALL IS ITS OWN KIND, AND ITS OWN LOG LINE.
     *
     * A 429 is the free tier being a free tier, not the provider having a bad
     * day -- the traveller has to wait, and the message must say so rather than
     * implying an outage. Kept apart from `upstream` so the caller can tell
     * "you asked too often" from "they are down".
     */
    #[Test]
    public function a_throttled_reply_is_its_own_kind(): void
    {
        $this->answerWith(Http::response('rate limited', 429));

        try {
            $this->client()->ask('suggest something');
            $this->fail('a 429 must throw');
        } catch (GeminiError $e) {
            $this->assertSame('throttled', $e->kind());
        }
    }

    /**
     * THE TRAVELLER IS TOLD NOTHING INTERNAL.
     *
     * The log line above is for whoever runs the deployment; the exception
     * message is what a traveller can be shown. A fix that made the 404
     * visible in the message would trade a debuggable silence for a leaked
     * implementation detail -- the model id is nobody's business on a page.
     */
    #[Test]
    public function a_retired_model_does_not_leak_the_model_id_to_the_caller(): void
    {
        $this->answerWith(Http::response([
            'error' => [
                'code' => 404,
                'message' => 'This model models/gemini-2.5-flash is no longer available to new users.',
            ],
        ], 404));

        try {
            $this->client()->ask('suggest something');
            $this->fail('a 404 must throw');
        } catch (GeminiError $e) {
            // The generic message may name the SERVICE -- "the gemini endpoint
            // answered with a 4xx/5xx failure" is honest and useful. What must
            // never appear is the model id the provider named, or the
            // provider's own sentence about it.
            $this->assertStringNotContainsString(
                '2.5',
                $e->getMessage(),
                'the retired model id is a deployment detail, not something a traveller reads'
            );
            $this->assertStringNotContainsString('no longer available', $e->getMessage());
            $this->assertStringNotContainsString('models/', $e->getMessage());
        }
    }

    /**
     * THE DEFAULT IN config/services.php MUST BE A MODEL THAT ANSWERS TODAY.
     *
     * It read `gemini-2.5-flash` and shipped, so every deployment that never
     * set GEMINI_MODEL asked for a retired model and got a 404 dressed as an
     * outage. A default is not a pin -- a retirement is still an `.env` change
     * -- but "you left it blank" must not be indistinguishable from "the
     * service is down", which is exactly what a retired default makes true.
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
            'the shipped default must be readable out of config/services.php'
        );

        preg_match(
            "/'model'\s*=>\s*env\(\s*'GEMINI_MODEL'\s*,\s*'(?<model>[^']+)'\s*\)/",
            $services,
            $matches
        );

        $this->assertStringNotContainsString(
            '2.5',
            $matches['model'],
            'gemini-2.5-* is retired for new credentials; measured callable 2026-10-21: '
            .'gemini-3.8-flash, gemini-3-flash-preview, gemini-flash-latest'
        );
    }

    // -------------------------------------------------------------- helpers

    private function client(): GeminiClient
    {
        return app(GeminiClient::class);
    }

    /**
     * A Gemini `generateContent` reply whose TEXT is the given JSON.
     *
     * No return type: `Http::response()` hands back a fulfilled promise, not a
     * Response, and pinning one made every caller TypeError before it reached
     * the fake.
     */
    private function decodedReply(array $payload)
    {
        return Http::response([
            'candidates' => [
                ['content' => ['parts' => [['text' => json_encode($payload)]]]],
            ],
        ]);
    }

    /**
     * Answer the Gemini host, capturing every request on the way.
     *
     * A wildcard host match rather than a full URL: the client posts to
     * `<base_url>/<model>:generateContent`, and pinning that string here would
     * make this helper fail for a model rename rather than for a behaviour
     * change.
     */
    private function answerWith($response): void
    {
        Http::fake([
            'generativelanguage.googleapis.com*' => function (Request $request) use ($response) {
                $this->captured[] = $request;

                return $response;
            },
        ]);
    }
}
