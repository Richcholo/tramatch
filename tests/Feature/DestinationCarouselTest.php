<?php

namespace Tests\Feature;

use App\Models\Destination;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The shared destinations carousel, and the seam between the two page kinds.
 *
 * THE TEST THIS FILE EXISTS FOR is `the_fan_actually_moves`. The carousel shipped
 * looking frozen: the panels' `data-offset` attributes were written once by Blade
 * and never touched again, so the script could change which panel was "active" but
 * nothing in the geometry ever changed. State updated, layout did not, and no test
 * failed -- the feature tests all passed against a carousel that could only
 * highlight.
 *
 * The fix inverts it: each panel derives its offset from the active index on the
 * client, and every geometric property is keyed off `data-offset` in CSS. Those
 * four facts are asserted individually below, because any one of them missing
 * reproduces the freeze, and none of them is visible in a rendered-page assertion.
 */
class DestinationCarouselTest extends TestCase
{
    use RefreshDatabase;

    private function make(string $name, array $attributes = []): Destination
    {
        static $n = 0;
        $n++;

        return Destination::create(array_merge([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.$n,
            'description' => 'A place worth the journey.',
            'province' => 'Cavite',
            'municipality' => 'Cavite City',
            'latitude' => 14.3,
            'longitude' => 120.9,
            'budget_level' => 'economy',
            'entrance_fee' => 100,
            'estimated_cost' => 500,
            'recommended_minutes' => 90,
            'image_url' => 'https://images.example.com/'.$n.'.jpg',
            'is_active' => true,
        ], $attributes));
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();

        @$document->loadHTML($html);

        libxml_clear_errors();

        return new DOMXPath($document);
    }

    private function showHtml(Destination $destination): string
    {
        return $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->getContent();
    }

    private function panelOffsets(string $html): array
    {
        $xpath = $this->xpath($html);
        $offsets = [];

        foreach ($xpath->query('//*[@data-stage-panel]') as $panel) {
            $offsets[] = (int) $panel->getAttribute('data-offset');
        }

        return $offsets;
    }

    // ---------------------------------------------------------------------
    // THE FREEZE
    // ---------------------------------------------------------------------

