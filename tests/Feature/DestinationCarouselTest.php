<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The destinations fan on a destination page, and the page it sits on.
 *
 * SCOPE, because it moved: the fan is on `/destinations/{slug}` ONLY.
 * `/destinations` is a search-and-grid listing again and has no carousel, so
 * there is no longer a two-page seam to keep in step. `DestinationCarousel` still
 * supplies the order, which is what keeps the fan deterministic and the chevrons
 * pointing at the slides the fan actually shows.
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

    private function indexHtml(): string
    {
        return $this->get(route('destinations.index'))->assertOk()->getContent();
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

        $this->assertMatchesRegularExpression(
            "/\[data-offset='-1'\],\s*\[data-offset='1'\]\s*\{/s",
            $css,
            'the near pair should share one rule. Separate rules are how the two '
            .'sides came to disagree about size'
        );

        // And the three heights genuinely differ, or there is no depth. The height
        // is searched for INSIDE the rule body rather than immediately after the
        // brace: declaration order differs per rule, and anchoring to the brace
        // silently misses most of them.
        $heights = [];

        preg_match_all(
            "/\[data-offset='(-?\d+)'\][^{]*\{([^}]*)\}/s",
            $css,
            $heights,
            PREG_SET_ORDER
        );

        $byOffset = [];

        foreach ($heights as $match) {
            if (preg_match('/height:\s*(\d+)%/', $match[2], $h)) {
                $byOffset[$match[1]] = (int) $h[1];
            }
        }

        $this->assertArrayHasKey(0, $byOffset, 'no height for the active panel');
        $this->assertArrayHasKey(-1, $byOffset, 'no height for the near pair');
        $this->assertArrayHasKey(-2, $byOffset, 'no height for the outer pair');

        $this->assertGreaterThan($byOffset[-1], $byOffset[0]);
        $this->assertGreaterThan($byOffset[-2], $byOffset[-1]);

        // 4. Something to interpolate.
        $this->assertMatchesRegularExpression(
            '/\.tm-fan-panel\s*\{[^}]*transition:[^}]*transform/s',
            $css,
            'the panels declare no transform transition, so the fan would jump '
            .'between slides instead of sliding'
        );
    }

    /**
     * The starting offset is server-rendered too, so the page is right with JS off.
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

        $this->make('Aguinaldo Shrine');
        $this->make('Taal Volcano');

        $offsets = [];

        foreach ($this->xpath($this->showHtml($destination))
            ->query('//*[@data-stage-panel]') as $panel) {
            $offsets[] = (int) $panel->getAttribute('data-offset');
        }

        $this->assertSame(
            [-1, 0, 1],
            $offsets,
            'the active destination must be centred on a cold load, and its '
            .'neighbours one step either side'
        );

        $this->assertSame(
            1,
            $this->xpath($this->showHtml($destination))
                ->query('//*[@data-stage-panel][@data-offset="0"]')->length,
            'exactly one panel may be at offset 0'
        );
    }

    /**
     * The fan is on the destination page and NOT on the index.
     *
     * `/destinations` was reverted to its search-and-grid listing. This is pinned
     * in both directions because the obvious way to "share the carousel" later is
     * to re-add the include to the index, and the only thing that would notice is
     * this.
     */
    #[Test]
    public function the_fan_is_on_the_destination_page_only(): void
    {
        $destination = $this->make('Aguinaldo Shrine');
        $this->make('Corregidor Island');

        $this->assertGreaterThan(
            0,
            $this->xpath($this->showHtml($destination))->query('//*[@data-stage-panel]')->length,
            'the destination page must carry the fan'
        );

        $index = $this->xpath($this->indexHtml());

        $this->assertSame(
            0,
            $index->query('//*[@data-destination-stage]')->length,
            '/destinations is a search-and-grid listing again and must not carry '
            .'the carousel'
        );

        $this->assertSame(0, $index->query('//*[@data-stage-panel]')->length);
    }

    /**
     * The active slide on a destination page is that destination.
     *
     * Derived from the URL, which is what makes a hard load land in the same state
     * as an in-page advance -- so deep links, Back/Forward and a shared link all
     * replay correctly.
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

        $this->assertSame(
            '1',
            $xpath->query('//*[@data-destination-stage]')->item(0)
                ->getAttribute('data-stage-active')
        );
    }

    // ---------------------------------------------------------------------
    // THE PAGE
    // ---------------------------------------------------------------------

    /**
     * The destination's photo is a blurred, whole-page backdrop.
     *
     * The layer is `fixed` rather than absolute so it stays put as the reader
     * scrolls; an absolutely-positioned one scrolls away and leaves the lower half
     * of the page on flat teal. And it is hidden from assistive tech, because it
     * is decorative -- the same photograph is described by the panel alt and the
     * caption, and announcing it three times helps nobody.
     */
    #[Test]
    public function the_photo_is_a_blurred_whole_page_backdrop(): void
    {
        $destination = $this->make('Aguinaldo Shrine', [
            'image_url' => 'https://images.example.com/hero.jpg',
        ]);

        $xpath = $this->xpath($this->showHtml($destination));

        $layer = null;

        foreach ($xpath->query('//img[contains(@src, "hero.jpg")]') as $img) {
            // The BACKDROP, not the fan panel. The image is `absolute` inside a
            // `fixed` wrapper, so the WRAPPER is what is checked -- looking for
            // "fixed" on the img's own class finds nothing and reports a page
            // background that is plainly there.
            $parent = $img->parentNode;

            if ($parent instanceof \DOMElement
                && str_contains($parent->getAttribute('class'), 'fixed')) {
                $layer = ['img' => $img, 'wrapper' => $parent];
            }
        }

        $this->assertNotNull(
            $layer,
            'the main photo is not behind the page. It must be the page background, '
            .'not a band at the top.'
        );

        $classes = $layer['img']->getAttribute('class');

        $this->assertStringContainsString('blur-', $classes, 'the backdrop must be blurred');
        $this->assertStringContainsString('scale-', $classes, 'the backdrop must be oversized');
        $this->assertStringContainsString(
            'object-cover',
            $classes,
            'the backdrop must cover, or its aspect ratio fights the page'
        );

        $this->assertSame('', trim($layer['img']->getAttribute('alt')), 'the backdrop is decorative');

        $wrapper = $layer['wrapper']->getAttribute('class');

        $this->assertStringContainsString(
            'fixed',
            $wrapper,
            'the backdrop must be fixed so it stays put as the reader scrolls'
        );
        $this->assertStringContainsString('inset-0', $wrapper, 'it must cover the viewport');
        $this->assertStringContainsString(
            'pointer-events-none',
            $wrapper,
            'the backdrop must not take clicks meant for the page'
        );
    }

    /**
     * A destination with no photo still gets a backdrop, not a bare void.
     */
    #[Test]
    public function a_destination_with_no_photo_still_gets_a_backdrop(): void
    {
        $destination = $this->make('Photoless Place', ['image_url' => null]);

        $this->make('Somewhere Else');

        $html = $this->showHtml($destination);

        $this->assertStringNotContainsString('hero.jpg', $html);
        $this->assertMatchesRegularExpression(
            '/fixed inset-0[^"]*bg-volcanic-teal/',
            $html,
            'a photoless destination still needs a full-page ground, or the lower '
            .'half of the page is body-background'
        );

        $this->assertMatchesRegularExpression(
            '/from-boracay via-cyan-500 to-volcanic-teal/',
            $html,
            'the fallback should be the brand gradient, not a flat panel'
        );
    }

    /**
     * Every fact the previous layout showed is still on the page.
     *
     * The redesign was allowed to change the arrangement and the palette, not the
     * information. This is the guard on that promise, and it is deliberately
     * written as a list of the things that used to be here so that dropping one
     * during a future restyle fails a test rather than quietly removing a feature.
     */
    #[Test]
    public function every_fact_the_previous_layout_showed_is_still_here(): void
    {
        $destination = $this->make('Aguinaldo Shrine', [
            'description' => 'A museum of Philippine history.',
            'entrance_fee' => 150,
            'estimated_cost' => 900,
            'recommended_minutes' => 120,
            'budget_level' => 'mid-range',
            'hours_source_url' => 'https://example.gov.ph/hours',
            'hours_source_label' => 'NHCP',
            'hours_note' => 'Last entry one hour before closing.',
        ]);

        $html = $this->showHtml($destination);

        // Name, place, prose.
        $this->assertStringContainsString('Aguinaldo Shrine', $html);
        $this->assertStringContainsString('Cavite City', $html);
        $this->assertStringContainsString('A museum of Philippine history.', $html);

        // The three numbers.
        $this->assertStringContainsString('₱150.00', $html);
        $this->assertStringContainsString('₱900.00', $html);
        $this->assertStringContainsString('120 min', $html);

        // Hours, in full.
        $this->assertStringContainsString('Philippine time (UTC+8)', $html);
        $this->assertStringContainsString('NHCP', $html);
        $this->assertStringContainsString(
            'https://example.gov.ph/hours',
            $html,
            'the cited hours source must survive: a traveller has to be able to '
            .'check these hours'
        );
        $this->assertStringContainsString('Last entry one hour before closing.', $html);

        // Map, and the travel-fit CTA.
        $this->assertStringContainsString('data-destination-map', $html);
        $this->assertStringContainsString('Find similar places', $html);
        $this->assertStringContainsString('Mid-range', $html);

        // Reviews, including the form. The form only renders for a signed-in
        // visitor -- guests get "Log in to leave a review" instead -- so this is
        // checked separately rather than asserted on the guest page.
        $this->assertStringContainsString('Traveler notes.', $html);
        $this->assertStringContainsString('Log in to leave a review.', $html);

        $this->actingAs(User::factory()->create())
            ->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertSee(route('reviews.store', $destination), escape: false);

        // The photographs an admin uploaded.
        $destination->images()->create(['path' => 'https://images.example.com/x.jpg']);

        $this->assertStringContainsString(
            'https://images.example.com/x.jpg',
            $this->showHtml($destination->fresh()),
            'the per-destination uploads must still be shown'
        );

        // A way back.
        $this->assertStringContainsString(route('destinations.index'), $html);
    }

    /**
     * There is exactly one `h1`, it is the destination's name, and it is static.
     *
     * The heading is fed from `$destination`, never from a slide. Two failures it
     * prevents:
     *
     *  - It used to follow the fan's active index, which put a different
     *    destination's name above prose, hours, fees and a map that were all still
     *    about the first one, and moved the document heading out from under the
     *    reader.
     *  - Because it lived inside the carousel section, a destination with no
     *    featured destinations skipped the section and rendered the page with NO
     *    `h1` AT ALL. That is covered separately by
     *    `an_empty_fan_renders_the_page_without_an_empty_arc`.
     */
    #[Test]
    public function there_is_one_static_h1_and_it_is_the_destination_name(): void
    {
        $this->make('Aguinaldo Shrine');
        $second = $this->make('Corregidor Island');
        $this->make('Taal Volcano');

        $html = $this->showHtml($second);

        $xpath = $this->xpath($html);

        $this->assertSame(
            1,
            $xpath->query('//h1')->length,
            'the destination page must have exactly one h1'
        );

        $this->assertStringContainsString(
            'Corregidor Island',
            $xpath->query('//h1')->item(0)->textContent
        );

        // And it is not bound to the fan's index. Scoped to the HEADING, not the
        // page: the live region and the dots legitimately carry `x-text`, so
        // asserting the whole page has none would fail for the right-looking
        // reason.
        $this->assertStringNotContainsString(
            'x-text',
            $xpath->query('//h1')->item(0)->ownerDocument->saveHTML(
                $xpath->query('//h1')->item(0)
            ),
            "the heading is bound to the active index. It is this page's h1 and the "
            .'name of the place the prose below is about -- advancing the fan must '
            .'not change it.'
        );
    }

    // ---------------------------------------------------------------------
    // PANELS AND CONTROLS
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

        $this->assertSame(2, $links->length, 'two of three panels are links');

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
     * There is no autoplay and no pause control, anywhere.
     *
     * The fan moved from the index to the destination page alone, and this is
     * somewhere someone has arrived: the copy beneath it is all about one place,
     * and a timer that keeps shifting the fan under someone reading it is worse
     * than no timer. With no autoplay there is nothing to pause, so the pause
     * control went too -- and a control that can never do anything is worse than
     * no control at all. WCAG 2.2.2 only applies to content that starts moving by
     * itself.
     */
    #[Test]
    public function there_is_no_autoplay_and_no_pause_control(): void
    {
        $destination = $this->make('Aguinaldo Shrine');
        $this->make('Corregidor Island');

        $html = $this->showHtml($destination);

        $this->assertStringNotContainsString('data-stage-pause', $html);
        $this->assertStringNotContainsString('data-stage-autoplay', $html);
        $this->assertStringNotContainsString('data-stage-interval', $html);

        $script = (string) file_get_contents(resource_path('js/hero-carousel.js'));

        $this->assertStringNotContainsString(
            'setInterval',
            $script,
            'a timer survived the removal of autoplay. Nothing on this page should '
            .'move by itself.'
        );

        $this->assertStringNotContainsString('setTimeout(() => {\n                this.next()', $script);
    }

    /**
     * The chevrons navigate to real destinations, and are omitted at each end.
     *
     * They are links, not buttons: they go somewhere. That also means they work
     * with JavaScript disabled, are middle-clickable and openable in a new tab, and
     * the browser shows the destination in the status bar -- none of which is true
     * of a button.
     *
     * Omitted at each end rather than disabled, since there is no wrap-around and a
     * permanently disabled control is a dead control.
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

        $prev = $xpath->query('//*[@data-stage-chevron="prev"]')->item(0);
        $next = $xpath->query('//*[@data-stage-chevron="next"]')->item(0);

        $this->assertSame('a', strtolower($prev->nodeName), 'a chevron must be a link');
        $this->assertSame('prev', $prev->getAttribute('rel'));
        $this->assertSame('next', $next->getAttribute('rel'));

        // The neighbours match the order around the active slide.
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
     * announced as hidden, or a screen reader walks the whole catalogue to reach
     * the page content.
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

            $this->assertStringContainsString('x-bind:aria-hidden="Math.abs(offsetOf(', $html);
            $this->assertStringContainsString('x-bind:tabindex=', $html);
        }

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
     * Archived and un-featured destinations are not in the fan.
     *
     * The cache is scoped by the membership filter and busted on save; without
     * that, an archived destination stays clickable for up to ten minutes and
     * leads to a 404.
     */
    #[Test]
    public function archived_and_unfeatured_destinations_appear_in_neither_fan(): void
    {
        $this->make('Aguinaldo Shrine');
        $archived = $this->make('Corregidor Island', ['is_active' => false]);
        $unfeatured = $this->make('Taal Volcano', ['is_featured' => false]);
        $live = $this->make('Bantay Abot Cave');

        $names = $this->panelNames($this->showHtml($live));

        $this->assertSame(
            ['Aguinaldo Shrine', 'Bantay Abot Cave'],
            $names,
            'the fan must exclude archived and un-featured destinations'
        );

        // Archived is unpublished, so its page is gone.
        $this->get(route('destinations.show', $archived))->assertNotFound();

        /*
         * Un-featured is NOT unpublished. It is a published destination the owner
         * has chosen not to feature, so its page still works -- it is only absent
         * from the fan. Asserting a 404 here would bake in a much stronger promise
         * than "not in the carousel".
         */
        $this->get(route('destinations.show', $unfeatured))->assertOk();
    }

    /**
     * A single destination gets no pager and no chevrons.
     *
     * A one-item fan is motion with no information: the dots cannot go anywhere and
     * the arrows have no neighbour.
     */
    #[Test]
    public function a_single_destination_has_no_pager_and_no_chevrons(): void
    {
        $only = $this->make('The Only One');

        $xpath = $this->xpath($this->showHtml($only));

        $this->assertSame(1, $xpath->query('//*[@data-stage-panel]')->length);

        $this->assertSame(
            0,
            $xpath->query('//*[@data-stage-dot]')->length,
            'one destination is not a carousel, so there are no dots'
        );

        $this->assertSame(0, $xpath->query('//*[@data-stage-chevron]')->length);

        // And the page itself is still fully usable.
        $this->assertSame(1, $xpath->query('//h1')->length);
        $this->assertStringContainsString('data-destination-map', $xpath->document->saveHTML());
    }

    /**
     * An empty catalogue still renders the page -- just not a fan of nothing.
     *
     * The empty state lives on the destination page, so it is reached through a
     * destination that exists while no featured one does.
     */
    #[Test]
    public function an_empty_fan_renders_the_page_without_an_empty_arc(): void
    {
        $hidden = $this->make('Hidden Place', ['is_featured' => false]);

        $xpath = $this->xpath($this->showHtml($hidden));

        $this->assertSame(
            0,
            $xpath->query('//*[@data-stage-panel]')->length,
            'an empty catalogue must not render a fan of nothing'
        );

        $this->assertSame(
            0,
            $xpath->query('//*[@data-stage-chevron]')->length,
            'there is no neighbour to link to'
        );

        // The destination's own content is unaffected.
        $this->assertSame(1, $xpath->query('//h1')->length);
        $this->assertStringContainsString(
            'data-destination-map',
            $xpath->document->saveHTML(),
            'losing the fan must not take the map with it'
        );
    }

    /**
     * A stale or poisoned cache entry degrades to a miss, not a 500.
     *
     * The cache driver is the database, so entries are serialised and SURVIVE A
     * DEPLOY. Caching a Collection of `CarouselSlide` objects therefore wrote a
     * payload naming classes a later deploy may rename or reshape, and
     * unserialising one yields `__PHP_Incomplete_Class` -- which, behind a strict
     * `Collection` return type, became a TypeError and took down the page.
     *
     * Three shapes are planted here, all of which a visitor could be served:
     * objects from the previous class shape, a string from an unrelated key, and
     * an array of rows missing the `slug` the rehydrator keys on.
     */
    #[Test]
    public function a_stale_or_poisoned_cache_entry_degrades_to_a_miss(): void
    {
        $destination = $this->make('Aguinaldo Shrine');
        $this->make('Corregidor Island');

        $key = 'destinations.carousel.v2';
        $carousel = \App\Domain\Destinations\DestinationCarousel::class;

        // 1. What the previous implementation stored: objects, not arrays.
        cache()->put($key, collect([$destination]), 600);

        $this->assertNotEmpty(app($carousel)->items());

        // 2. Something unrelated entirely.
        cache()->put($key, 'not a collection at all', 600);

        $this->assertNotEmpty(app($carousel)->items());

        // 3. Arrays, but rows without the field the rehydrator keys on.
        cache()->put($key, [['name' => 'No slug here']], 600);

        $this->assertNotEmpty(app($carousel)->items());

        // And the good path still works.
        cache()->forget($key);

        $this->assertContains('Aguinaldo Shrine', $this->panelNames($this->showHtml($destination)));
    }

    /**
     * The cached carousel is an array, not an object graph.
     *
     * The mechanism of the above, asserted directly so the fix cannot be undone by
     * someone "simplifying" the presenter back to caching a Collection.
     */
    #[Test]
    public function the_cached_carousel_is_an_array_not_an_object_graph(): void
    {
        $this->make('Aguinaldo Shrine');

        app(\App\Domain\Destinations\DestinationCarousel::class)->items();

        $cached = cache()->get('destinations.carousel.v2');

        $this->assertIsArray(
            $cached,
            'the cached carousel must be an array of scalar rows. An object graph '
            .'outlives the class that built it across a deploy and unserialises to '
            .'__PHP_Incomplete_Class, which 500s the page.'
        );

        $this->assertIsArray($cached[0]);
        $this->assertArrayHasKey('slug', $cached[0]);

        foreach ($cached[0] as $value) {
            $this->assertIsNotObject($value);
        }
    }

    /**
     * The script reads exactly the panel fields the markup renders.
     *
     * The caption used to bind `activeSlide().regionLabel` while the script
     * returned an object keyed `region`, and the chip went blank the moment Alpine
     * took over -- no error, invisible to any assertion about rendered HTML. The
     * caption is static now, but the panel data the live region reads off is still
     * a template/script contract, so it is compared directly.
     */
    #[Test]
    public function the_script_reads_fields_the_panels_actually_render(): void
    {
        $destination = $this->make('Aguinaldo Shrine');
        $this->make('Corregidor Island');

        $xpath = $this->xpath($this->showHtml($destination));

        $script = (string) file_get_contents(resource_path('js/hero-carousel.js'));

        // Every dataset key the script reads must be rendered on the panel.
        preg_match_all('/panel\.dataset\.(\w+)/', $script, $read);

        $this->assertNotEmpty($read[1], 'the script reads no dataset fields, so this guard checks nothing');

        /*
         * `dataset.camelCase` maps to `data-kebab-case`, NOT to a lowercased
         * attribute name. `panel.dataset.stageName` reads `data-stage-name`, so a
         * plain `strtolower` comparison asks for `data-stagename` and reports a
         * panel that is plainly correct as missing.
         */
        $rendered = [];

        foreach ($xpath->query('//*[@data-stage-panel]')->item(0)->attributes as $attribute) {
            $rendered[] = strtolower($attribute->name);
        }

        foreach (array_unique($read[1]) as $field) {
            // Kebab-case FIRST, then lowercase. Doing it the other way round
            // lowercases away the capitals the regex is looking for, and
            // 'stageName' comes out as 'stagename' -- which is the same class of
            // silent mismatch this test exists to catch.
            $attribute = 'data-'.strtolower(
                preg_replace('/(?<!^)[A-Z]/', '-$0', $field)
            );

            $this->assertContains(
                $attribute,
                $rendered,
                "the script reads panel.dataset.{$field}, which maps to "
                ."`{$attribute}`, but no panel renders it. It will read undefined "
                .'and go blank as soon as Alpine takes over, with no error anywhere.'
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
         * in the PROSE and reported the correct ordering as reversed. Searching
         * source that explains itself needs the prose taken out first.
         */
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);

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

        $this->assertMatchesRegularExpression(
            "/^import heroCarousel from '\.\/hero-carousel';/m",
            $code,
            'hero-carousel.js must be imported by app.js so it is bundled. A '
            .'standalone entry is what produced a 0.00 kB bundle once already.'
        );
    }
}