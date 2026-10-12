<?php

namespace Tests\Feature;

use App\Models\Destination;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MOVING BETWEEN PLACES: the strip under the stage, and quickhop.
 *
 * Two controls that both mean "somewhere else", with a third already on the page
 * that means something else entirely. The stage's chevrons move the FAN -- one
 * destination's photographs, no navigation -- and the strip changes WHICH
 * destination the page is about. Keeping those apart is most of what is pinned
 * here.
 */
class PlaceNavigationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, Destination> */
    private array $places;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Three places in a deliberate catalogue order, with names that sort the
         * same way as their `sort_order` so a test that accidentally relies on
         * the name tiebreak still passes -- and one that relies on `sort_order`
         * alone would fail loudly if it ever began ignoring it.
         */
        $this->places = [];

        foreach ([['Aklatan', 0], ['Baler', 1], ['Caliraya', 2]] as [$name, $order]) {
            $this->places[] = Destination::create([
                'name' => $name,
                'slug' => strtolower($name),
                'description' => 'A place in Luzon.',
                'province' => 'Laguna',
                'municipality' => 'Real',
                'latitude' => 14.1,
                'longitude' => 121.5,
                'budget_level' => 'economy',
                'estimated_cost' => 450,
                'recommended_minutes' => 120,
                'is_active' => true,
                'is_featured' => true,
                'sort_order' => $order,
            ]);
        }
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument();

        @$document->loadHTML($html, LIBXML_NOERROR);

        return new DOMXPath($document);
    }

    /**
     * THE STRIP NAMES ITS NEIGHBOURS, because an arrow cannot.
     *
     * The stage's chevrons already own the arrow vocabulary on this page, and
     * they move the photographs. A second set of arrows meaning "leave this
     * destination" would be unreadable -- and it is the exact confusion the
     * stage already had once, when its chevrons navigated to a neighbouring
     * destination while looking like the ones that moved the fan.
     */
    #[Test]
    public function the_strip_names_the_places_on_either_side(): void
    {
        $dom = $this->dom(
            (string) $this->get(route('destinations.show', $this->places[1]))
                ->assertOk()
                ->getContent()
        );

        $strip = $dom->query('//nav[@aria-label="Browse destinations"]');

        $this->assertSame(
            1,
            $strip->length,
            'the place strip is missing from the destination page, so there is no '
            .'way off this page except back to the listing'
        );

        $html = $this->get(route('destinations.show', $this->places[1]))->getContent();

        $this->assertStringContainsString(
            route('destinations.show', $this->places[0]),
            $html,
            'the strip does not link to the previous place'
        );

        $this->assertStringContainsString(
            route('destinations.show', $this->places[2]),
            $html,
            'the strip does not link to the next place'
        );

        $this->assertStringContainsString(
            'Aklatan',
            $html,
            'the strip does not name the previous place'
        );

        $this->assertStringContainsString(
            'Caliraya',
            $html,
            'the strip does not name the next place'
        );

        $this->assertStringContainsString(
            '2 of 3',
            $html,
            'the strip does not say which place this is out of how many'
        );
    }

    /**
     * THE POSITION IS THE CATALOGUE ORDER, NOT THE LISTING'S `latest()`.
     *
     * `latest()` sorts on `created_at`, and a bulk CSV import writes many rows
     * carrying the same timestamp -- so "the next place" would be effectively
     * arbitrary and two requests in the same page load could disagree about it.
     */
    #[Test]
    public function the_position_follows_the_catalogue_order(): void
    {
        $html = (string) $this->get(route('destinations.show', $this->places[0]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '1 of 3',
            $html,
            'the first place in the catalogue is not reported as place 1'
        );

        $this->assertStringNotContainsString(
            route('destinations.show', $this->places[2]),
            $html,
            'the strip links BACKWARDS, so "next" is running on something other '
            .'than the catalogue order'
        );
    }

    /**
     * NOTHING WRAPS AROUND, AT EITHER END.
     *
     * A Next that quietly returned to the first place is a carousel's trick. On a
     * page whose job is to be somewhere, it would be a lie about there being
     * anywhere after this one.
     */
    #[Test]
    public function the_first_place_has_no_previous_and_the_last_has_no_next(): void
    {
        $first = (string) $this->get(route('destinations.show', $this->places[0]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'rel="prev"',
            $first,
            'the first place in the catalogue links backwards, so somewhere wraps '
            .'around to somewhere'
        );

        $this->assertStringContainsString(
            'rel="next"',
            $first,
            'the first place has no next link at all, so the catalogue cannot be '
            .'walked forward from the beginning'
        );

        $last = (string) $this->get(route('destinations.show', $this->places[2]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'rel="next"',
            $last,
            'the last place in the catalogue links forwards, so somewhere wraps '
            .'around to somewhere'
        );

        $this->assertStringContainsString(
            'rel="prev"',
            $last,
            'the last place has no previous link, so the catalogue cannot be '
            .'walked backward from the end'
        );
    }

    /**
     * AN ARCHIVED PLACE IS NEITHER A NEIGHBOUR NOR A QUICKHOP RESULT.
     *
     * `is_active = false` is the publish switch. An archived destination 404s on
     * its own page, so a strip linking to it or a quickhop landing on it would
     * be a dead end dressed as a working control.
     */
    #[Test]
    public function an_archived_place_is_neither_a_neighbour_nor_a_quickhop_result(): void
    {
        $archived = Destination::create([
            'name' => 'Deleted Del',
            'slug' => 'deleted-del',
            'description' => 'Gone.',
            'province' => 'Laguna',
            'municipality' => 'Real',
            'latitude' => 14.1,
            'longitude' => 121.5,
            'budget_level' => 'economy',
            'estimated_cost' => 450,
            'recommended_minutes' => 120,
            'is_active' => false,
            'is_featured' => true,
            'sort_order' => 3,
        ]);

        $html = (string) $this->get(route('destinations.show', $this->places[2]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            route('destinations.show', $archived),
            $html,
            'the strip links to an archived place, which 404s'
        );

        $this->assertStringContainsString(
            '3 of 3',
            $html,
            'an archived place is counted in the total, so the last place is '
            .'reported as though there were another one after it'
        );

        $this->get(route('destinations.quickhop', ['q' => 'Deleted Del']))
            ->assertRedirect(route('destinations.index', ['search' => 'Deleted Del']));
    }

    /**
     * QUICKHOP LANDS ON THE PLACE, AND IT IS A ROUTE OF ITS OWN.
     *
     * Declared BEFORE `/{destination:slug}`: after it, route-model binding
     * resolves "quickhop" as a slug, finds nothing, and 404s -- which reads as a
     * broken search box rather than as an ordering mistake.
     */
    #[Test]
    public function quickhop_lands_on_the_place_you_typed(): void
    {
        $this->get(route('destinations.quickhop', ['q' => 'Baler']))
            ->assertRedirect(route('destinations.show', $this->places[1]));

        $this->get(route('destinations.quickhop', ['q' => '  Caliraya  ']))
            ->assertRedirect(route('destinations.show', $this->places[2]));
    }

    /**
     * A PROVINCE OR MUNICIPALITY WORKS TOO, which is what makes it a hop
     * rather than a duplicate of the listing's name search.
     */
    #[Test]
    public function quickhop_accepts_a_province_or_a_municipality(): void
    {
        $this->get(route('destinations.quickhop', ['q' => 'Laguna']))
            ->assertRedirect(route('destinations.show', $this->places[0]));
    }

    /**
     * AN EXACT NAME BEATS A LOOSE CONTAINMENT.
     *
     * Otherwise "Caliraya" could land on whichever other place happened to
     * contain those eight letters first, which is a worse answer than none.
     */
    #[Test]
    public function an_exact_name_beats_a_loose_containment(): void
    {
        // Sorts after `Caliraya` alphabetically, so only the match quality can
        // put the right one first.
        Destination::create([
            'name' => 'Caliraya Lake',
            'slug' => 'caliraya-lake',
            'description' => 'Contains it.',
            'province' => 'Rizal',
            'municipality' => 'Tanay',
            'latitude' => 14.1,
            'longitude' => 121.5,
            'budget_level' => 'economy',
            'estimated_cost' => 450,
            'recommended_minutes' => 120,
            'is_active' => true,
            'sort_order' => 3,
        ]);

        $this->get(route('destinations.quickhop', ['q' => 'Caliraya']))
            ->assertRedirect(route('destinations.show', $this->places[2]));
    }

    /**
     * `%` IS ESCAPED, SO IT CANNOT MATCH ALL SIXTY-FIVE PLACES.
     *
     * A visitor who types a percent sign is not asking for the whole catalogue,
     * and an unescaped term would send them somewhere arbitrary -- which is a
     * worse answer than none.
     */
    #[Test]
    public function a_like_wildcard_is_escaped_rather_than_matched_everything(): void
    {
        $this->get(route('destinations.quickhop', ['q' => '%']))
            ->assertRedirect(route('destinations.index', ['search' => '%']));

        $this->get(route('destinations.quickhop', ['q' => 'Baler']))
            ->assertRedirect(route('destinations.show', $this->places[1]));
    }

    /**
     * NOTHING MATCHING GOES TO THE LISTING WITH THE SEARCH KEPT, not to a dead
     * end and not to an arbitrary place.
     */
    #[Test]
    public function an_unmatched_term_falls_back_to_the_filtered_listing(): void
    {
        $this->get(route('destinations.quickhop', ['q' => 'nowhere at all']))
            ->assertRedirect(route('destinations.index', ['search' => 'nowhere at all']));

        $this->get(route('destinations.quickhop', ['q' => '   ']))
            ->assertRedirect(route('destinations.index'));
    }

    /**
     * QUICKHOP NEEDS NO JAVASCRIPT, AND SUGGESTS WITHOUT A REQUEST.
     *
     * A plain GET form to its own route, and a native `<datalist>`. That is what
     * makes it work with scripting off, impossible to drift from the listing's
     * own search, and safe to land on a shared URL -- the browser ends up on the
     * place's own address rather than replaying a query.
     */
    #[Test]
    public function quickhop_is_a_form_with_native_suggestions_and_no_script(): void
    {
        $dom = $this->dom(
            (string) $this->get(route('destinations.show', $this->places[0]))
                ->assertOk()
                ->getContent()
        );

        $form = $dom->query(
            '//form[@action="'.route('destinations.quickhop').'"]'
        );

        $this->assertSame(
            1,
            $form->length,
            'the quickhop control is not a form pointing at its own route'
        );

        $this->assertSame('get', strtolower((string) $form->item(0)->getAttribute('method')));

        $this->assertSame(1, $dom->query('//input[@name="q"]')->length);

        $list = $dom->query('//datalist[@id="quickhop-places"]//option');

        $this->assertGreaterThan(
            0,
            $list->length,
            'the quickhop box offers no suggestions at all'
        );

        $this->assertStringContainsString(
            'Aklatan',
            (string) $form->item(0)->ownerDocument->saveHTML($form->item(0)),
            'the quickhop suggestions do not include the places'
        );
    }

    /**
     * THE STRIP IS NOT THE STAGE'S CAROUSEL, AND SAYS NOTHING ABOUT IT.
     *
     * The chevrons stay where they are and keep moving the fan. Nothing in the
     * strip may claim to control the photographs, because that is the one claim
     * a visitor would act on and be wrong about.
     */
    #[Test]
    public function the_strip_does_not_pretend_to_control_the_photographs(): void
    {
        $dom = $this->dom(
            (string) $this->get(route('destinations.show', $this->places[1]))
                ->assertOk()
                ->getContent()
        );

        $strip = $dom->query('//nav[@aria-label="Browse destinations"]')->item(0);

        $markup = (string) $strip->ownerDocument->saveHTML($strip);

        foreach (['Photograph', 'photograph', 'slide', 'Slide', 'carousel', 'Carousel'] as $word) {
            $this->assertStringNotContainsString(
                $word,
                $markup,
                'the place strip talks about '.strtolower($word).', so it reads as a '
                .'control for the carousel rather than for which destination you are on'
            );
        }

        $this->assertSame(
            0,
            $this->dom($markup)->query('//*[@data-stage-chevron]')->length,
            'the place strip renders carousel chevrons of its own'
        );
    }
}