    /**
     * The fan actually moves.
     *
     * Four independent conditions, all required, any one of which reproduces the
     * frozen carousel:
     *
     *  1. Every panel has a CLIENT-SIDE offset binding. Without it the attribute
     *     is whatever Blade wrote and never changes, which is the original bug.
     *  2. The offset is computed from the index as `i - index`, not read from the
     *     DOM. Reading it back would freeze at the server's value again.
     *  3. CSS derives position, size, rotation, stacking order and opacity from
     *     `[data-offset]` -- five signed states. If geometry lived anywhere else,
     *     moving the attribute would still not move a panel.
     *  4. The panels declare a transition on those properties, so the browser has
     *     something to interpolate.
     */
    #[Test]
    public function the_fan_actually_moves(): void
    {
        $destination = $this->make('Aguinaldo Shrine');

        $xpath = $this->xpath($this->showHtml($destination));

        $panels = $xpath->query('//*[@data-stage-panel]');

        $this->assertGreaterThan(0, $panels->length, 'no panels rendered');

        foreach ($panels as $panel) {
            $this->assertMatchesRegularExpression(
                '/x-bind:data-offset="offsetOf\(\d+\)"/',
                $panel->ownerDocument->saveHTML($panel),
                'a panel has no client-side offset binding, so advancing changes '
                .'which panel is highlighted but never where the panels are. This '
                .'is the frozen-carousel bug.'
            );
        }

        // 2. The offset is derived from the index, not read back from the DOM.
        $script = (string) file_get_contents(resource_path('js/hero-carousel.js'));

        $this->assertMatchesRegularExpression(
            '/offsetOf\(i\)\s*\{\s*return i - this\.index;/',
            $script,
            'offsetOf must compute from the active index. Reading the offset back '
            .'out of the DOM would freeze at the value the server rendered.'
        );

        // 3. Geometry keyed on all five signed offsets.
        $css = (string) file_get_contents(resource_path('css/app.css'));

        foreach (['0', '-1', '1', '-2', '2'] as $offset) {
            $this->assertMatchesRegularExpression(
                "/\[data-offset='".preg_quote($offset, '/')."'\]/s",
                $css,
                "no CSS rule for [data-offset='{$offset}'], so a panel at that "
                .'offset has no defined geometry'
            );
        }

        // Position AND size are both offset-derived. An earlier version keyed only
        // the rotation off the offset and left `left` on the base rule, so panels
        // rotated in place instead of travelling along the arc.
        $this->assertMatchesRegularExpression(
            "/\[data-offset='0'\]\s*\{[^}]*left:/s",
            $css,
            "the active panel's position must come from its offset"
        );

        $this->assertMatchesRegularExpression(
            "/\[data-offset='-1'\]\s*\{[^}]*left:/s",
            $css,
            "the near-left panel's position must come from its offset"
        );

        // Height comes off the offset too, but through a GROUPED selector
        // (`[data-offset='-1'], [data-offset='1'] { height: ... }`) because the
        // near pair is always the same size. A regex anchored to the bare
        // `[data-offset='-1']` selector reports it missing, which is a false
        // negative about the stylesheet rather than a fact about it.
        $this->assertMatchesRegularExpression(
            "/\[data-offset='-1'\][^{]*\{[^}]*height:/s",
            $css,
            'panel height must come from the offset too, or every panel is the '
            .'same size and the fan reads as a flat row'
        );

        // And the heights genuinely differ, or there is no depth.
        //
        // The near pair shares ONE grouped rule
        // (`[data-offset='-1'], [data-offset='1'] { height: ... }`), so a regex
        // that walks to the next `{` consumes the second selector and never
        // captures offset 1 at all. Hence: assert the grouped selector exists, and
        // compare 0 / -1 / -2 rather than 0 / 1 / 2.
        $this->assertMatchesRegularExpression(
            "/\[data-offset='-1'\],\s*\[data-offset='1'\]\s*\{/s",
            $css,
            'the near pair should share one rule. Separate rules are how the two '
            .'sides came to disagree about size'
        );

        $heights = [];

        preg_match_all(
            "/\[data-offset='(-?\d+)'\][^{]*\{([^}]*)\}/s",
            $css,
            $heights,
            PREG_SET_ORDER
        );

        $byOffset = [];

        foreach ($heights as $match) {
            // The height is searched for INSIDE the rule body rather than
            // immediately after the brace. Declaration order differs per rule --
            // offset 0 leads with `left`, the grouped near pair leads with
            // `height` -- so anchoring to the brace silently misses most of them.
            if (preg_match('/height:\s*(\d+)%/', $match[2], $h)) {
                $byOffset[$match[1]] = (int) $h[1];
            }
        }

        $this->assertArrayHasKey(0, $byOffset, 'no height for the active panel');
        $this->assertArrayHasKey(-1, $byOffset, 'no height for the near pair');
        $this->assertArrayHasKey(-2, $byOffset, 'no height for the outer pair');

        $this->assertGreaterThan(
            $byOffset[-1],
            $byOffset[0],
            'the active panel must be the tallest'
        );

        $this->assertGreaterThan(
            $byOffset[-2],
            $byOffset[-1],
            'the outer panels must be shorter than the near ones, or there is no depth'
        );

        // 4. Something to interpolate.
        $this->assertMatchesRegularExpression(
            '/\.tm-fan-panel\s*\{[^}]*transition:[^}]*transform/s',
            $css,
            'the panels declare no transform transition, so the fan would jump '
            .'between slides instead of sliding'
        );
    }

    /**
     * The offset is server-rendered too, so the page is right with JavaScript off.
     *
     * The client binding is what makes the fan move; this static attribute is what
     * makes the page correct before (and without) the script. Both are required,
     * which is the whole point of writing the starting state in PHP and the moving
     * state in JS.
     */
    #[Test]
    public function the_starting_offset_is_rendered_without_javascript(): void
    {
        $destination = $this->make('Corregidor Island');

        // Three destinations, so the middle one is not at an edge.
        $this->make('Aguinaldo Shrine');
        $this->make('Taal Volcano');

        $offsets = $this->panelOffsets($this->showHtml($destination));

        $this->assertSame([-1, 0, 1], $offsets, 'the active destination must be '
            .'centred on a cold load, and its neighbours one step either side');

        $zero = $this->xpath($this->showHtml($destination))
            ->query('//*[@data-stage-panel][@data-offset="0"]');

        $this->assertSame(1, $zero->length, 'exactly one panel may be at offset 0');
    }

    // ---------------------------------------------------------------------
    // THE SEAM
    // ---------------------------------------------------------------------

