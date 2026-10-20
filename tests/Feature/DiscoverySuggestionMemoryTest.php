<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DiscoverySuggestion;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use App\Services\SwipeDeckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE NUDGE BECOMES STATE: THE DISCOVERY ASSIST'S PERSISTENT MEMORY.
 *
 * The assist used to be entirely stateless — the model's picks were flashed
 * to the session, rendered once and gone, so the model's output could never
 * become anything the system ACTED on. Now every validated reply is written
 * to `discovery_suggestions` and the next deal promotes that batch to the
 * front of the traveller's real deck.
 *
 * The invariants:
 *
 * - **ONLY VALIDATED PICKS ARE REMEMBERED.** The write happens after
 *   decode, after candidate membership, after the catalogue re-read. An
 *   invented id must never reach the table, and a failure state (throttled,
 *   unparseable, empty pool) is never remembered as an opinion.
 * - **A REORDER, NEVER A FILTER AND NEVER AN INJECTION.** Promotion moves
 *   rows already on the deck; a remembered pick that has since left the
 *   deck simply does not move. The deck's membership is still the deck's
 *   own deal (see DeckDiversityTest).
 * - **THE MODEL'S RANK IS THE ORDER.** The promoted front replays the
 *   reply's own ranking — the one decision config/planning.php says the
 *   model owns — not our preference score.
 * - **THE STRETCH CARD NEVER LEADS.** The novelty card shares no interests,
 *   so it is not in the deck when promotion runs: it stays last with its
 *   own badge, promoted or not.
 * - **NO MEMORY MEANS THE DECK IS UNTOUCHED.** Same order, no flags — the
 *   arithmetic deals exactly what it always dealt.
 * - **THE BATCH DIES WITH THE DECISIONS IT WAS BUILT ON.** Resetting the
 *   deck and saving preferences both forget the batch, alongside the swipes
 *   those actions already wipe.
 * - **THE QUOTE IS STILL THE MODEL'S WORDS.** Rendered on the card with the
 *   same "unchecked" label the panel uses; every other fact stays ours.
 *
 * The no-key / no-reply / throttled behaviour of the DECK ITSELF is
 * unchanged: nothing here gates dealing cards, and DiscoveryAssistTest
 * still owns the wire contract.
 */
class DiscoverySuggestionMemoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Destination $burnham;

    private Destination $mines;

    private Destination $pinto;

    private Destination $sculpture;

    private array $capturedRequests = [];

    /** @var callable(): \Illuminate\Http\Client\Response */
    private $geminiResponder;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.key' => 'test-gemini-key']);

        // ONE STANDING STUB, SWAPPED BY RESPONDER — never a second
        // Http::fake(): the factory honours the FIRST matching stub, so a
        // second fake() would be silently ignored and the old reply served
        // again (the same trap tests/TestCase.php::stubOsrm() exists for).
        $this->geminiResponder = fn () => Http::response('Server exploded', 500);

        Http::fake(function (Request $request) {
            $this->capturedRequests[] = $request;

            return ($this->geminiResponder)();
        });

        $this->user = User::factory()->create();

        $profile = TravelProfile::create([
            'user_id' => $this->user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 2,
            'preferred_region' => null,
        ]);

        $nature = Tag::create(['name' => 'Nature', 'slug' => 'nature']);
        $museum = Tag::create(['name' => 'Museums', 'slug' => 'museums']);
        $cuisine = Tag::create(['name' => 'Cuisine', 'slug' => 'cuisine']);

        // Scores with weights Nature 3 / Museums 1: Burnham 75, Mines 75,
        // Pinto 25 — so a remembered batch that leads with PINTO can only
        // have been put there by the model's rank, never by our arithmetic.
        $profile->tags()->attach($nature, ['weight' => 3]);
        $profile->tags()->attach($museum, ['weight' => 1]);

        $this->burnham = $this->destination('Burnham Park', [$nature]);
        $this->mines = $this->destination('Mines View Park', [$nature]);
        $this->pinto = $this->destination('Pinto Art Museum', [$museum]);
        // The stretch card: an unweighted tag means no interest match, so
        // this row is dealt LAST, as novelty.
        $this->sculpture = $this->destination('Sculpture Hill', [$cuisine]);
    }

    // ------------------------------------------------------- remembering

    #[Test]
    public function a_valid_nudge_is_remembered_as_typed_state_in_the_models_order(): void
    {
        $this->fakeGemini([
            'picks' => [
                ['id' => $this->pinto->id, 'why' => 'Your museum weight makes this one worth the ridge.'],
                ['id' => $this->burnham->id, 'why' => 'A walkable park suits a short Baguio day.'],
            ],
        ]);

        // Assert the response too: a swallowed 500 (anything thrown inside
        // persist) must not pass a test that only inspects the table — the
        // table would then be "correct" because the request never finished.
        $this->actingAs($this->user)->post(route('discover.assist'))
            ->assertRedirect(route('discover.index'))
            ->assertSessionHas('assist_result');

        $rows = DiscoverySuggestion::where('user_id', $this->user->id)
            ->orderBy('rank')
            ->get();

        $this->assertCount(2, $rows, 'A validated nudge must survive the request.');
        $this->assertSame([$this->pinto->id, $this->burnham->id], $rows->pluck('destination_id')->all());
        $this->assertSame([1, 2], $rows->pluck('rank')->all());
        $this->assertSame(
            [
                'Your museum weight makes this one worth the ridge.',
                'A walkable park suits a short Baguio day.',
            ],
            $rows->pluck('why')->all(),
            'The model sentence is stored exactly as it was sanitised, in rank order.'
        );
    }

    #[Test]
    public function a_new_nudge_replaces_the_batch_rather_than_stacking(): void
    {
        $this->fakeGemini([
            'picks' => [
                ['id' => $this->pinto->id, 'why' => 'First opinion.'],
                ['id' => $this->burnham->id, 'why' => 'Also first opinion.'],
            ],
        ]);

        $this->actingAs($this->user)->post(route('discover.assist'));

        // The cooldown is per-traveller and spent on purpose; a second ask
        // inside it is a refusal, not a batch change, so clear it the way
        // only a test may.
        \Illuminate\Support\Facades\Cache::forget('assist:cooldown:'.$this->user->id);

        $this->fakeGemini([
            'picks' => [
                ['id' => $this->mines->id, 'why' => 'Second opinion, alone.'],
            ],
        ]);

        $this->actingAs($this->user)->post(route('discover.assist'));

        $this->assertDatabaseCount('discovery_suggestions', 1);

        $rows = DiscoverySuggestion::where('user_id', $this->user->id)->get();

        $this->assertSame($this->mines->id, $rows->first()->destination_id);
    }

    #[Test]
    public function a_throttled_attempt_is_never_remembered(): void
    {
        $this->fakeHttp(Http::response('rate limited', 429));

        $this->actingAs($this->user)->post(route('discover.assist'))
            ->assertRedirect(route('discover.index'))
            ->assertSessionHas('toast_message', 'The suggestion model is out of free requests right now — try again in a minute.');

        $this->assertDatabaseCount('discovery_suggestions', 0);
    }

    #[Test]
    public function a_reply_of_only_invented_ids_is_never_remembered(): void
    {
        $this->fakeGemini([
            'picks' => [
                ['id' => 999999, 'why' => 'A place this app has never heard of.'],
            ],
        ]);

        $this->actingAs($this->user)->post(route('discover.assist'))
            ->assertRedirect(route('discover.index'))
            ->assertSessionHas('toast_message', 'It did not name any place from the catalogue. Try again.');

        $this->assertSame(0, DiscoverySuggestion::count(), 'A failure is not an opinion.');
    }

    #[Test]
    public function an_invented_id_never_reaches_the_table(): void
    {
        $this->fakeGemini([
            'picks' => [
                ['id' => 999999, 'why' => 'Invented.'],
                ['id' => $this->burnham->id, 'why' => 'Real.'],
            ],
        ]);

        // A 500 here (e.g. an invented id reaching the table and hitting the
        // foreign key) would otherwise be swallowed by the exception handler
        // while the table still holds only the valid pick.
        $this->actingAs($this->user)->post(route('discover.assist'))
            ->assertRedirect(route('discover.index'))
            ->assertSessionHas('assist_result');

        $rows = DiscoverySuggestion::where('user_id', $this->user->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame($this->burnham->id, $rows->first()->destination_id);
    }

    // -------------------------------------------------------- promotion

    #[Test]
    public function the_next_deal_leads_with_the_remembered_picks_in_the_models_order(): void
    {
        $this->fakeGemini([
            'picks' => [
                // The LOWEST-scoring match first: only the model's rank can
                // explain this order, because our arithmetic puts the 75s
                // ahead of the 25.
                ['id' => $this->pinto->id, 'why' => 'Ranked first by the model.'],
                ['id' => $this->burnham->id, 'why' => 'Ranked second by the model.'],
            ],
        ]);

        $this->actingAs($this->user)->post(route('discover.assist'));

        $cards = $this->cards();

        $this->assertSame(
            [$this->pinto->id, $this->burnham->id, $this->mines->id, $this->sculpture->id],
            $cards->pluck('id')->all(),
            'The batch leads in the reply\'s own order; the rest keeps its place behind it.'
        );

        $this->assertTrue($cards->first()->is_suggested);
        $this->assertSame('Ranked first by the model.', $cards->first()->suggest_why);
        $this->assertFalse($cards->get(2)->is_suggested);
    }

    #[Test]
    public function promotion_reorders_the_deck_and_never_changes_which_places_are_in_it(): void
    {
        $withoutMemory = $this->cards()->pluck('id')->sort()->values()->all();

        $this->remember($this->pinto->id, 1, 'Promoted.');

        $withMemory = $this->cards();

        $this->assertSame(
            $withoutMemory,
            $withMemory->pluck('id')->sort()->values()->all(),
            'Promotion is a reorder: the same places, in a different order.'
        );
        $this->assertNotSame(
            $withoutMemory,
            $withMemory->pluck('id')->all(),
            'And it really is a reorder — the order must have changed.'
        );
    }

    #[Test]
    public function a_remembered_pick_that_has_been_decided_never_comes_back(): void
    {
        $this->remember($this->burnham->id, 1, 'Was worth a look when this was written.');

        $this->actingAs($this->user)->postJson(route('discover.swipes.store'), [
            'destination_id' => $this->burnham->id,
            'action' => 'liked',
        ])->assertOk();

        $cards = $this->cards();

        $this->assertFalse(
            $cards->contains('id', $this->burnham->id),
            'A remembered pick never re-injects a card the traveller decided on.'
        );
        $this->assertSame(
            [$this->mines->id, $this->pinto->id, $this->sculpture->id],
            $cards->pluck('id')->all()
        );
    }

    #[Test]
    public function the_stretch_card_is_never_promoted_even_when_the_model_named_it(): void
    {
        // The novelty card IS in the assist's candidate list — the deck
        // deals it — so the model may legitimately name it. It still may
        // not lead: the stretch card's whole rule is that an unranked place
        // never comes first.
        $this->fakeGemini([
            'picks' => [
                ['id' => $this->sculpture->id, 'why' => 'Something unlike the rest.'],
                ['id' => $this->pinto->id, 'why' => 'And this match besides.'],
            ],
        ]);

        $this->actingAs($this->user)->post(route('discover.assist'));

        $this->assertSame(2, DiscoverySuggestion::where('user_id', $this->user->id)->count());

        $cards = $this->cards();

        $this->assertSame($this->pinto->id, $cards->first()->id);
        $this->assertTrue($cards->last()->is_novelty, 'The stretch card still comes last.');
        $this->assertFalse(
            $cards->last()->is_suggested,
            'The stretch card is never badged as suggested on the deck.'
        );
    }

    #[Test]
    public function a_deck_with_no_memory_carries_no_suggestion_flags_and_the_usual_order(): void
    {
        $cards = $this->cards();

        $this->assertSame(
            [$this->burnham->id, $this->mines->id, $this->pinto->id, $this->sculpture->id],
            $cards->pluck('id')->all(),
            'No batch means the arithmetic deals exactly what it always dealt.'
        );

        foreach ($cards as $card) {
            $this->assertFalse($card->is_suggested, 'A traveller who never asked carries no badge.');
        }
    }

    #[Test]
    public function the_suggestion_batch_cannot_exceed_the_decks_per_tag_cap(): void
    {
        // Promotion leads with the batch, so a batch larger than MAX_PER_TAG
        // could stack more of one tag at the front than the cap allows —
        // the cap would be enforceable everywhere except the one place a
        // model's ranking gets to speak.
        $cap = (new \ReflectionClass(SwipeDeckService::class))
            ->getReflectionConstant('MAX_PER_TAG')
            ->getValue();

        $this->assertLessThanOrEqual(
            $cap,
            (int) config('planning.limits.assist_choices'),
            'The ask for picks must never exceed the per-tag cap the deck enforces.'
        );
    }

    // ---------------------------------------------------------- the card

    #[Test]
    public function the_remembered_sentence_is_rendered_on_the_card_with_the_models_words_label(): void
    {
        $this->remember($this->pinto->id, 1, 'The ridge walk suits someone who rated museums.');

        $html = $this->deckHtml();

        $this->assertSame(1, substr_count($html, 'Suggested next'), 'One promoted card, one badge.');
        $this->assertStringContainsString('The ridge walk suits someone who rated museums.', $html);
        // The label travels with the quote wherever the quote appears, so a
        // card-borne sentence can never read as verified catalogue copy.
        $this->assertSame(
            1,
            substr_count($html, "the model's own words, unchecked"),
            'No panel is rendered here, so the label can only be the card\'s own.'
        );

        DiscoverySuggestion::where('user_id', $this->user->id)->delete();

        $this->assertStringNotContainsString('Suggested next', $this->deckHtml());
    }

    // ------------------------------------------------- the batch's lifetime

    #[Test]
    public function resetting_the_deck_forgets_the_batch(): void
    {
        $this->remember($this->pinto->id, 1, 'Chosen for a deck that no longer exists.');

        $this->actingAs($this->user)->post(route('discover.reset'));

        $this->assertSame(0, DiscoverySuggestion::count(), 'Start fresh means start fresh.');
    }

    #[Test]
    public function saving_preferences_forgets_the_batch(): void
    {
        $this->remember($this->pinto->id, 1, 'Chosen for interest weights that just changed.');

        $this->actingAs($this->user)->put(route('preferences.update'), [
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 3,
            'weights' => [
                Tag::where('slug', 'nature')->first()->id => 2,
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            0,
            DiscoverySuggestion::count(),
            'Saving preferences is the reset-the-deck behaviour, batch included.'
        );
    }

    // -------------------------------------------------------------- helpers

    /** @return \Illuminate\Support\Collection<int, Destination> */
    private function cards()
    {
        return $this
            ->actingAs($this->user)
            ->get(route('discover.index'))
            ->assertOk()
            ->viewData('cards');
    }

    private function deckHtml(): string
    {
        return (string) $this->actingAs($this->user)->get(route('discover.index'))->assertOk()->getContent();
    }

    private function remember(int $destinationId, int $rank, string $why): void
    {
        DiscoverySuggestion::create([
            'user_id' => $this->user->id,
            'destination_id' => $destinationId,
            'rank' => $rank,
            'why' => $why,
        ]);
    }

    private function destination(string $name, array $tags): Destination
    {
        $destination = Destination::create([
            'name' => $name,
            'slug' => str($name)->slug(),
            'description' => 'A place in Luzon worth the walk.',
            'province' => 'Benguet',
            'municipality' => 'Baguio',
            'latitude' => 16.4,
            'longitude' => 120.6,
            'budget_level' => 'economy',
            'estimated_cost' => 100,
            'recommended_minutes' => 90,
            'is_featured' => true,
            'is_active' => true,
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
        $this->geminiResponder = fn () => $response;
    }
}
