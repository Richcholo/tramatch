<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use App\Services\SwipeDeckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE TAKE-BACK, AND A "NO" THAT IS FINALLY READ BACK.
 *
 * Three decisions now exist on the deck: a like, a pass and a skip. The
 * plan's complaint was that `destination_swipes` rows were written and never
 * read anywhere, so "no opinion" and "no" were indistinguishable in the
 * data — a pass cost the traveller the card and nothing else.
 *
 * The invariants these tests own:
 *
 * - A SKIP IS NOT A PASS. It removes the card and never scores a place
 *   down; the two actions stay distinguishable in the table itself.
 * - A PASS PENALISES ITS TAGS. The passed rows are read back, and every
 *   pass subtracts one step from that tag's weight, floored at zero.
 * - EVERY DECISION IS UNDOABLE. Undo pops the MOST RECENT row — its —
 *   not the first the traveller ever made, and returns what it freed.
 */
class SwipeReversibilityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = false;

    // --------------------------------------------------- skip vs pass, in data

    public function test_a_skip_and_a_pass_are_distinguishable_in_the_data(): void
    {
        $this->actingAsUser(tags: ['Beaches' => 1]);
        $skipped = $this->destination('Saud Beach', 'economy');
        $passed = $this->destination('Nagsasa Cove', 'economy');

        $this->postSwipe($skipped, 'skip')->assertOk();
        $this->postSwipe($passed, 'passed')->assertOk();

        $this->assertDatabaseHas('destination_swipes', [
            'user_id' => $this->userId,
            'destination_id' => $skipped->id,
            'action' => 'skip',
        ]);
        $this->assertDatabaseHas('destination_swipes', [
            'user_id' => $this->userId,
            'destination_id' => $passed->id,
            'action' => 'passed',
        ]);
    }

    // ---------------------------------------------- a pass is read back, at last

    public function test_a_pass_penalises_its_tags_in_the_deck(): void
    {
        $beaches = Tag::firstOrCreate(['name' => 'Beaches', 'slug' => 'beaches']);

        $this->actingAsUser(tags: ['Beaches' => 1]);

        $swiped = $this->destination('Saud Beach', 'economy');
        $swiped->tags()->attach($beaches->id);

        // A SECOND place carrying the same tag: invisible to the swipe that
        // only ever excluded its own row, so this is where the penalty is
        // observable — a pass on one beach now weighs on the other beach.
        $survivor = $this->destination('Nagsasa Cove', 'economy');
        $survivor->tags()->attach($beaches->id);

        $deck = fn () => app(SwipeDeckService::class)
            ->cards(User::findOrFail($this->userId))
            ->pluck('id');

        $this->assertTrue($deck()->contains($survivor->id));

        $this->postSwipe($swiped, 'passed')->assertOk();

        $this->assertFalse($deck()->contains($survivor->id));
    }

    public function test_a_skip_is_never_a_penalised_no(): void
    {
        $beaches = Tag::firstOrCreate(['name' => 'Beaches', 'slug' => 'beaches']);

        $this->actingAsUser(tags: ['Beaches' => 1]);

        $skipped = $this->destination('Saud Beach', 'economy');
        $skipped->tags()->attach($beaches->id);

        $survivor = $this->destination('Nagsasa Cove', 'economy');
        $survivor->tags()->attach($beaches->id);

        $this->postSwipe($skipped, 'skip')->assertOk();

        // A skip carries no opinion: the card leaves the deck, and the
        // place sharing its tag stays at its FULL weight. This is the
        // line between "decide later" and "no" — the same fixture with a
        // pass instead drops the survivor, which
        // test_a_pass_penalises_its_tags_in_the_deck pins on purpose.
        $survivors = app(SwipeDeckService::class)
            ->cards(User::findOrFail($this->userId))
            ->pluck('id');

        $this->assertTrue($survivors->contains($survivor->id));
    }

    // ----------------------------------------------------------- the take-back

    public function test_undo_pops_the_most_recent_decision_only(): void
    {
        $this->actingAsUser(tags: ['Beaches' => 1]);

        $liked = $this->destination('Saud Beach', 'economy');
        $passed = $this->destination('Nagsasa Cove', 'economy');

        $this->postSwipe($liked, 'liked')->assertOk();
        $this->postSwipe($passed, 'passed')->assertOk();

        $response = $this->post(route('discover.swipes.undo'));
        $response->assertOk()->assertJson(['destination_id' => $passed->id]);

        $this->assertDatabaseMissing('destination_swipes', [
            'user_id' => $this->userId,
            'destination_id' => $passed->id,
        ]);
        $this->assertDatabaseHas('destination_swipes', [
            'user_id' => $this->userId,
            'destination_id' => $liked->id,
        ]);
    }

    public function test_an_undone_skip_returns_the_card_to_the_deck(): void
    {
        $beaches = Tag::firstOrCreate(['name' => 'Beaches', 'slug' => 'beaches']);

        $this->actingAsUser(tags: ['Beaches' => 1]);
        $skipped = $this->destination('Saud Beach', 'economy');
        $skipped->tags()->attach($beaches->id);
        $survivor = $this->destination('Nagsasa Cove', 'economy');
        $survivor->tags()->attach($beaches->id);

        $this->postSwipe($skipped, 'skip')->assertOk();

        $this->assertStringNotContainsString(
            $skipped->name,
            $this->deckHtml()
        );
        $this->assertStringContainsString($survivor->name, $this->deckHtml());

        $this->post(route('discover.swipes.undo'))->assertOk();

        $this->assertStringContainsString($skipped->name, $this->deckHtml());
    }

    public function test_the_undo_button_hides_until_something_is_decided(): void
    {
        $this->actingAsUser(tags: ['Beaches' => 1]);
        $this->destination('Saud Beach', 'economy');

        $this->assertStringNotContainsString('data-swipe-undo', $this->deckHtml());

        $this->postSwipe(
            Destination::firstWhere('name', 'Saud Beach'),
            'skip'
        )->assertOk();

        $html = $this->deckHtml();

        $this->assertStringContainsString('data-swipe-undo', $html);
        $this->assertStringContainsString('Undo last', $html);
    }

    public function test_nothing_to_undo_answers_without_an_error(): void
    {
        $this->actingAsUser(tags: ['Beaches' => 1]);
        $this->destination('Saud Beach', 'economy');

        $this->post(route('discover.swipes.undo'))->assertNoContent();
    }

    public function test_another_user_s_decisions_are_not_popped(): void
    {
        $this->actingAsUser(tags: ['Beaches' => 1]);
        $first = $this->destination('Saud Beach', 'economy');

        DestinationSwipe::create([
            'user_id' => $this->userId,
            'destination_id' => $first->id,
            'action' => 'liked',
        ]);

        // A SECOND traveller, holding nothing: their undo must not reach
        // into anyone else's decisions, and a pop that finds nothing of
        // their own answers 204 without touching the row above.
        $user = User::factory()->create();
        TravelProfile::create([
            'user_id' => $user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 3,
        ]);
        $this->actingAs($user);

        $this->post(route('discover.swipes.undo'))->assertNoContent();

        $this->assertDatabaseHas('destination_swipes', [
            'user_id' => $this->userId,
            'destination_id' => $first->id,
            'action' => 'liked',
        ]);
    }

    // ---------------------------------------------------------------- helpers

    private function postSwipe(Destination $destination, string $action)
    {
        return $this->postJson(route('discover.swipes.store'), [
            'destination_id' => $destination->id,
            'action' => $action,
        ]);
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