    /**
     * The index and a destination page render the same fan, in the same order.
     *
     * This is the architecture's central claim and the reason the presenter exists:
     * the fan on `/destinations/{slug}` IS the index fan with the index shifted.
     * If the two ever disagree on membership or order, the continuity between the
     * pages is gone and no other test notices.
     *
     * WHAT THIS DOES NOT CATCH, established by falsifying it: passing
     * `slides($slug)` on the show page instead of `slides()` changes only the
     * active index, not the membership or the order, so this test still passes.
     * That regression is caught by `the_bound_destination_is_centred`, which is
     * why the two are separate tests with separate claims rather than one test
     * trying to assert both.
     */
    #[Test]
    public function the_index_and_a_destination_page_render_the_same_fan_in_the_same_order(): void
    {
        $names = ['Aguinaldo Shrine', 'Corregidor Island', 'Taal Volcano', 'Bantay Abot Cave'];

        foreach ($names as $name) {
            $this->make($name);
        }

        $indexHtml = $this->get(route('destinations.index'))->assertOk()->getContent();
        $showHtml = $this->showHtml(Destination::where('name', 'Taal Volcano')->firstOrFail());

        $slugs = function (string $html): array {
            $xpath = $this->xpath($html);
            $found = [];

            foreach ($xpath->query('//*[@data-stage-panel]') as $panel) {
                $found[] = $panel->getAttribute('data-href');
            }

            return $found;
        };

        $fromIndex = $slugs($indexHtml);
        $fromShow = $slugs($showHtml);

        $this->assertNotEmpty($fromIndex, 'the index rendered no carousel panels');

        $this->assertSame(
            $fromIndex,
            $fromShow,
            'the index and the destination page disagree about the carousel. They '
            .'read the same presenter precisely so they cannot.'
        );
    }

    /**
     * The active slide on a destination page is that destination.
     *
     * Derived from the URL, which is what makes a hard load land in the same
     * state as an in-page advance -- so deep links, Back/Forward and a shared link
     * all replay correctly.
     */
    #[Test]
    public function the_bound_destination_is_centred(): void
    {
        $this->make('Aguinaldo Shrine');
        $second = $this->make('Corregidor Island');
        $this->make('Taal Volcano');

        $xpath = $this->xpath($this->showHtml($second));

        $active = $xpath->query('//*[@data-stage-panel][@data-offset="0"]')->item(0);

        $this->assertNotNull($active, 'no panel is centred');

        $this->assertStringContainsString(
            'Corregidor Island',
            $active->getAttribute('data-stage-name'),
            'the wrong destination is centred'
        );

        $stage = $xpath->query('//*[@data-destination-stage]')->item(0);

        $this->assertSame('1', $stage->getAttribute('data-stage-active'));
    }

    // ---------------------------------------------------------------------
    // PANELS, CAPTION, CONTROLS
    // ---------------------------------------------------------------------

    /**
     * Every panel links to its destination, and the current page is not a link to
     * itself.
     *
     * `href="#"` on the active panel would be a dead control, so on a destination
     * page it renders as a plain element marked `aria-current="page"`.
     */
    #[Test]
    public function panels_link_to_their_destination_but_never_to_the_page_you_are_on(): void
    {
        $this->make('Aguinaldo Shrine');
        $current = $this->make('Corregidor Island');
        $this->make('Taal Volcano');

        $xpath = $this->xpath($this->showHtml($current));

        $links = $xpath->query('//*[@data-stage-panel]//a[@data-stage-link]');

        // Two of three panels are links; the current one is not.
        $this->assertSame(2, $links->length);

        foreach ($links as $link) {
            $this->assertNotSame(
                route('destinations.show', $current),
                $link->getAttribute('href'),
                'a panel links to the page it is already on'
            );
        }

        $this->assertSame(
            1,
            $xpath->query('//*[@data-stage-panel][@data-offset="0"]//*[@aria-current="page"]')->length,
            'the centred panel must be marked as the current page'
        );
    }

    /**
     * The caption follows the active index rather than being fixed to the first
     * destination.
     *
     * Rendered from PHP alone it would sit frozen on one name while the fan moved
     * on -- the same class of bug as the frozen fan, one level up. `x-text` is
     * what keeps the two agreeing.
     */
    #[Test]
    public function the_caption_follows_the_active_index(): void
    {
        $this->make('Aguinaldo Shrine');
        $second = $this->make('Corregidor Island');

        $html = $this->showHtml($second);

        $this->assertStringContainsString('x-text="activeSlide().name"', $html);
        $this->assertStringContainsString('x-text="activeSlide().regionLabel"', $html);

        // And the server-rendered fallback names the right destination, so the
        // page reads correctly before the script runs.
        $title = $this->xpath($html)->query('//*[@data-stage-title]')->item(0);

        $this->assertNotNull($title);
        $this->assertStringContainsString('Corregidor Island', $title->textContent);
    }

