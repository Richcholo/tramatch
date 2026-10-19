<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BULK EDIT, SCOPED TO THE FOUR FIELDS THAT GATE VISIBILITY.
 *
 * Fixing a tier split across a hundred destinations one row at a time is the
 * sort of job an admin does once and then never wants to do again. The four
 * fields here — budget tier, featured, active, tags — are exactly the ones
 * that decide what a traveller is ever shown; everything else this app knows
 * about a destination is curated in the CSV and re-seeded over, so a bulk
 * action here cannot reach curated data even by accident.
 *
 * The invariants:
 *
 * - **EVERY FIELD IS OPTIONAL AND UNSET MEANS LEAVE ALONE.** An admin
 *   ticking twelve rows to archive them must not have to remember what their
 *   budget levels were.
 * - **THE OUTCOME IS COUNTED, NOT ASSUMED.** Applying the same edit twice
 *   must be able to say "nothing changed", or an admin who cannot tell a no-op
 *   from a success learns to distrust the message.
 * - **TAGS ARE ADDED AND REMOVED, NEVER REPLACED.** A `sync()` would strip
 *   every tag the admin did not name — the worst possible reading of "add
 *   Museums to these twelve".
 * - **A TAG CANNOT BE BOTH ADDED AND REMOVED** in one pass. The order it
 *   would be applied in would decide the answer, so it is refused by name.
 * - **NOTHING SELECTED IS NOT A NO-OP THAT SUCCEEDS**, and neither is
 *   submitting nothing to change.
 */
class AdminBulkDestinationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
    }

    // -------------------------------------------------------------- the basics

    public function test_a_bulk_tier_change_moves_every_selected_destination(): void
    {
        $first = $this->destination('Burnham Park');
        $second = $this->destination('Wright Park');
        $untouched = $this->destination('Mines View Park');

        $this->bulk([
            'ids' => [$first->id, $second->id],
            'budget_level' => 'premium',
        ])->assertRedirect();

        $this->assertSame('premium', $first->fresh()->budget_level);
        $this->assertSame('premium', $second->fresh()->budget_level);

        // A row nobody ticked does not move. "Leave unchanged" has to mean
        // that on an unticked row too.
        $this->assertSame('economy', $untouched->fresh()->budget_level);
    }

    public function test_the_tier_vocabulary_is_the_model_constant_not_a_copied_list(): void
    {
        foreach (array_keys(Destination::BUDGET_TIERS) as $tier) {
            $destination = $this->destination('Place '.ucfirst($tier));

            $this->bulk([
                'ids' => [$destination->id],
                'budget_level' => $tier,
            ])->assertSessionHasNoErrors();

            $this->assertSame($tier, $destination->fresh()->budget_level);
        }
    }

    public function test_a_tier_that_does_not_exist_is_refused(): void
    {
        $destination = $this->destination('Burnham Park');

        $this->bulk([
            'ids' => [$destination->id],
            'budget_level' => 'ultra-luxury',
        ])->assertSessionHasErrors('budget_level');

        $this->assertSame('economy', $destination->fresh()->budget_level);
    }

    // -------------------------------------------------- optional means optional

    public function test_a_bulk_archive_leaves_every_other_field_exactly_as_it_was(): void
    {
        $destination = $this->destination('Burnham Park');
        $destination->update(['budget_level' => 'mid-range', 'is_featured' => true]);

        $this->bulk([
            'ids' => [$destination->id],
            'is_active' => 'no',
        ])->assertSessionHasNoErrors();

        $fresh = $destination->fresh();

        $this->assertFalse($fresh->is_active);
        $this->assertSame('mid-range', $fresh->budget_level);
        $this->assertTrue($fresh->is_featured);
    }

    public function test_bulk_edits_are_reversible_by_another_bulk_edit(): void
    {
        $destination = $this->destination('Burnham Park');

        $this->bulk(['ids' => [$destination->id], 'is_active' => 'no']);
        $this->assertFalse($destination->fresh()->is_active);

        $this->bulk(['ids' => [$destination->id], 'is_active' => 'yes']);
        $this->assertTrue($destination->fresh()->is_active);
    }

    // --------------------------------------------------------------- the count

    /**
     * "12 destinations updated" counts rows VISITED, which is a claim about
     * the click rather than about the catalogue. Applying the same edit twice
     * has to be able to say nothing changed.
     */
    public function test_applying_the_same_bulk_edit_twice_says_nothing_changed(): void
    {
        $destination = $this->destination('Burnham Park');

        $this->bulk(['ids' => [$destination->id], 'budget_level' => 'premium'])
            ->assertSessionHas('status', '1 destination(s) updated.');

        $this->bulk(['ids' => [$destination->id], 'budget_level' => 'premium'])
            ->assertSessionHas(
                'status',
                'Nothing changed. All 1 selected destination(s) already had those values.'
            );
    }

    // -------------------------------------------------------------------- tags

    public function test_bulk_adding_a_tag_keeps_the_tags_already_there(): void
    {
        $beaches = Tag::create(['name' => 'Beaches', 'slug' => 'beaches']);
        $museums = Tag::create(['name' => 'Museums', 'slug' => 'museums']);

        $destination = $this->destination('Burnham Park');
        $destination->tags()->attach($beaches->id);

        $this->bulk([
            'ids' => [$destination->id],
            'add_tags' => [$museums->id],
        ])->assertSessionHasNoErrors();

        $fresh = $destination->fresh();

        // A sync() here would have stripped Beaches and left only Museums —
        // the worst possible reading of "add Museums to these rows".
        $this->assertEqualsCanonicalizing(
            [$beaches->id, $museums->id],
            $fresh->tags->pluck('id')->all()
        );
    }

    public function test_bulk_removing_a_tag_leaves_the_others(): void
    {
        $beaches = Tag::create(['name' => 'Beaches', 'slug' => 'beaches']);
        $museums = Tag::create(['name' => 'Museums', 'slug' => 'museums']);

        $destination = $this->destination('Burnham Park');
        $destination->tags()->attach([$beaches->id, $museums->id]);

        $this->bulk([
            'ids' => [$destination->id],
            'remove_tags' => [$beaches->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            [$museums->id],
            $destination->fresh()->tags->pluck('id')->all()
        );
    }

    public function test_a_tag_cannot_be_added_and_removed_at_once(): void
    {
        $museums = Tag::create(['name' => 'Museums', 'slug' => 'museums']);
        $destination = $this->destination('Burnham Park');
        $destination->tags()->attach($museums->id);

        $this->bulk([
            'ids' => [$destination->id],
            'add_tags' => [$museums->id],
            'remove_tags' => [$museums->id],
        ])->assertSessionHasErrors('bulk');

        $this->assertSame(
            [$museums->id],
            $destination->fresh()->tags->pluck('id')->all()
        );
    }

    public function test_a_tag_a_row_does_not_carry_is_not_reported_as_a_change(): void
    {
        $beaches = Tag::create(['name' => 'Beaches', 'slug' => 'beaches']);
        $destination = $this->destination('Burnham Park');
        $destination->tags()->attach($beaches->id);

        $this->bulk([
            'ids' => [$destination->id],
            'add_tags' => [$beaches->id],
        ])->assertSessionHas(
            'status',
            'Nothing changed. All 1 selected destination(s) already had those values.'
        );
    }

    // -------------------------------------------------------------- refusals

    public function test_nothing_selected_is_refused_rather_than_succeeding_at_nothing(): void
    {
        $destination = $this->destination('Burnham Park');

        $this->post(route('admin.destinations.bulk'), [
            'budget_level' => 'premium',
        ])->assertSessionHasErrors('ids');

        $this->assertSame('economy', $destination->fresh()->budget_level);
    }

    public function test_a_bulk_edit_with_nothing_to_change_is_refused(): void
    {
        $destination = $this->destination('Burnham Park');

        $this->bulk(['ids' => [$destination->id]])
            ->assertSessionHasErrors('bulk');
    }

    public function test_a_destination_that_does_not_exist_cannot_be_bulk_edited(): void
    {
        $destination = $this->destination('Burnham Park');

        $this->bulk([
            'ids' => [$destination->id, 999999],
            'budget_level' => 'premium',
        ])->assertSessionHasErrors('ids.1');

        $this->assertSame('economy', $destination->fresh()->budget_level);
    }

    public function test_a_stranger_cannot_bulk_edit_the_catalogue(): void
    {
        $destination = $this->destination('Burnham Park');

        $this->actingAs(User::factory()->create())
            ->post(route('admin.destinations.bulk'), [
                'ids' => [$destination->id],
                'budget_level' => 'premium',
            ])
            ->assertForbidden();

        $this->assertSame('economy', $destination->fresh()->budget_level);
    }

    // ------------------------------------------------------------------ markup

    /**
     * THE WRAPPER FORM HOLDS THE TABLE, SO NOTHING ELSE MAY BE A FORM INSIDE
     * IT. A nested form's start tag is discarded by the HTML parser and its
     * first `</form>` closes the OUTER one — here that would turn a Delete
     * click into a bulk edit over whatever was ticked.
     */
    public function test_no_admin_form_is_nested_inside_the_bulk_form(): void
    {
        $this->destination('Burnham Park');

        $html = (string) $this->get(route('admin.destinations.index'))->assertOk()->getContent();

        $this->assertStringContainsString('name="ids[]"', $html);
        $this->assertStringContainsString(route('admin.destinations.bulk'), $html);

        $bulkAt = strpos($html, route('admin.destinations.bulk'));

        $dom = new \DOMDocument();
        @$dom->loadHTML($html);

        $nested = 0;

        foreach ($dom->getElementsByTagName('form') as $form) {
            if (str_contains((string) $form->getAttribute('action'), 'destinations/bulk')) {
                $nested += $form->getElementsByTagName('form')->length;
            }
        }

        $this->assertSame(
            0,
            $nested,
            'The bulk wrapper must not contain another form.'
        );

        /*
         * AND THE POSITION OF THE WRAPPER'S CLOSE, measured on the RAW
         * markup. A DOM-nesting assertion alone is not enough, and the reason
         * is the trap itself: when a form is nested, the parser DISCARDS the
         * inner start tag, so the document contains no nested <form> element
         * to count. Removing the wrapper's closing tag therefore produced a
         * page whose forms all "passed" the nesting check while every row's
         * archive button had silently lost its form.
         */
        $wrapperClose = strpos($html, '</form>', $bulkAt);
        $firstRowForm = strpos($html, 'id="archive-destination-');

        $this->assertNotFalse($wrapperClose, 'The bulk wrapper must be closed.');
        $this->assertNotFalse($firstRowForm, 'The row forms must exist at all.');
        $this->assertLessThan(
            $firstRowForm,
            $wrapperClose,
            'The rows\' forms must come after the bulk wrapper closes.'
        );
    }

    /**
     * EVERY ROW BUTTON STILL POINTS AT A FORM THAT EXISTS. Moving the forms
     * out of the rows is only safe if each button's `form="..."` resolves to a
     * real id — a rename on one side is a dead control.
     */
    public function test_every_row_button_points_at_a_form_that_exists(): void
    {
        $destination = $this->destination('Burnham Park');

        $html = (string) $this->get(route('admin.destinations.index'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'form="archive-destination-'.$destination->id.'"',
            $html
        );
        $this->assertStringContainsString(
            'id="archive-destination-'.$destination->id.'"',
            $html
        );
        $this->assertStringContainsString(
            'id="restore-destination-'.$destination->id.'"',
            $html
        );
    }

    // ---------------------------------------------------------------- helpers

    private function bulk(array $payload)
    {
        return $this->post(route('admin.destinations.bulk'), $payload);
    }

    private function destination(string $name): Destination
    {
        return Destination::create([
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
            'is_featured' => false,
            'is_active' => true,
        ]);
    }
}
