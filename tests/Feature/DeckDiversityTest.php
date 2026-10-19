<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use App\Services\SwipeDeckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FIVE MUSEUMS, AND ONE PLACE THAT IS NOT LIKE ANYTHING YOU PICKED.
 *
 * Ranking the deck by match score alone answers "what matches you?" and gets
 * it right: five museums all score 100 when the traveller rates museums
 * highest. It is a useless answer to the question the traveller is actually
 * asking, which is "what is there?".
 *
 * The invariants:
 *
 * - **THE CAP REORDERS, IT NEVER FILTERS.** A destination the cap defers is
 *   pushed down the deck, never dropped — a filter would silently shorten
 *   what the traveller is offered and nobody could say why.
 * - **THE STRETCH CARD IS LAST.** First position would put an unranked place
 *   in front of someone who just opened the deck and let it read as a match.
 * - **AND ONLY WHEN THE POOL HAS MATCHES.** A traveller whose interests
 *   match nothing gets the honest empty state with its two ways out — the
 *   same lie `DeckEmptyStatesTest` exists to refuse, wearing a different hat.
 * - **IT SAYS WHAT IT IS.** The card is labelled "Outside your interests" in
 *   both the photograph and the plate; a "0% match" badge is honest
 *   arithmetic and useless copy.
 * - **THE DEAL IS DETERMINISTIC.** Same catalogue, same swipes, same order —
 *   every tie is broken by `sort_order, name`.
 */
class DeckDiversityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = false;

    private ?int $userId = null;

    // ------------------------------------------------------- five museums, no

    /**
     * THE CAP REORDERS THE DECK; IT NEVER SHORTENS IT.
     *
     * Five museums all score 100, so ranking alone opens the deck with five
     * of the same thing — the correct answer to "what matches you?" and a
     * useless one to "what is there?". The cap admits three, and the beach
     * (which matches too, just less) is what comes next. **All six are still
     * dealt**: a cap that filtered would quietly shorten the deck and nobody
     * could say why.
     */
    public function test_the_deck_does_not_open_with_five_of_the_same_thing(): void
    {
        $this->actingAsUser(tags: ['Museums' => 3, 'Beaches' => 2]);

        foreach (range(1, 5) as $n) {
            $this->destination("Museum {$n}", 'Museums');
        }

        $beach = $this->destination('Baler Beach', 'Beaches');

        $deck = $this->deck();

        $this->assertCount(6, $deck, 'The cap reorders; it never drops a place.');

        $museum = $this->tagId('Museums');

        $openingRun = $deck
            ->take(4)
            ->filter(fn (Destination $d) => $this->taggedWith($d->id, $museum))
            ->count();

        $this->assertLessThanOrEqual(
            3,
            $openingRun,
            'The deck may not open with more than three of one tag.'
        );

        // Which means the beach is inside the first four, not last.
        $this->assertTrue(
            $deck->take(4)->contains('id', $beach->id),
            'Something else than a fourth museum comes next.'
        );
    }

    public function test_a_deferred_place_is_pushed_down_the_deck_not_removed(): void
    {
        $this->actingAsUser(tags: ['Museums' => 3]);

        foreach (range(1, 4) as $n) {
            $this->destination("Museum {$n}", 'Museums');
        }

        $deck = $this->deck();

        $this->assertCount(4, $deck, 'Nothing is filtered away.');
        $this->assertSame(
            ['Museum 1', 'Museum 2', 'Museum 3', 'Museum 4'],
            $deck->pluck('name')->all()
        );
    }

    // ------------------------------------------------------ the stretch card

    public function test_the_one_place_unlike_your_picks_comes_last_and_says_so(): void
    {
        $this->actingAsUser(tags: ['Beaches' => 2]);

        $this->destination('Baler Beach', 'Beaches');
        $this->destination('Sculpture Hill', 'none of these');

        $deck = $this->deck();

        $this->assertTrue(
            $deck->last()->is_novelty,
            'The stretch card belongs at the end of the deck.'
        );

        $this->assertCount(0, $deck->last()->matched_tags);

        $html = $this->deckHtml();

        $this->assertStringContainsString('Outside your interests', $html);
        $this->assertStringContainsString('Sculpture Hill', $html);
    }

    public function test_a_stretched_card_is_never_marked_a_match(): void
    {
        $this->actingAsUser(tags: ['Beaches' => 2]);

        $this->destination('Baler Beach', 'Beaches');
        $this->destination('Sculpture Hill', 'none of these');

        $html = $this->deckHtml();

        // Counts, not substrings: "0% match" is a substring of "100% match",
        // so a NotContainsString here would fail on the card that is
        // behaving perfectly. Two cards, two badges — one percentage, one
        // "outside your interests", and nothing claiming a score for the
        // card that has none.
        $this->assertSame(1, substr_count($html, '% match'));
        $this->assertSame(1, substr_count($html, 'Outside your interests'));
        $this->assertStringContainsString('100% match', $html);
    }

    public function test_the_stretch_card_is_never_one_you_already_decided(): void
    {
        $this->actingAsUser(tags: ['Beaches' => 2]);

        $this->destination('Baler Beach', 'Beaches');

        // Two unmatched places; the traveller skipped the one the catalogue
        // would otherwise offer.
        $skipped = $this->destination('First Unliked', 'none of these');
        $this->destination('Second Unliked', 'none of these');

        $this->postJson(route('discover.swipes.store'), [
            'destination_id' => $skipped->id,
            'action' => 'skip',
        ])->assertOk();

        $this->assertSame(
            'Second Unliked',
            $this->deck()->last()->name,
            'A card already dealt with cannot come back as the stretch card.'
        );
    }

    // --------------------------------- no matches means no stretch card, ever

    /**
     * THE EMPTY DECK STAYS EMPTY. The honest empty state names which filter
     * emptied the pool and offers two ways out; filling the deck with places
     * chosen for sharing nothing would replace that explanation with twenty
     * unrelated cards, which is the same lie in a different costume.
     */
    public function test_nothing_matching_means_an_empty_deck_not_a_wall_of_stretch_cards(): void
    {
        $this->actingAsUser(tags: ['Beaches' => 2]);

        $this->destination('Sculpture Hill', 'none of these');
        $this->destination('Old Church', 'also none');

        $this->assertCount(0, $this->deck());

        $html = $this->deckHtml();

        $this->assertStringNotContainsString('Outside your interests', $html);
        $this->assertStringContainsString('shares your interests', $html);
    }

    public function test_a_traveller_with_no_interests_at_all_still_gets_the_explanation(): void
    {
        $this->actingAsUser(tags: []);

        $this->destination('Sculpture Hill', 'none of these');

        $this->assertCount(0, $this->deck());
        $this->assertStringContainsString('shares your interests', $this->deckHtml());
    }

    // ------------------------------------------------------------- determinism

    public function test_the_same_traveller_is_dealt_the_same_deck_every_time(): void
    {
        $this->actingAsUser(tags: ['Museums' => 3, 'Beaches' => 2]);

        foreach (range(1, 6) as $n) {
            $this->destination("Museum {$n}", 'Museums');
        }

        $this->destination('Baler Beach', 'Beaches');
        $this->destination('Sculpture Hill', 'none of these');

        $first = $this->deck()->pluck('id')->all();
        $second = $this->deck()->pluck('id')->all();

        $this->assertSame($first, $second);
    }

    // ---------------------------------------------------------------- helpers

    private function deck()
    {
        return app(SwipeDeckService::class)->cards(User::findOrFail($this->userId));
    }

    private function deckHtml(): string
    {
        return (string) $this->get(route('discover.index'))->assertOk()->getContent();
    }

    private function actingAsUser(string $tier = 'economy', array $tags = []): void
    {
        $user = User::factory()->create();
        $this->userId = $user->id;

        TravelProfile::create([
            'user_id' => $user->id,
            'budget_level' => $tier,
            'group_size' => 2,
            'trip_duration_days' => 3,
            'preferred_region' => null,
        ]);

        foreach ($tags as $name => $weight) {
            $tag = Tag::firstOrCreate(['name' => $name, 'slug' => str($name)->slug()]);

            $user->travelProfile->tags()->attach($tag->id, ['weight' => $weight]);
        }

        $this->actingAs($user);
    }

    /**
     * @param  string|array<int, string>  $tagNames  One name, or several;
     *                                           a place with no tag at all is a
     *                                           fixture worth being able to write.
     */
    private function destination(string $name, string|array $tagNames): Destination
    {
        $destination = Destination::create([
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

        foreach ((array) $tagNames as $tagName) {
            $destination->tags()->attach($this->tagId($tagName));
        }

        return $destination;
    }

    private function destinationNamed(string $name): Destination
    {
        return Destination::where('name', $name)->firstOrFail();
    }

    private function tagId(string $name): int
    {
        return (int) Tag::firstOrCreate(['name' => $name, 'slug' => str($name)->slug()])->id;
    }

    private function taggedWith(int $destinationId, int $tagId): bool
    {
        return DB::table('destination_tag')
            ->where('destination_id', $destinationId)
            ->where('tag_id', $tagId)
            ->exists();
    }
}