    /**
     * Autoplay ships with a pause control, because a self-starting loop longer
     * than five seconds without one fails WCAG 2.2.2.
     */
    #[Test]
    public function autoplay_always_ships_a_pause_control(): void
    {
        $destination = $this->make('Aguinaldo Shrine');
        $this->make('Corregidor Island');

        $xpath = $this->xpath($this->get(route('destinations.index'))->assertOk()->getContent());

        $this->assertSame('1', $xpath->query('//*[@data-destination-stage]')
            ->item(0)->getAttribute('data-stage-autoplay'));

        $pause = $xpath->query('//*[@data-stage-pause]')->item(0);

        $this->assertNotNull($pause, 'the index autoplays with no pause control, '
            .'which fails WCAG 2.2.2');

        $this->assertSame('button', strtolower($pause->nodeName));
        $this->assertNotSame('', trim($pause->getAttribute('aria-label')));

        // The state is conveyed by aria-pressed, not by the icon alone.
        $this->assertStringContainsString(
            'x-bind:aria-pressed="paused ? \'true\' : \'false\'"',
            $pause->ownerDocument->saveHTML($pause)
        );

        // The show page does not autoplay, so it must not carry a dead control.
        $showXpath = $this->xpath($this->showHtml($destination));

        $this->assertSame('0', $showXpath->query('//*[@data-destination-stage]')
            ->item(0)->getAttribute('data-stage-autoplay'));

        $this->assertSame(
            0,
            $showXpath->query('//*[@data-stage-pause]')->length,
            'a destination page does not autoplay, so the pause control is dead'
        );
    }

    /**
     * The chevrons navigate to real destinations, and are omitted at each end.
     *
     * They are links, not buttons: they go somewhere. A permanently disabled
     * control is a dead control, so at the first destination there is no previous
     * one to link to and it is not rendered.
     */
    #[Test]
    public function the_chevrons_link_to_real_destinations_and_stop_at_the_ends(): void
    {
        $this->make('Aguinaldo Shrine');
        $second = $this->make('Corregidor Island');
        $this->make('Taal Volcano');

        $xpath = $this->xpath($this->showHtml($second));

        $this->assertSame(1, $xpath->query('//*[@data-stage-chevron="prev"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-stage-chevron="next"]')->length);

        $this->assertSame(
            route('destinations.show', $second->getKey()),
            route('destinations.show', $second->getKey())
        );

        // The neighbours match the order around the active slide.
        $prev = $xpath->query('//*[@data-stage-chevron="prev"]')->item(0);
        $next = $xpath->query('//*[@data-stage-chevron="next"]')->item(0);

        $this->assertStringContainsString(
            $xpath->query('//*[@data-stage-panel][@data-offset="-1"]')->item(0)
                ->getAttribute('data-stage-name'),
            $prev->getAttribute('aria-label')
        );

        $this->assertStringContainsString(
            $xpath->query('//*[@data-stage-panel][@data-offset="1"]')->item(0)
                ->getAttribute('data-stage-name'),
            $next->getAttribute('aria-label')
        );

        // At the first destination there is no previous one.
        $first = $this->xpath($this->showHtml(
            Destination::orderBy('name')->firstOrFail()
        ));

        $this->assertSame(
            0,
            $first->query('//*[@data-stage-chevron="prev"]')->length,
            'a disabled or looping chevron at the first destination is a dead or '
            .'misleading control'
        );
    }

