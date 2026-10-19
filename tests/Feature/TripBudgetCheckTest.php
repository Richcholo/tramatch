<?php

namespace Tests\Feature;

use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "DOES THIS TRIP FIT?" — THE QUESTION NOTHING WAS ANSWERING.
 *
 * A trip could show a peso total and nothing else, so the only way to know
 * whether a plan fitted a budget was to do the subtraction by hand and hope
 * the figure was complete. It often is not: an unpriced stop is stored as 0
 * because the column cannot hold null, a hotel is charged once for the trip
 * rather than per night, and a place contributes its price band's midpoint.
 *
 * The invariants here:
 *
 * - **THE CEILING IS THE TRAVELLER'S FIGURE.** `budget_level` is a
 *   catalogue filter (ADR 0002), so no peso amount is ever derived from it —
 *   inventing one would be publishing a number nobody did.
 * - **NO CEILING, NO VERDICT.** `budgetCheck()` returns null and the hero
 *   renders nothing; "₱0 of ₱0" would be a verdict nobody stated.
 * - **THE VERDICT NEVER CLAIMS COMPLETENESS.** It says "in verified prices
 *   so far" and the unpriced count sits beside it. A line that printed the
 *   arithmetic alone would make a partly-priced trip read as a final one.
 * - **GUESTS READ THE VERDICT AND CANNOT TOUCH THE FIELD.** The sentence
 *   lives in the shared hero; the form is `@auth`-guarded inside it.
 * - **ANOTHER TRAVELLER'S BUDGET IS NOT THEIRS TO SET.** The same
 *   `ensureOwner()` boundary every other write on a trip uses.
 */
class TripBudgetCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $traveller;

    private Itinerary $trip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traveller = User::factory()->create();

        $this->trip = Itinerary::create([
            'user_id' => $this->traveller->id,
            'title' => 'Benguet weekend',
            'area' => 'Benguet',
            'budget_level' => 'economy',
            'trip_duration_days' => 1,
            'total_estimated_cost' => 8400,
        ]);

        ItineraryDay::create([
            'itinerary_id' => $this->trip->id,
            'day_number' => 1,
        ]);
    }

    // ------------------------------------------------------- no ceiling, no line

    public function test_a_trip_with_no_budget_asked_about_states_no_verdict(): void
    {
        $html = $this->editorHtml();

        // The total is still a fact about the trip; the verdict is not there
        // because nobody stated a ceiling.
        $this->assertStringContainsString('₱8,400.00', $html);
        $this->assertStringNotContainsString('Against your', $html);

        $this->assertNull($this->trip->budgetCheck());
    }

    public function test_the_budget_field_is_there_before_a_budget_is(): void
    {
        $html = $this->editorHtml();

        // The control that SETS a ceiling has to render while there is none,
        // or the only way to reach one is... to already have one.
        $this->assertStringContainsString('name="budget_amount"', $html);
        $this->assertStringContainsString(route('itineraries.budget.update', $this->trip), $html);
    }

    // ------------------------------------------------------------- under, and over

    public function test_a_trip_inside_its_budget_says_what_is_left(): void
    {
        $this->setBudget(15000);

        $html = $this->editorHtml();

        $this->assertStringContainsString('Against your ₱15,000.00 budget', $html);
        $this->assertStringContainsString('₱8,400.00 in verified prices so far', $html);
        $this->assertStringContainsString('leaving ₱6,600.00', $html);

        // Pinned on the VERDICT's phrase, not the bare word: "already"
        // appears in unrelated copy on this page (the add-a-stop dialog says
        // "Every place you liked is already on this trip"), so a loose
        // assertion here would be asserting about that.
        $this->assertStringNotContainsString('already ₱', $html);
    }

    public function test_a_trip_over_its_budget_says_by_how_much(): void
    {
        $this->setBudget(5000);

        $html = $this->editorHtml();

        $this->assertStringContainsString('Against your ₱5,000.00 budget', $html);
        $this->assertStringContainsString('already ₱3,400.00 over', $html);

        $check = $this->trip->budgetCheck();

        $this->assertTrue($check['over']);
        $this->assertEquals(-3400.0, $check['remaining']);
    }

    public function test_a_trip_exactly_on_its_budget_is_not_over_it(): void
    {
        $this->setBudget(8400);

        $check = $this->trip->fresh()->budgetCheck();

        // Equality is not over: a plan that lands exactly on the ceiling has
        // fitted it, and saying "already ₱0.00 over" would be a false alarm
        // dressed as precision.
        $this->assertFalse($check['over']);
        $this->assertEquals(0.0, $check['remaining']);
    }

    // ---------------------------------------------------------- the honest caveat

    public function test_a_partly_priced_trip_says_the_figure_is_only_so_far(): void
    {
        ItineraryItem::create([
            'itinerary_day_id' => $this->trip->days->first()->id,
            'item_type' => 'custom',
            'sort_order' => 1,
            'custom_name' => 'A stop of their own',
            'estimated_cost' => 0,
        ]);

        $this->setBudget(15000);

        $html = $this->editorHtml();

        // Both halves of one sentence, from two places: "so far" from the
        // verdict's own wording, the count from the notice above it. Assert
        // the qualifier travels WITH the verdict — a verdict that reads
        // complete while a third of the trip is unpriced is the lie this
        // feature exists not to tell.
        $this->assertStringContainsString('in verified prices so far', $html);
        $this->assertStringContainsString('stop(s) without a verified price', $html);
    }

    // ------------------------------------------------------- the field, end to end

    public function test_a_stated_budget_is_stored_and_read_back(): void
    {
        $this->actingAs($this->traveller)
            ->patch(route('itineraries.budget.update', $this->trip), [
                'budget_amount' => 15000,
            ])
            ->assertRedirect(route('itineraries.show', $this->trip));

        $this->assertDatabaseHas('itineraries', [
            'id' => $this->trip->id,
            'budget_amount' => 15000,
        ]);

        $this->assertStringContainsString(
            'Against your',
            $this->editorHtml()
        );
    }

    public function test_clearing_the_field_removes_the_ceiling(): void
    {
        $this->setBudget(15000);

        $this->actingAs($this->traveller)
            ->patch(route('itineraries.budget.update', $this->trip), [
                'budget_amount' => '',
            ])
            ->assertRedirect(route('itineraries.show', $this->trip));

        $this->assertNull($this->trip->fresh()->budget_amount);
        $this->assertNull($this->trip->fresh()->budgetCheck());
        $this->assertStringNotContainsString('Against your', $this->editorHtml());
    }

    public function test_a_zero_is_not_a_budget(): void
    {
        $this->actingAs($this->traveller)
            ->patch(route('itineraries.budget.update', $this->trip), [
                'budget_amount' => 0,
            ])
            ->assertSessionHasErrors('budget_amount');

        // ₱0 would render as a trip that is instantly, absurdly over, and a
        // refusal with an explanation beats storing it.
        $this->assertNull($this->trip->fresh()->budget_amount);
    }

    public function test_text_where_a_peso_amount_belongs_is_refused(): void
    {
        $this->actingAs($this->traveller)
            ->patch(route('itineraries.budget.update', $this->trip), [
                'budget_amount' => 'a lot',
            ])
            ->assertSessionHasErrors('budget_amount');

        $this->assertNull($this->trip->fresh()->budget_amount);
    }

    public function test_another_traveller_cannot_set_a_budget_on_someone_elses_trip(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->patch(route('itineraries.budget.update', $this->trip), [
                'budget_amount' => 15000,
            ])
            ->assertForbidden();

        $this->assertNull($this->trip->fresh()->budget_amount);
    }

    // ------------------------------------------------------- the shared read surface

    public function test_a_guest_reads_the_verdict_and_never_the_field(): void
    {
        $this->setBudget(15000);

        $token = $this->trip->sharedToken();

        $html = (string) $this->get(route('itineraries.shared', [
            'itinerary' => $this->trip,
            'token' => $token,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Against your', $html);

        // The zero-forms invariant of the share page, restated where the new
        // write lives: an unguarded field here would render a stranger a
        // control for someone else's trip.
        $this->assertStringNotContainsString('name="budget_amount"', $html);
        $this->assertStringNotContainsString(
            route('itineraries.budget.update', $this->trip),
            $html
        );
    }

    /**
     * THE BUDGET FORM IS A SIBLING OF THE EDITOR FORM, NEVER INSIDE IT.
     *
     * The same trap the add-a-day form has: a `<form>` nested in another
     * `<form>` has its start tag discarded by the HTML parser, and the first
     * inner `</form>` then closes the OUTER one — taking every stop field
     * with it, so a save would post a budget and no itinerary at all.
     */
    public function test_the_budget_form_is_not_nested_inside_the_editor_form(): void
    {
        $this->setBudget(15000);

        $html = $this->editorHtml();

        $this->assertStringContainsString('data-editor-form', $html);

        $dom = new \DOMDocument();
        @$dom->loadHTML($html);

        $editorForms = $dom->getElementsByTagName('form');
        $nested = 0;

        foreach ($editorForms as $form) {
            if ($form->getElementsByTagName('input')->length > 0
                && $form->getAttribute('data-editor-form') !== ''
            ) {
                $nested += $form->getElementsByTagName('form')->length;
            }
        }

        $this->assertSame(0, $nested);
    }

    // ------------------------------------------------------------------- helpers

    private function setBudget(float $amount): void
    {
        $this->trip->update(['budget_amount' => $amount]);
        $this->trip = $this->trip->fresh();
    }

    private function editorHtml(): string
    {
        return (string) $this->actingAs($this->traveller)
            ->get(route('itineraries.show', $this->trip))
            ->assertOk()
            ->getContent();
    }
}
