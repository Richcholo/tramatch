<?php

namespace Tests\Feature\Admin;

use App\Models\Destination;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The budget tier guideline on the admin destination form.
 *
 * `budget_level` is an exact match against a traveller's travel profile, not a
 * score: SwipeDeckService, RecommendationService, ItineraryGenerator and
 * SwipeDiscoveryController all filter `where('budget_level', $profile-
 * >budget_level)`. A destination filed under the wrong tier therefore does not
 * rank lower, it disappears for two thirds of users. The guideline exists
 * because that is a much worse failure than a slightly-wrong score.
 *
 * The auto-fill and the warning are JavaScript and cannot be exercised here --
 * there is no browser automation in this project. What IS pinned is the part
 * that can be: the boundaries themselves, and the guideline actually rendering
 * them. A JS copy of these numbers would be free to drift from the labels above
 * the field, so the module reads them back out of the rendered markup instead.
 */
class BudgetTierTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function destination(array $attributes = []): Destination
    {
        return Destination::create(array_merge([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'description' => 'A place.',
            'province' => 'Manila',
            'municipality' => 'Manila',
            'latitude' => 14.6,
            'longitude' => 120.9,
            'budget_level' => 'economy',
            'entrance_fee' => 100,
            'estimated_cost' => 500,
            'recommended_minutes' => 90,
        ], $attributes));
    }

    /**
     * The boundaries, including the edges.
     *
     * The original wording was "Mid 501-1000" alongside "Premium 1000 and up",
     * which double-counts exactly 1000. Mid therefore stops at 999.
     */
    #[Test]
    #[DataProvider('costs')]
    public function a_cost_maps_to_the_documented_tier(int $cost, string $expected): void
    {
        $this->assertSame($expected, Destination::budgetTierForCost($cost));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function costs(): array
    {
        return [
            'free is economy' => [0, 'economy'],
            'top of economy' => [500, 'economy'],
            'just over economy' => [501, 'mid-range'],
            'top of mid' => [999, 'mid-range'],
            'bottom of premium' => [1000, 'premium'],
            'well into premium' => [25000, 'premium'],
        ];
    }

    /**
     * The input is a number field an admin is still typing into, so it can be
     * empty, half-typed or negative. None of that may throw.
     */
    #[Test]
    #[DataProvider('junk')]
    public function nonsense_input_falls_back_to_the_bottom_tier(mixed $cost): void
    {
        $this->assertSame('economy', Destination::budgetTierForCost($cost));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function junk(): array
    {
        return [
            'empty string' => [''],
            'null' => [null],
            'not a number' => ['abc'],
            'negative' => [-50],
        ];
    }

    /**
     * The bands must not overlap, or a single peso figure would have two
     * defensible answers and the guideline would be quietly wrong.
     */
    #[Test]
    public function the_tiers_do_not_overlap(): void
    {
        $tiers = Destination::BUDGET_TIERS;

        $this->assertSame('economy', array_key_first($tiers));
        $this->assertNull(end($tiers)['max'], 'the top tier must be open-ended');

        $previousMax = null;

        foreach ($tiers as $key => $tier) {
            if ($previousMax !== null) {
                $this->assertSame(
                    $previousMax + 1,
                    $tier['min'],
                    'the '.$key.' band starts at '.($previousMax + 1).', leaving a gap or an overlap'
                );
            }

            $previousMax = $tier['max'];
        }
    }

    /**
     * Every boundary figure gets swept, so no peso amount is unclassified.
     */
    #[Test]
    public function every_boundary_figure_classifies(): void
    {
        foreach (Destination::BUDGET_TIERS as $key => $tier) {
            $this->assertSame(
                $key,
                Destination::budgetTierForCost($tier['min']),
                'the bottom of '.$key.' should classify as '.$key
            );

            if ($tier['max'] !== null) {
                $this->assertSame(
                    $key,
                    Destination::budgetTierForCost($tier['max']),
                    'the top of '.$key.' should classify as '.$key
                );
            }
        }
    }

    /**
     * The readable ranges, which is what the admin actually reads.
     */
    #[Test]
    public function the_ranges_render_the_figures_the_admin_was_given(): void
    {
        $ranges = Destination::budgetTierRanges();

        $this->assertSame('Free – ₱500', $ranges['economy']);
        $this->assertSame('₱501 – ₱999', $ranges['mid-range']);
        $this->assertSame('₱1,000 and up', $ranges['premium']);
    }

    /**
     * The guideline is on the form, on both create and edit.
     *
     * A guideline that is only on the edit screen does not help the admin who
     * is about to create the row.
     */
    #[Test]
    public function the_guideline_renders_on_both_admin_forms(): void
    {
        $destination = $this->destination();

        foreach ([
            'create' => route('admin.destinations.create'),
            'edit' => route('admin.destinations.edit', $destination),
        ] as $name => $url) {
            $html = $this->actingAs($this->admin())->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('data-budget-tier', $html, 'the '.$name.' form has no budget tier guideline');
            $this->assertStringContainsString('data-budget-tier-select', $html);
            $this->assertStringContainsString('data-budget-tier-cost', $html);
            $this->assertStringContainsString('Free – ₱500', $html);
            $this->assertStringContainsString('₱1,000 and up', $html);
        }
    }

    /**
     * The select must offer exactly the tiers the guideline describes.
     *
     * A fourth option, or a renamed one, would leave the JS matching a label
     * that is not in the select and silently failing to auto-fill.
     */
    #[Test]
    public function the_select_offers_exactly_the_tiers_the_guideline_describes(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('admin.destinations.create'))
            ->assertOk()
            ->getContent();

        $document = new \DOMDocument();

        @$document->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($document);

        $selects = $xpath->query('//select[@data-budget-tier-select]');

        $this->assertSame(
            1,
            $selects->length,
            'expected exactly one budget tier select, found '.$selects->length
        );

        $values = [];

        foreach ($xpath->query('.//option', $selects->item(0)) as $option) {
            $values[] = $option->getAttribute('value');
        }

        $this->assertSame(
            array_keys(Destination::BUDGET_TIERS),
            $values,
            'the budget select and the guideline disagree about the tiers'
        );
    }

    /**
     * The warning is hidden by the ATTRIBUTE.
     *
     * Tailwind's `hidden` class is display:none at author level and beats the
     * browser's own `[hidden] { display: none }` rule, so an element carrying
     * both can never be revealed by `element.hidden = false` -- the admin would
     * simply never see the mismatch. This has bitten this codebase before.
     */
    #[Test]
    public function the_warning_carries_no_hidden_class(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('admin.destinations.create'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<p[^>]*\bdata-budget-tier-warning\b(?![^>]*\bclass="[^"]*\bhidden\b)[^>]*>/s',
            $html,
            'the warning element carries a Tailwind `hidden` class, which is display:none at '
            .'author level and outranks the [hidden] attribute the script toggles, so it can '
            .'never be shown'
        );
    }

    /**
     * Saving still works: the guideline is a helper, not a gate. An admin who
     * files a deliberate override must be able to keep it.
     */
    #[Test]
    public function a_deliberate_override_is_still_saved(): void
    {
        $destination = $this->destination(['estimated_cost' => 50, 'budget_level' => 'economy']);

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                [
                    'name' => $destination->name,
                    'description' => $destination->description,
                    'province' => $destination->province,
                    'municipality' => $destination->municipality,
                    'latitude' => $destination->latitude,
                    'longitude' => $destination->longitude,
                    // deliberately inconsistent, and allowed through
                    'budget_level' => 'premium',
                    'entrance_fee' => $destination->entrance_fee,
                    'estimated_cost' => 50,
                    'recommended_minutes' => $destination->recommended_minutes,
                    'is_active' => '1',
                    'tags' => [Tag::firstOrCreate(['slug' => 'nature'], ['name' => 'Nature'])->id],
                ]
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'premium',
            $destination->refresh()->budget_level,
            'the guideline must warn about a mismatch, never silently correct one'
        );
    }
}