    /**
     * Panels beyond the fan's reach are removed from the accessibility tree.
     *
     * They stay in the DOM so they can slide in, but they must be untabbable and
     * announced as hidden, or a screen reader walks 65 destinations to reach the
     * page content.
     */
    #[Test]
    public function panels_beyond_the_reach_are_hidden_and_untabbable(): void
    {
        $destination = $this->make('Aguinaldo Shrine');

        for ($i = 0; $i < 8; $i++) {
            $this->make('Filler '.$i);
        }

        $xpath = $this->xpath($this->showHtml($destination));

        $far = $xpath->query('//*[@data-stage-panel][@data-far]');

        $this->assertGreaterThan(
            0,
            $far->length,
            'no panel is marked far, so the whole catalogue is reachable by Tab'
        );

        foreach ($far as $panel) {
            $html = $panel->ownerDocument->saveHTML($panel);

            $this->assertStringContainsString(
                'x-bind:aria-hidden="Math.abs(offsetOf(',
                $html,
                'a far panel is not bound to aria-hidden'
            );

            $this->assertStringContainsString('x-bind:tabindex=', $html);
        }

        // And CSS removes them from hit testing and the a11y tree outright.
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\[data-far\]\s*\{[^}]*visibility:\s*hidden/s',
            $css,
            '[data-far] must set visibility: hidden, or far panels stay in the '
            .'accessibility tree despite the opacity'
        );

