<?php

namespace Tests\Feature;

use App\Models\Itinerary;
use App\Models\Place;
use App\Models\User;
use App\Services\Planning\DiscoveryAssist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE ITINERARY LOCATION ADVISER: "where should we eat and sleep on this trip?"
 *
 * The whole authority split, tested at the level it is actually enforced. The
 * model chooses selection and one sentence per pick; Laravel owns membership of
 * the catalogue and the trip's province, and every id the reply names is
 * checked against the candidate map the prompt was built from. So an invented
 * id, a place from another province, or a place that is not food or lodging is
 * dropped by the server rather than rendered.
 *
 * The transport's own claims -- the output budget, the one-request rule, the
 * two failure kinds, the log lines -- live in `GeminiClientTest`, because they
 * are true of the client whatever calls it.
 */
class ItineraryLocationSuggestTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Itinerary $trip;

    /**
     * The requests the Gemini stub has seen.
     *
     * @var Request[]
     */
    private array $captured = [];

    /**
     * What the ONE standing Gemini stub will answer, set per call site.
     *
     * The stub is registered once in setUp() and reads this property, rather
     * than being re-registered per call: Laravel runs EVERY matching stub
     * closure and uses the first non-null response, so a second Http::fake()
     * in one test would double-count every captured request -- and the wire
     * count is exactly what these tests are claiming.
     */
    private mixed $reply = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.key' => 'test-gemini-key']);

        $this->captured = [];
        $this->reply = null;

        Http::fake([
            'generativelanguage.googleapis.com*' => function (Request $request) {
                $this->captured[] = $request;

                return $this->reply ?? $this->decodedSuggestions([]);
            },
        ]);

        $this->owner = User::factory()->create();

        $this->trip = Itinerary::create([
            'user_id' => $this->owner->id,
            'title' => 'A Baguio weekend',
            'area' => 'Benguet',
            'budget_level' => 'economy',
            'trip_duration_days' => 2,
        ]);
    }

    // ------------------------------------------------------------- the pool

    /**
     * THE PROMPT CARRIES THE CANDIDATE ROWS AND NO PERSONAL DATA.
     *
     * A free-tier request is improving Google's products, so a traveller name,
     * email or account field must not leave. The rows are the only facts the
     * model sees: id, name, type and municipality. Asserted on the wire rather
     * than on the prompt string, because the wire is what the provider receives.
     */
    #[Test]
    public function the_prompt_carries_the_catalogue_and_no_personal_data(): void
    {
        $food = $this->place(['name' => 'Le Chef', 'type' => Place::TYPE_FOOD]);
        $lodge = $this->place(['name' => 'Microtel', 'type' => Place::TYPE_LODGING]);

        $this->answerWith([]);

        $this->assist()->recommendLocationsForItinerary($this->trip);

        $this->assertCount(1, $this->captured);

        $prompt = (string) json_decode((string) $this->captured[0]->body(), true)
            ['contents'][0]['parts'][0]['text'];

        // Both candidates are there, by name and by id.
        $this->assertStringContainsString('Le Chef', $prompt);
        $this->assertStringContainsString('Microtel', $prompt);
        $this->assertStringContainsString((string) $food->id, $prompt);
        $this->assertStringContainsString((string) $lodge->id, $prompt);

        // And the province is named, so the model cannot wander out of it.
        $this->assertStringContainsString('Benguet', $prompt);

        // Nothing personal: the owner's name and email both stay home. Their
        // ACCOUNT id is deliberately not asserted as a substring: on a fresh
        // database the owner is id 1 and so is the first catalogue row, so the
        // check would fail on a prompt that is telling the truth -- and would
        // pass on one leaking a different single-digit id. A numeric id cannot
        // be proven absent by substring; name and email carry the claim.
        $this->assertStringNotContainsString($this->owner->name, $prompt);
        $this->assertStringNotContainsString($this->owner->email, $prompt);
    }

    /**
     * A CANDIDATE IS FOOD OR LODGING IN THE TRIP'S OWN PROVINCE. NOTHING ELSE.
     *
     * An attraction in the right province is not a candidate: the panel is
     * "where should we eat and sleep", and a museum in the pool would let the
     * model suggest one. A restaurant in the wrong province is refused for the
     * same reason a traveller would have to leave the area to reach it.
     */
    #[Test]
    public function only_food_and_lodging_in_the_trips_own_province_are_candidates(): void
    {
        $this->place(['name' => 'In province food', 'type' => Place::TYPE_FOOD]);
        $this->place(['name' => 'In province lodge', 'type' => Place::TYPE_LODGING]);

        // Right province, wrong type.
        $this->place(['name' => 'A museum', 'type' => 'attraction']);

        // Right type, wrong province.
        $this->place([
            'name' => 'Far away food',
            'type' => Place::TYPE_FOOD,
            'province' => 'Ilocos Norte',
        ]);

        // Right type and province, but archived.
        $this->place([
            'name' => 'Closed for good',
            'type' => Place::TYPE_FOOD,
            'is_active' => false,
        ]);

        $this->answerWith([]);

        $this->assist()->recommendLocationsForItinerary($this->trip);

        $prompt = (string) json_decode((string) $this->captured[0]->body(), true)
            ['contents'][0]['parts'][0]['text'];

        $this->assertStringContainsString('In province food', $prompt);
        $this->assertStringContainsString('In province lodge', $prompt);

        $this->assertStringNotContainsString('A museum', $prompt);
        $this->assertStringNotContainsString('Far away food', $prompt);
        $this->assertStringNotContainsString('Closed for good', $prompt);
    }

    // ------------------------------------------------------- the membership

    /**
     * AN INVENTED ID IS DROPPED, NOT RENDERED.
     *
     * A prompt is not enforcement: the model is TOLD every place_id must come
     * from the list, and it sometimes invents one anyway. The check against the
     * candidate map is the actual boundary, and this is the falsification -- a
     * reply naming an id that was never offered must come back empty rather
     * than as a suggestion pointing at nothing.
     */
    #[Test]
    public function an_invented_id_is_dropped(): void
    {
        $real = $this->place(['name' => 'Le Chef']);

        $this->answerWith([
            ['place_id' => 999999, 'reason' => 'A place I made up.'],
            ['place_id' => $real->id, 'reason' => 'Close to the hotel.'],
        ]);

        $picks = $this->assist()->recommendLocationsForItinerary($this->trip);

        $this->assertCount(1, $picks);
        $this->assertSame($real->id, $picks[0]['place']->id);
    }

    /**
     * A REAL PLACE OUTSIDE THE POOL IS REFUSED FOR THE SAME REASON.
     *
     * Not an unknown id this time: a genuine, active place in another province.
     * It exists in the catalogue, so a membership check against the TABLE would
     * pass -- only a check against the CANDIDATE MAP catches it, which is why
     * the map is built from the same query the prompt was.
     */
    #[Test]
    public function a_real_place_outside_the_pool_is_refused(): void
    {
        $this->place(['name' => 'In province']);

        $elsewhere = $this->place([
            'name' => 'Paoay church',
            'type' => Place::TYPE_FOOD,
            'province' => 'Ilocos Norte',
        ]);

        $this->answerWith([
            ['place_id' => $elsewhere->id, 'reason' => 'A fine restaurant, four hours away.'],
        ]);

        $picks = $this->assist()->recommendLocationsForItinerary($this->trip);

        $this->assertSame([], $picks);
    }

    // ---------------------------------------------------------- the sentence

    /**
     * THE SENTENCE IS THE ONLY THING THE MODEL CONTRIBUTES.
     *
     * It is sanitised to one line and capped at 280 characters: a multi-line
     * reply would break the panel's layout, and an unbounded one would let a
     * model write an essay where a caption belongs. Every other fact on the
     * panel comes from the candidate map, so nothing the model says about
     * prices or hours can reach a traveller.
     */
    #[Test]
    public function the_sentence_is_one_line_and_capped(): void
    {
        $real = $this->place(['name' => 'Le Chef']);

        $this->answerWith([
            [
                'place_id' => $real->id,
                // Newlines, and well over the cap.
                'reason' => str_repeat('A very fine place to eat. ', 30)."\nSecond line.",
            ],
        ]);

        $picks = $this->assist()->recommendLocationsForItinerary($this->trip);

        // Over the cap, so it is dropped rather than truncated: a half
        // sentence under a place's name reads as a broken page.
        $this->assertSame([], $picks);
    }

    /**
     * A PICK WITH NO USABLE REASON IS NOT A RECOMMENDATION.
     *
     * The panel's whole claim is "here is why this place suits your trip", so
     * a pick with no sentence (or a blank one) has nothing to show. It is
     * dropped rather than rendered with an empty caption.
     */
    #[Test]
    public function a_pick_with_no_usable_reason_is_dropped(): void
    {
        $real = $this->place(['name' => 'Le Chef']);

        foreach ([null, '', '   ', 42] as $index => $reason) {
            $this->answerWith([['place_id' => $real->id, 'reason' => $reason]]);

            $this->assertSame(
                [],
                $this->assist()->recommendLocationsForItinerary($this->trip),
                'a pick whose reason is '.var_export($reason, true).' must be dropped'
            );

            // One wire call per attempt, whatever the reply was.
            $this->assertCount($index + 1, $this->captured);
        }
    }

    // ------------------------------------------------------- the quiet states

    /**
     * NO KEY, NO CALL, NO PANEL.
     *
     * The suggest is a nice-to-have panel, not a workflow the trip depends on,
     * so a deployment with no key renders nothing rather than failing. It must
     * also not reach the wire: an unauthenticated request would be refused and
     * would log a 403 nobody can act on.
     */
    #[Test]
    public function a_missing_key_returns_empty_without_a_wire_call(): void
    {
        $this->place(['name' => 'Le Chef']);

        config(['services.gemini.key' => '']);

        $this->answerWith([]);

        $this->assertSame([], $this->assist()->recommendLocationsForItinerary($this->trip));
        $this->assertCount(0, $this->captured);
    }

    /**
     * NO CANDIDATES, NO CALL.
     *
     * A trip in a province with no food or lodging rows is the honest empty
     * state, and asking the model to choose from an empty list would either
     * invent a place or return nothing at a cost. Both halves matter: the
     * empty result, and the request that never went out.
     */
    #[Test]
    public function an_empty_pool_returns_empty_without_a_wire_call(): void
    {
        // Places exist, but none of them are food or lodging in this province.
        $this->place(['name' => 'A museum', 'type' => 'attraction']);

        $this->answerWith([]);

        $this->assertSame([], $this->assist()->recommendLocationsForItinerary($this->trip));
        $this->assertCount(0, $this->captured);
    }

    /**
     * A FAILED CALL IS AN EMPTY PANEL, NOT AN ERROR PAGE.
     *
     * The trip is fine; the suggestion panel is not. Every failure kind the
     * client names -- throttled, upstream, truncated, unparseable, unreachable
     * -- ends the attempt here rather than reaching the traveller as an
     * exception, because none of them is about their trip.
     */
    #[Test]
    public function a_failed_call_is_an_empty_panel_not_an_exception(): void
    {
        $this->place(['name' => 'Le Chef']);

        foreach ([429, 500, 404] as $status) {
            $this->captured = [];
            $this->answerWithRaw(Http::response('nope', $status));

            $this->assertSame(
                [],
                $this->assist()->recommendLocationsForItinerary($this->trip),
                'a '.$status.' must be an empty panel, not a thrown error'
            );

            $this->assertCount(1, $this->captured);
        }
    }

    // ------------------------------------------------------------- the route

    /**
     * THE SUGGEST IS OWNER-ONLY, LIKE EVERY OTHER SURFACE ON A TRIP.
     *
     * A trip carries where its traveller will eat and sleep; a stranger gets a
     * 403 and no model call at all -- the guard runs before the service is
     * resolved, so a hand-posted request cannot spend the key either.
     */
    #[Test]
    public function a_stranger_cannot_ask_for_another_travellers_suggestions(): void
    {
        $this->place(['name' => 'Le Chef']);

        $this->answerWith([]);

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post(route('itineraries.llm-suggest', $this->trip))
            ->assertForbidden();

        $this->assertCount(0, $this->captured, 'a refused request must not spend the key');
    }

    /**
     * THE OWNER GETS THE PICKS BACK AS JSON.
     *
     * The route is the panel's only read surface, so it carries the validated
     * picks and nothing else: the place comes from the candidate map, and the
     * sentence from the model under its own label upstream. Asserted on the
     * response rather than on the service, because the panel reads the response.
     */
    #[Test]
    public function the_owner_gets_the_picks_back(): void
    {
        $real = $this->place(['name' => 'Le Chef', 'municipality' => 'Baguio City']);

        $this->answerWith([
            ['place_id' => $real->id, 'reason' => 'Close to the hotel.'],
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson(route('itineraries.llm-suggest', $this->trip))
            ->assertOk()
            ->json();

        $this->assertCount(1, $response['suggestions']);
        $this->assertSame($real->id, $response['suggestions'][0]['place']['id']);
        $this->assertSame('Le Chef', $response['suggestions'][0]['place']['name']);
        $this->assertSame('Close to the hotel.', $response['suggestions'][0]['reason']);
    }

    /**
     * AN INVENTED ID NEVER REACHES THE RESPONSE.
     *
     * The same membership rule as the service, asserted at the HTTP boundary --
     * which is where a traveller would actually see a bad id. The route returns
     * an empty list rather than an error: the trip is fine, the model was not
     * useful.
     */
    #[Test]
    public function an_invented_id_never_reaches_the_response(): void
    {
        $this->place(['name' => 'Le Chef']);

        $this->answerWith([
            ['place_id' => 424242, 'reason' => 'A place that does not exist.'],
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson(route('itineraries.llm-suggest', $this->trip))
            ->assertOk()
            ->json();

        $this->assertSame([], $response['suggestions']);
    }

    // -------------------------------------------------------------- helpers

    private function assist(): DiscoveryAssist
    {
        return app(DiscoveryAssist::class);
    }

    private function place(array $overrides = []): Place
    {
        static $sequence = 0;

        $sequence++;

        return Place::create(array_merge([
            'name' => 'Test Place '.$sequence,
            'slug' => 'suggest-place-'.$sequence,
            'type' => Place::TYPE_FOOD,
            'province' => 'Benguet',
            'municipality' => 'Baguio City',
            'latitude' => 16.4120,
            'longitude' => 120.5960,
            'budget_level' => 'economy',
            'source_url' => 'https://example.test/suggest-'.$sequence,
        ], $overrides));
    }

    /**
     * A Gemini `generateContent` reply whose TEXT is the given suggestions
     * payload, wrapped in the envelope the client decodes.
     */
    private function decodedSuggestions(array $suggestions)
    {
        return Http::response([
            'candidates' => [
                ['content' => ['parts' => [['text' => json_encode([
                    'suggestions' => $suggestions,
                ])]]]],
            ],
        ]);
    }

    /**
     * Point the standing stub at a well-formed reply carrying these picks.
     */
    private function answerWith(array $suggestions): void
    {
        $this->reply = $this->decodedSuggestions($suggestions);
    }

    /**
     * Point the standing stub at ANY raw response -- a status code, prose.
     */
    private function answerWithRaw($response): void
    {
        $this->reply = $response;
    }
}
