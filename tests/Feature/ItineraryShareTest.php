<?php

namespace Tests\Feature;

use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE ITINERARY AS A LINK SOMEBODY CAN HOLD WITHOUT AN ACCOUNT.
 *
 * The plan's line: "Signed token, no auth, no edit. The artifact that
 * outlives a defence." Three facts carry the feature, and each is
 * load-bearing:
 *
 * **THE COLUMN IS THE ACCESS CONTROL.** An itinerary that never received a
 * token has nothing to match a presented one against, so unshared is not
 * "hard to reach" — it is unreachable, on any URL shape.
 *
 * **MINTING NEVER HAPPENS BY ACCIDENT.** The token is created on the share
 * page the owner opens, and a background process or a page view from a
 * stranger can never write it. The record of "this trip was shared" is
 * therefore always a record that the owner DID something.
 *
 * **ROTATION IS THE REVOCATION.** A signed link is revoked by replacing its
 * secret, and the old link must actually stop working afterwards — tested
 * with the OLD token, not with the new one, because the failure to look for
 * is an old link that still opens.
 */
class ItineraryShareTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Itinerary $trip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->trip = Itinerary::create([
            'user_id' => $this->user->id,
            'title' => 'Benguet weekend',
            'area' => 'Benguet',
            'budget_level' => 'economy',
            'trip_duration_days' => 1,
        ]);

        ItineraryDay::create([
            'itinerary_id' => $this->trip->id,
            'day_number' => 1,
        ]);

        ItineraryItem::create([
            'itinerary_day_id' => $this->trip->days->first()->id,
            'item_type' => 'custom',
            'sort_order' => 1,
            'custom_name' => 'Burnham Park walk',
            'custom_address' => 'Session Road, Baguio',
            'custom_latitude' => 16.4095,
            'custom_longitude' => 120.5960,
            'estimated_cost' => 120,
        ]);
    }

    // ------------------------------------------------------------- the public link

    public function test_a_token_from_nowhere_is_not_an_entry(): void
    {
        $this->get(route('itineraries.shared', [
            'itinerary' => $this->trip,
            'token' => str_repeat('a', 40),
        ]))->assertNotFound();
    }

    /**
     * THE COLUMN DECIDES, NOT THE VIEW. An itinerary whose token was cleared
     * is unshared; a link minted BEFORE the clearing keeps its token, and the
     * fact that this probe holds the old token makes the test about
     * revocation and not about a wrong guess.
     */
    public function test_a_cleared_token_leaves_a_held_link_dead(): void
    {
        $token = $this->trip->sharedToken();

        $this->get(route('itineraries.shared', [
            'itinerary' => $this->trip,
            'token' => $token,
        ]))->assertOk();

        $this->trip->update(['share_token' => null]);

        $this->get(route('itineraries.shared', [
            'itinerary' => $this->trip,
            'token' => $token,
        ]))->assertNotFound();
    }

    /**
     * THE GUEST PAGE IS THE READ SURFACE AND NOTHING MANAGES IT.
     *
     * ONE `name="_token"` is allowed, and it is the layout's own logout
     * dialog, which renders on every app-layout page including every guest
     * page — its POST is dead without a session and Laravel's own auth
     * middleware is what answers it. What must count as ZERO is the trip
     * content: the hero carries no owner buttons to guests, the days grid
     * carries no editor form, and the share route is never minted from here.
     */
    public function test_the_shared_page_holds_nothing_that_writes(): void
    {
        $token = $this->trip->sharedToken();

        $html = (string) $this->get(route('itineraries.shared', [
            'itinerary' => $this->trip,
            'token' => $token,
        ]))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'name="_token"'), sprintf(
            'the trip content rendered %d forms of its own (the layout\'s logout dialog accounts for exactly 1)',
            substr_count($html, 'name="_token"') - 1
        ));        $this->assertStringContainsString(
            'action="'.route('logout').'"',
            $html,
            'the one form must be the layout\'s logout, not a trip control'
        );

        $this->assertStringNotContainsString('data-editor-form', $html);
        $this->assertStringNotContainsString('data-editor-toggle', $html);
        $this->assertStringContainsString('Burnham Park walk', $html);

        // The delete dialog is ALWAYS an owner control: it is what the hero's
        // red button opens. On a guest page the button cannot exist, and
        // neither can the dialog its click target points at.
        $this->assertStringNotContainsString('delete-itinerary', $html);
    }

    // ----------------------------------------------------------------- minting

    public function test_the_column_is_null_until_the_owner_actually_opens_the_share_page(): void
    {
        $this->assertNull($this->trip->fresh()->share_token);
    }

    public function test_the_share_page_still_mints_nothing_when_the_owner_only_visits(): void
    {
        $html = (string) $this->actingAs($this->user)
            ->get(route('itineraries.share', $this->trip))
            ->assertOk()
            ->getContent();

        $this->assertNull($this->trip->fresh()->share_token);
        $this->assertStringContainsString('No share link exists', $html);
    }

    public function test_sharing_from_the_page_mints_a_token(): void
    {
        $this->actingAs($this->user)
            ->from(route('itineraries.share', $this->trip))
            ->post(route('itineraries.share.regenerate', $this->trip), ['confirm' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $fresh = $this->trip->fresh();

        $this->assertNotNull($fresh->share_token);
        $this->assertSame(40, strlen($fresh->share_token));

        $this->get(route('itineraries.shared', [
            'itinerary' => $this->trip,
            'token' => $fresh->share_token,
        ]))->assertOk();
    }

    /**
     * MINTING IS ALWAYS A DECISION. The share page never writes the column
     * itself — only the POST does.
     */
    public function test_the_minted_url_is_a_real_address_and_shown_as_one(): void
    {
        $token = $this->trip->sharedToken();

        $html = (string) $this->actingAs($this->user)
            ->get(route('itineraries.share', $this->trip))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'href="'.route('itineraries.shared', ['itinerary' => $this->trip, 'token' => $token]).'"',
            $html
        );
        $this->assertStringContainsString('Live share link', $html);
    }

    // ---------------------------------------------------------------- rotation

    public function test_rotation_kills_the_old_token_and_mints_a_new_one(): void
    {
        $first = $this->trip->sharedToken();

        $this->actingAs($this->user)
            ->from(route('itineraries.share', $this->trip))
            ->post(route('itineraries.share.regenerate', $this->trip), ['confirm' => '1'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($message) => str_contains($message, 'no longer works'));

        $second = $this->trip->fresh()->share_token;

        $this->assertNotSame($first, $second);

        $this->get(route('itineraries.shared', [
            'itinerary' => $this->trip,
            'token' => $first,
        ]))->assertNotFound('the revocation never happened');

        $this->get(route('itineraries.shared', [
            'itinerary' => $this->trip,
            'token' => $second,
        ]))->assertOk();
    }

    /**
     * THE DESTRUCTIVE-ACTION CONFIRMATION IS THE BOUNDARY. A missing
     * confirmation is not silent success — the owner is told exactly what
     * would have happened, and the token is unchanged.
     */
    public function test_rotation_without_confirmation_rotates_nothing(): void
    {
        $token = $this->trip->sharedToken();

        $this->actingAs($this->user)
            ->from(route('itineraries.share', $this->trip))
            ->post(route('itineraries.share.regenerate', $this->trip))
            ->assertSessionHasErrors('share');

        $this->assertSame($token, $this->trip->fresh()->share_token);
    }

    // -------------------------------------------------------------- ownership

    public function test_nobody_else_can_open_the_share_page_or_rotate_the_token(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('itineraries.share', $this->trip))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('itineraries.share.regenerate', $this->trip), ['confirm' => '1'])
            ->assertForbidden();

        $this->assertNull($this->trip->fresh()->share_token);
    }

    // -------------------------------------------------------- the owner page

    /**
     * THE ENTRY POINT LIVES ON THE OWNER'S OWN EDITOR PAGE, never on the
     * shared partial's hero: the hero renders for guests too, and a
     * minting button in there would be visible to every stranger.
     */
    public function test_the_share_entry_point_is_on_the_owner_page_and_not_on_the_shared_hero(): void
    {
        $token = $this->trip->sharedToken();

        $owner = (string) $this->actingAs($this->user)
            ->get(route('itineraries.show', $this->trip))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('itineraries.share', $this->trip), $owner);

        $share = (string) $this->get(route('itineraries.shared', [
            'itinerary' => $this->trip,
            'token' => $token,
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('itineraries.share', $share);
    }
}