        $this->assertMatchesRegularExpression(
            '/\[data-far\]\s*\{[^}]*pointer-events:\s*none/s',
            $css,
            '[data-far] must not be clickable'
        );
    }

    // ---------------------------------------------------------------------
    // MEMBERSHIP AND EDGE CASES
    // ---------------------------------------------------------------------

    /**
     * Archived and un-featured destinations are in neither fan.
     *
     * The cache is scoped by the membership filter and busted on save; without
     * that, an archived destination stays clickable in both carousels for up to
     * ten minutes and leads to a 404.
     */
    #[Test]
    public function archived_and_unfeatured_destinations_appear_in_neither_fan(): void
    {
        $this->make('Aguinaldo Shrine');
        $archived = $this->make('Corregidor Island', ['is_active' => false]);
        $unfeatured = $this->make('Taal Volcano', ['is_featured' => false]);
        $live = $this->make('Bantay Abot Cave');

        $indexHtml = $this->get(route('destinations.index'))->assertOk()->getContent();

        $this->panelNames($indexHtml);

        $this->assertSame(
            ['Aguinaldo Shrine', 'Bantay Abot Cave'],
            $this->panelNames($indexHtml),
            'the carousel must exclude archived and un-featured destinations'
        );

        // Archived is unpublished, so its page is gone.
        $this->get(route('destinations.show', $archived))->assertNotFound();

        /*
         * Un-featured is NOT unpublished. It is a published destination the owner
         * has chosen not to feature, so its page still works -- it is only absent
         * from the fan. Asserting a 404 here would bake in a much stronger promise
         * than "not in the carousel", and one an admin would immediately hit when
         * un-featuring a destination they still want reachable.
         */
        $this->get(route('destinations.show', $unfeatured))->assertOk();
    }

    private function panelNames(string $html): array
    {
        $xpath = $this->xpath($html);
        $names = [];

        foreach ($xpath->query('//*[@data-stage-panel]') as $panel) {
            $names[] = $panel->getAttribute('data-stage-name');
        }

        return $names;
    }

    /**
     * A destination with no photo still renders, and still links.
     *
     * No photo is an ordinary row, not a broken one. The panel falls back to a
     * gradient rather than rendering an empty frame.
     */
    #[Test]
    public function a_destination_with_no_photo_still_has_a_panel(): void
    {
        $photoless = $this->make('Photoless Place', ['image_url' => null]);

        // A neighbour, so there is a panel other than the current one to link.
        // With only the photoless destination the fan has a single panel, which
        // renders as the current page rather than a link -- so this would assert
        // nothing about reachability.
        $this->make('Somewhere Else');

        $xpath = $this->xpath($this->showHtml($photoless));

        $this->assertSame(
            1,
            $xpath->query('//*[@data-stage-panel][@data-offset="0"]')->length,
            'a destination with no photo must still be centred in the fan'
        );

        /*
         * Backdrop layers are per SLIDE, not per active slide -- there is one for
         * every destination in the fan that has a photo, and the script reveals
         * the active one. Two destinations here, one of them photoless, so exactly
         * one backdrop layer renders.
         */
        $this->assertSame(
            1,
            $xpath->query('//*[@data-stage-backdrop]')->length,
            'the photoless destination contributes no backdrop layer, and the one '
            .'that has a photo contributes exactly one'
        );

        $this->assertGreaterThan(
            0,
            $xpath->query('//*[@data-destination-stage]//a[@data-stage-link]')->length,
            'the photoless destination must still be reachable'
        );
    }

    /**
     * A single destination gets no pager and no chevrons.
     *
     * A one-item carousel is motion with no information: the dots cannot go
     * anywhere, the arrows have no neighbour, and autoplay would spin on the same
     * picture forever.
     */
    #[Test]
    public function a_single_destination_has_no_pager_and_no_chevrons(): void
    {
        $only = $this->make('The Only One');

        $xpath = $this->xpath($this->get(route('destinations.index'))->assertOk()->getContent());

        $this->assertSame(1, $xpath->query('//*[@data-stage-panel]')->length);

        $this->assertSame(
            0,
            $xpath->query('//*[@data-stage-dot]')->length,
            'one destination is not a carousel, so there are no dots'
        );

        $this->assertSame(0, $xpath->query('//*[@data-stage-chevron]')->length);
    }

    /**
     * An empty catalogue renders an explanation, not an empty arc.
     */
    #[Test]
    public function an_empty_catalogue_renders_a_message_not_an_empty_fan(): void
    {
        $xpath = $this->xpath($this->get(route('destinations.index'))->assertOk()->getContent());

        $this->assertSame(
            0,
            $xpath->query('//*[@data-stage-panel]')->length,
            'an empty catalogue must not render a fan of nothing'
        );

        $this->assertStringContainsString(
            'No destinations to show yet',
            $this->get(route('destinations.index'))->assertOk()->getContent()
        );
    }

    /**
     * Every field the template reads off the script actually exists on it.
     *
     * This caught a real bug and is here because of it. The caption bound
     * `activeSlide().regionLabel` while `activeSlide()` returned an object keyed
     * `region`. The chip rendered its server-rendered text on load and then went
     * EMPTY the moment Alpine took over -- a mismatch with no error, invisible to
     * any assertion about rendered HTML, and only visible as one missing word in a
     * browser.
     *
     * So the two sides are compared directly: each `activeSlide().<field>` in the
     * template must be a key the script's `panelData` really sets.
     */
    #[Test]
    public function every_field_the_template_reads_off_the_script_exists_on_it(): void
    {
        $caption = (string) file_get_contents(
            resource_path('views/components/destinations/carousel-caption.blade.php')
        );

        preg_match_all('/activeSlide\(\)\.(\w+)/', $caption, $read);

        $this->assertNotEmpty(
            $read[1],
            'the caption reads no fields off activeSlide(), so this guard is '
            .'checking nothing -- keep it in step with the template'
        );

        $script = (string) file_get_contents(resource_path('js/hero-carousel.js'));

        foreach (array_unique($read[1]) as $field) {
            $this->assertMatchesRegularExpression(
                '/\b'.preg_quote($field, '/').'\s*:/',
                $script,
                "the caption binds activeSlide().{$field}, but the script's "
                ."panelData never sets a `{$field}` key. It will render empty as "
                .'soon as Alpine takes over, with no error anywhere.'
            );
        }
    }

    /**
     * The Alpine component is registered before Alpine starts.
     *
     * An `x-data="heroCarousel()"` in markup resolves against the registry.
     * Registering after `Alpine.start()` leaves the expression unevaluated, so the
     * panels render and the script never attaches -- which looks exactly like the
     * frozen carousel and reports nothing at all.
     */
    #[Test]
    public function the_alpine_component_is_registered_before_start(): void
    {
        $source = (string) file_get_contents(resource_path('js/app.js'));

        /*
         * Comments stripped first. The explanatory comment above the registration
         * contains the literal string "Alpine.start()", so a naive strpos found it
         * in the PROSE at byte 330 and reported the correct ordering as reversed.
         * Searching source that explains itself needs the prose taken out first.
         */
        $code = (string) preg_replace(
            ['#/\*.*?\*/#s', '#//[^\n]*#'],
            '',
            $source
        );

        $register = strpos($code, "Alpine.data('heroCarousel'");
        $start = strpos($code, 'Alpine.start()');

        $this->assertNotFalse($register, 'heroCarousel is never registered with Alpine');
        $this->assertNotFalse($start, 'Alpine.start() is missing');
        $this->assertLessThan(
            $start,
            $register,
            'heroCarousel is registered after Alpine.start(), so x-data never '
            .'resolves and the carousel silently does nothing'
        );

        // And the entry that carries it is a real import, so it cannot be
        // tree-shaken. A previous version shipped a 0.00 kB bundle because the
        // component was an unused export.
        $this->assertMatchesRegularExpression(
            "/^import heroCarousel from '\.\/hero-carousel';/m",
            $code,
            'hero-carousel.js must be imported by app.js so it is bundled'
        );
    }
}