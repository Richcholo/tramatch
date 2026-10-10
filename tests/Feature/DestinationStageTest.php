<?php

namespace Tests\Feature;

use App\Domain\Destinations\DestinationCarousel;
use App\Models\Destination;
use App\Models\DestinationImage;
use App\Models\DestinationSwipe;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The destinations stage.
 *
 * =========================================================================
 * WHAT THESE TESTS ARE AND ARE NOT
 * =========================================================================
 *
 * There is NO BROWSER AUTOMATION in this repository, so none of this can see
 * that the fan moves, that the backdrop cross-fades, or that the caption arrives
 * after the panels settle. What it can do is pin every CONTRACT the browser is
 * relying on, so that a change which breaks one fails here instead of looking
 * like a styling preference on a click-through.
 *
 * The contracts are:
 *
 *   - the ORDER, which both pages read and neither may compute for itself;
 *   - `data-offset`, which is the whole animation mechanism;
 *   - the markup/attribute seams between the Blade, the CSS and the script,
 *     where a rename silently disables a feature rather than breaking the page.
 *
 * `the_fan_actually_moves` is the one to read first. An earlier version of this
 * stage shipped looking frozen -- state updated, geometry did not, and no test
 * failed -- and every guard that would have caught it was checking rendered
 * markup that was genuinely correct.
 */
class DestinationStageTest extends TestCase
{
    use RefreshDatabase;

    private function destination(array $attributes = []): Destination
    {
        return Destination::create(array_merge([
            'name' => 'Caliraya Lake',
            'slug' => 'caliraya-lake',
            'description' => 'A reservoir in the Sierra Madre. The boat leaves at dawn.',
            'province' => 'Laguna',
            'municipality' => 'Caliraya',
            'latitude' => 14.1,
            'longitude' => 121.5,
            'budget_level' => 'economy',
            'entrance_fee' => 0,
            'estimated_cost' => 450,
            'recommended_minutes' => 120,
            'image_url' => 'https://images.example.com/caliraya.jpg',
        ], $attributes));
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument();

        @$document->loadHTML($html, LIBXML_NOERROR);

        return new DOMXPath($document);
    }

    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    private function script(): string
    {
        return (string) file_get_contents(resource_path('js/stage.js'));
    }

    /**
     * The panel `[data-offset]` values, in document order.
     *
     * @return array<int, string>
     */
    private function offsetsIn(string $html): array
    {
        $dom = $this->dom($html);

        $out = [];

        foreach ($dom->query('//*[@data-stage-panel]') as $panel) {
            $out[] = $panel->getAttribute('data-offset');
        }

        return $out;
    }

    /**
     * An uploaded photograph on a destination, which is what a "slide" is.
     *
     * A helper because the stage is about photographs grouped per destination and
     * every test that means anything has to build a set of them. `sort_order`
     * defaults to 0, which is the value every real row carries until somebody
     * curates it, so a test that wants a particular order has to say so.
     */
    private function photograph(Destination $destination, string $path, int $sortOrder = 0): DestinationImage
    {
        return DestinationImage::create([
            'destination_id' => $destination->id,
            'path' => $path,
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * The stage for the first PUBLISHED destination in canonical order.
     *
     * `slides()` filters the cached payload down to one slug, so this helper has
     * to hand it a slug that is actually IN that payload. It used to pick the
     * first destination of any kind, which worked only while `slides()` returned
     * the whole catalogue and ignored the slug entirely -- hand it an archived
     * destination now and it correctly returns nothing, which reads as a bug
     * until you remember the helper is asking for the wrong thing.
     *
     * `where('is_active', true)` rather than assuming the first row is published:
     * the tests that archive a destination and then ask what the stage holds are
     * exactly the ones that would otherwise silently assert nothing.
     */
    private function stage(): Collection
    {
        $first = Destination::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->value('slug');

        return app(DestinationCarousel::class)->slides((string) $first);
    }

    // =====================================================================
    // The order. One query, one order, both pages.
    // =====================================================================

/**
     * THE STAGE HOLDS ONE DESTINATION'S PHOTOGRAPHS AND NOTHING ELSE.
     *
     * It used to fan across every featured destination with this one in the middle,
     * which sounds like the richer idea and is the wrong one on this route. You
     * arrive somewhere and the fan is holding a photograph of somewhere else,
     * with this destination's caption over it. Two places on screen at once, both
     * of them rendering correctly -- which is the worst kind of wrong, because it
     * cannot be described precisely enough to be reported.
     *
     * The caption's rule that every field is read off ONE slide cannot prevent
     * that from across a destination boundary. The stage has to not do it.
     */
    #[Test]
    public function the_stage_holds_this_destinations_photographs_and_nobody_elses(): void
    {
        $alpha = $this->destination([
            'name' => 'Alpha',
            'slug' => 'alpha',
            'image_url' => 'https://images.example.com/alpha-hero.jpg',
        ]);

        $this->photograph($alpha, 'https://images.example.com/alpha-1.jpg');

        $beta = $this->destination([
            'name' => 'Beta',
            'slug' => 'beta',
            'image_url' => 'https://images.example.com/beta-hero.jpg',
        ]);

        $this->photograph($beta, 'https://images.example.com/beta-1.jpg');

        $carousel = app(DestinationCarousel::class);

        $this->assertSame(
            [
                'https://images.example.com/alpha-hero.jpg',
                'https://images.example.com/alpha-1.jpg',
            ],
            $carousel->slides('alpha')->map->imageUrl->all(),
            "Alpha's stage has picked up a photograph that is not Alpha's"
        );

        $this->assertSame(
            [
                'https://images.example.com/beta-hero.jpg',
                'https://images.example.com/beta-1.jpg',
            ],
            $carousel->slides('beta')->map->imageUrl->all(),
            'each destination must get its OWN stage rather than a shared one'
        );
    }

    /**
     * Within one destination: the hero photograph first, then uploads in
     * `sort_order`.
     *
     * The hero is first because it is the photograph the page is about, and it is
     * the one the LCP candidate budget is spent on. The uploads follow the order
     * an admin chose by dragging them, which is `sort_order`.
     *
     * Note that the SECONDARY destination sort (`sort_order`, then `name`) is no
     * longer observable on this route and is not asserted here: it orders the
     * cached payload, and the payload is filtered down to one slug before a slide
     * is ever returned. It still matters, because two requests in the same page
     * load must agree on the payload they are both filtering.
     */
    #[Test]
    public function a_destinations_own_photographs_run_hero_first_then_in_sort_order(): void
    {
        $alpha = $this->destination([
            'name' => 'Alpha',
            'slug' => 'alpha',
            'image_url' => 'https://images.example.com/alpha-hero.jpg',
        ]);

        // Created out of order on purpose: `sort_order` decides, not creation order.
        $this->photograph($alpha, 'https://images.example.com/alpha-3.jpg', 30);
        $this->photograph($alpha, 'https://images.example.com/alpha-1.jpg', 10);
        $this->photograph($alpha, 'https://images.example.com/alpha-2.jpg', 20);

        $this->assertSame(
            [
                'https://images.example.com/alpha-hero.jpg',
                'https://images.example.com/alpha-1.jpg',
                'https://images.example.com/alpha-2.jpg',
                'https://images.example.com/alpha-3.jpg',
            ],
            app(DestinationCarousel::class)->slides('alpha')->map->imageUrl->all()
        );
    }

    /**
     * OFFSETS RESTART AT ZERO, so the fan opens ONE-SIDED.
     *
     * This is the honest consequence of two decisions arriving together, and it is
     * worth stating plainly because it looks like a bug in a screenshot.
     *
     *   - the hero photograph is always the active one, so the stage opens on the
     *     photograph the page is about, and
     *   - offsets are `index - 0`, so everything else is to the RIGHT.
     *
     * There is nothing to the left because there is no earlier photograph of this
     * destination. A fan that wrapped, or that centred the middle photograph and
     * opened on an upload instead of the hero, would be symmetrical and would be
     * lying about which picture you arrived at.
     *
     * So a three-photograph destination opens as: hero centred, second to its
     * right, third further right. Which is the ordinary arrangement for a
     * "current and what follows" carousel.
     *
     * There is no negative offset on this route, and that is the point to assert:
     * a negative one would mean another destination's photograph had crept in.
     */
    #[Test]
    public function the_hero_photograph_is_active_and_the_offsets_restart_at_zero(): void
    {
        $alpha = $this->destination([
            'name' => 'Alpha',
            'slug' => 'alpha',
            'image_url' => 'https://images.example.com/alpha-hero.jpg',
        ]);

        $this->photograph($alpha, 'https://images.example.com/alpha-1.jpg');
        $this->photograph($alpha, 'https://images.example.com/alpha-2.jpg');

        $slides = app(DestinationCarousel::class)->slides('alpha');

        $this->assertSame([0, 1, 2], $slides->map->offset->all());

        $this->assertTrue($slides[0]->isActive, 'the hero photograph must be the active one');
        $this->assertFalse($slides[1]->isActive);
        $this->assertFalse($slides[2]->isActive);

        $this->assertGreaterThanOrEqual(
            0,
            $slides->map->offset->min(),
            'a negative offset means a photograph from BEFORE this destination\'s own set, '
            .'which is another destination\'s picture'
        );
    }

    /**
     * A photograph past the reach is marked `data-far`, NOT removed and NOT wrapped.
     *
     * All five stay in the DOM so the cascade has somewhere to come from, and the
     * two beyond the reach are pushed off-stage by CSS. Wrapping would send the
     * fifth from far-right to far-left in one step, which is a visible pop.
     *
     * Five photographs also exercises the outer pair of the formation for the only
     * time on this route: a destination with three uploads is offsets 0, 1 and 2,
     * so the left flank and the two `-2` rules are dead code at that size and only
     * mean anything once somebody uploads a fourth and fifth.
     */
    #[Test]
    public function a_photograph_past_the_reach_is_rendered_far_and_never_dropped(): void
    {
        $alpha = $this->destination([
            'name' => 'Alpha',
            'slug' => 'alpha',
            'image_url' => 'https://images.example.com/alpha-hero.jpg',
        ]);

        foreach (range(1, 4) as $n) {
            $this->photograph($alpha, "https://images.example.com/alpha-{$n}.jpg", $n * 10);
        }

        $carousel = app(DestinationCarousel::class);
        $slides = $carousel->slides('alpha');

        $this->assertCount(5, $slides, 'a photograph beyond the reach must still be a slide');

        $this->assertSame([0, 1, 2, 3, 4], $slides->map->offset->all());

        $html = (string) $this->get(route('destinations.show', $alpha))->assertOk()->getContent();

        $this->assertCount(5, $this->dom($html)->query('//*[@data-stage-panel]'));

        foreach ($slides as $slide) {
            $far = $slide->offset > DestinationCarousel::REACH;

            $this->assertSame(
                $far ? 1 : 0,
                $this->dom($html)
                    ->query(sprintf('//*[@data-stage-panel][@data-offset="%d"][@data-far]', $slide->offset))
                    ->length,
                "offset {$slide->offset} should ".($far ? '' : 'NOT ').'be marked data-far'
            );
        }
    }

/**
     * THE PAYLOAD ISLAND IS INSIDE THE `x-data` SCOPE.
     *
     * THE BUG THIS EXISTS FOR. `readPayload()` reads the island with
     * `this.$el.querySelector('[data-stage-data]')`, and `$el` is the
     * `<section x-data="destinationStage">`. The island used to be rendered as a
     * SIBLING of that section, one line further down the same Blade file, which
     * reads as tidier and is outside the subtree the script searches. The query
     * returned null, `.textContent` threw, and the `catch` returned `slides: []`.
     *
     * The failure is silent and total, and it is worth writing down in full
     * because every symptom looks like a different bug:
     *
     *   - `count` is 0, so `autoplayable()` is false and autoplay never starts;
     *   - `index` clamps to 0, so `offsetOf()` returns `i - 0` and the fan
     *     re-indexes itself onto whatever the FIRST panel in the DOM is -- a
     *     photograph of a completely different destination;
     *   - `go()` clamps every target to 0 and returns early because the target
     *     already equals the index, so the dots and the chevrons all do nothing;
     *   - the offsets have been rewritten to 0, 1, 2 ... and then never change,
     *     so nothing animates;
     *   - and the caption, the backdrop and the server-rendered offsets all still
     *     describe the destination that was actually asked for.
     *
     * So the page showed one destination's photographs under another
     * destination's backdrop, with every control inert and no motion, and
     * nothing anywhere reported an error. Every HTTP-level test passed
     * throughout, because the server-rendered markup was genuinely correct.
     *
     * Asserted on the DOM RATHER than by grepping the Blade source, because the
     * relationship that matters is the one the browser builds: the island has to
     * be a DESCENDANT of the element carrying `x-data`.
     */
    #[Test]
    public function the_payload_island_is_a_descendant_of_the_element_that_reads_it(): void
    {
        $html = (string) $this->get(route('destinations.show', $this->destination()))
            ->assertOk()
            ->getContent();

        $dom = $this->dom($html);

        $island = $dom->query('//script[@data-stage-data]');

        $this->assertSame(1, $island->length, 'the payload island is missing or duplicated');

        /*
         * `ancestor::*[@data-stage]` rather than counting ancestors: what matters
         * is that the reader is one of them, not how many wrappers are in between.
         */
        $this->assertGreaterThan(
            0,
            $dom->query('//script[@data-stage-data]/ancestor::*[@data-stage]')->length,
            'THE PAYLOAD ISLAND IS OUTSIDE THE x-data SCOPE. `readPayload()` searches '
            .'`this.$el`, so an island that is not a DESCENDANT of the section is '
            .'invisible to it: the payload reads as empty, `count` is 0, the fan '
            .'re-indexes onto the first panel in the DOM -- a different '
            .'destination\'s photograph -- autoplay never starts, every control '
            .'clamps to 0 and does nothing, and the offsets stop changing.'
        );

        $this->assertStringContainsString(
            'this.$el.querySelector(\'[data-stage-data]\')',
            $this->script(),
            'the script no longer reads the island from `$el`. If it reaches for '
            .'`document` instead, the descendant relationship above stops being '
            .'load-bearing and this guard is asserting the wrong thing.'
        );
    }

    /**
     * A STAGE THAT CANNOT READ ITS DATA LEAVES THE STAGE AS RENDERED.
     *
     * The structural fix above is the real one. This is the second line of
     * defence, and it exists because the failure mode above is so bad: a
     * DEGRADED stage and a WRONG stage look identical from outside, and only one
     * of them is showing you a photograph of somewhere else.
     *
     * So every panel carries `data-stage-start` -- the server's offset, written
     * once by PHP and never bound -- and `offsetOf()` answers from it whenever the
     * payload is unusable. `data-offset` itself cannot be used for this: it is
     * the live attribute, and Alpine overwrites it before anything could read it
     * back.
     *
     * This asserts the three halves that have to agree: the attribute exists on
     * every panel, the script reads it, and the script has a branch that uses it.
     */
    #[Test]
    public function an_unreadable_payload_freezes_the_fan_where_the_server_put_it(): void
    {
        $destination = $this->destination();

        $this->photograph($destination, 'https://images.example.com/one.jpg');
        $this->photograph($destination, 'https://images.example.com/two.jpg');

        $html = (string) $this->get(route('destinations.show', $destination))->assertOk()->getContent();
        $dom = $this->dom($html);

        $panels = $dom->query('//*[@data-stage-panel]');

        $this->assertSame(3, $panels->length);

        foreach ($panels as $panel) {
            $this->assertSame(
                $panel->getAttribute('data-offset'),
                $panel->getAttribute('data-stage-start'),
                'data-stage-start must record the offset the server rendered, in a '
                .'attribute the script never writes'
            );
        }

        // The one that is live and unbound: a bound attribute would be rewritten.
        $this->assertStringNotContainsString(
            'x-bind:data-stage-start',
            $html,
            'data-stage-start must not be bound, or it stops being the server\'s value'
        );

        $script = $this->script();

        $this->assertStringContainsString(
            'Number(panel.dataset.stageStart)',
            $script,
            'the script no longer captures the server offsets, so there is nothing to '
            .'fall back to'
        );

        $this->assertMatchesRegularExpression(
            '/offsetOf\(i\)\s*\{\s*if \(this\.unreadable\)/s',
            $script,
            'offsetOf() does not fall back to the server offsets when the payload is '
            .'unusable. Without the branch, an unreadable payload re-indexes the fan '
            .'onto panel zero.'
        );
    }

/**
     * THE FAN CAN BE DRAGGED, AND EVERY PART OF THAT IS A CONTRACT.
     *
     * There is no browser automation in this repo, so none of this can be tested
     * by dragging. What it can do is pin the four pieces without which a drag is
     * either invisible or unusable -- and each one fails SILENTLY, which is what
     * makes them worth asserting separately.
     *
     *   1. `touch-action: pan-y` on the fan. Without it a swipe on a phone is a
     *      coin toss between scrolling the page and moving the carousel, and the
     *      browser delays every pointermove until it has decided which.
     *   2. The pointer is CAPTURED on the fan. The panels are narrower than the
     *      fan, so a drag that travels more than a panel's width leaves the
     *      element the press landed on -- and without capture the gesture dies
     *      halfway and the fan snaps back under a finger that is still moving.
     *   3. The click is intercepted in the CAPTURE phase, on an ANCESTOR of the
     *      panel link. By the time a click event exists there is no drag left to
     *      cancel, and every panel is an `<a>`: without this, every drag that
     *      ends over a card navigates away from the page being read.
     *   4. The transition is suspended while dragging and restored after it. The
     *      fan has to TRACK the pointer, not lag behind it, and a cancelled drag
     *      has to ease home rather than jump.
     *
     * A DEAD ZONE is asserted too, because it is the difference between a tap and
     * a drag: 6px, below the tap threshold on every platform that has one. Without
     * it a plain tap on a panel flickers the dragging class, changes the cursor,
     * takes the autoplay hold and releases it again, and competes with the
     * browser's own tap gesture on a touch screen.
     */
    #[Test]
    public function the_fan_is_draggable_and_the_gesture_has_the_parts_that_make_it_usable(): void
    {
        $css = $this->css();
        $script = $this->script();

        // 1. The browser may scroll vertically, and horizontal movement is ours.
        $this->assertMatchesRegularExpression(
            '/\.tm-fan\s*\{[^}]*touch-action:\s*pan-y/s',
            $css,
            'the fan does not declare `touch-action: pan-y`. A swipe on a phone is then a '
            .'coin toss between scrolling the page and moving the carousel.'
        );

        // 2. The pointer is captured, and on the FAN rather than on a panel.
        $this->assertStringContainsString(
            'this.fan.setPointerCapture(event.pointerId)',
            $script,
            'the drag does not capture the pointer. The panels are narrower than the fan, so '
            .'the gesture dies the moment it travels more than a panel width.'
        );

        // 3. The click is killed before the anchor can see it.
        $this->assertMatchesRegularExpression(
            "/this\.\\\$el\.addEventListener\('click'.*?\}, true\);/s",
            $script,
            'the drag does not suppress the synthesised click. Every panel is a link to its '
            .'destination, so a drag that ends over one navigates away from the page.'
        );

        $this->assertStringContainsString(
            'event.stopPropagation()',
            $script,
            'the click interceptor does not stop propagation, so the anchor still receives it'
        );

        // 4. No transition while the finger is down; one again once it is up.
        $this->assertMatchesRegularExpression(
            '/\.tm-fan\.is-dragging\s*\{[^}]*transition:\s*none/s',
            $css,
            'the transition is not suspended while dragging, so the fan lags behind the '
            .'pointer instead of tracking it'
        );

        $this->assertStringContainsString(
            "this.fan.classList.add('is-dragging')",
            $script,
            'the drag never adds the dragging class'
        );

        $this->assertStringContainsString(
            "this.fan.classList.remove('is-dragging')",
            $script,
            'the dragging class is never removed, so a cancelled drag can never ease home'
        );

        // The dead zone, and the distance threshold that commits an advance.
        $this->assertStringContainsString(
            'Math.abs(delta) < 6',
            $script,
            'the drag has no dead zone, so a plain tap counts as a gesture'
        );

        $this->assertStringContainsString(
            'dragThreshold()',
            $script,
            'a release does not have to travel far enough to commit to an advance'
        );

        // 5. THE BROWSER'S OWN DRAG MUST NOT TAKE THE GESTURE. The
        // photograph and the link wrapping it are both draggable by
        // default, so a horizontal pull starts a native drag-and-drop:
        // a ghost image follows the pointer, `pointercancel` fires, and
        // the fan's gesture dies halfway. `dragstart` bubbles, so one
        // listener on the fan cancels it for every descendant.
        $this->assertStringContainsString(
            "addEventListener('dragstart'",
            $script,
            'the fan does not cancel native dragstart, so pulling a '
            ."photograph starts the browser's own drag-and-drop and the "
            .'gesture dies halfway'
        );

        $this->assertMatchesRegularExpression(
            '/\.tm-fan\s*\{[^}]*user-select:\s*none/s',
            $css,
            'the fan does not disable text selection, so a swipe selects '
            .'the province pill instead of moving the fan'
        );

        $destination = $this->destination();
        $this->photograph($destination, 'https://images.example.com/drag-1.jpg');
        $this->photograph($destination, 'https://images.example.com/drag-2.jpg');

        $html = (string) $this->get(route('destinations.show', $destination))->assertOk()->getContent();
        $dom = $this->dom($html);

        $links = $dom->query('//*[@data-stage-panel]//a[@data-stage-link]');
        $this->assertGreaterThan(0, $links->length, 'no panel links were rendered');

        foreach ($links as $link) {
            $this->assertSame(
                'false',
                $link->getAttribute('draggable'),
                'a panel link is still draggable, so pulling it starts the '
                ."browser's own link drag and the fan's gesture dies"
            );
        }

        $images = $dom->query('//*[@data-stage-panel]//img');
        $this->assertGreaterThan(0, $images->length, 'no panel photographs were rendered');

        foreach ($images as $image) {
            $this->assertSame(
                'false',
                $image->getAttribute('draggable'),
                'a panel photograph is still draggable, so pulling it starts '
                ."the browser's own image drag and the fan's gesture dies"
            );
        }
    }

    /**
     * A DRAG ENDS BY MOVING THE INDEX, AND IT READS ITS DIRECTION BEFORE CLEARING.
     *
     * `dragEnd()` zeroes `dragDelta` and then branches on it. Swapping those two
     * lines compiles, passes every other guard on this page, and makes every drag
     * advance in the same direction -- forwards on a rightward swipe and backwards
     * on a leftward one.
     *
     * There is no way to test the gesture itself here, so what is pinned is the
     * ORDER: the direction is captured while the delta is still non-zero.
     */
    #[Test]
    public function the_drag_reads_its_direction_before_clearing_the_delta(): void
    {
        $script = $this->script();

        $direction = strpos($script, 'const forwards = this.dragDelta < 0;');
        $cleared = strpos($script, 'this.dragDelta = 0;', $direction ?: 0);

        $this->assertIsInt($direction, 'dragEnd() no longer captures the drag direction');
        $this->assertIsInt($cleared, 'dragEnd() no longer clears the delta');
        $this->assertLessThan(
            $cleared,
            $direction,
            'the drag direction is read AFTER the delta has been zeroed, so every drag '
            .'advances the same way regardless of which way it was thrown'
        );

        $this->assertStringContainsString('if (forwards) {', $script);
        $this->assertStringContainsString('this.next();', $script);
        $this->assertStringContainsString('this.prev();', $script);
    }

    /**
     * NO METHOD IN THE STAGE IS DEFINED TWICE.
     *
     * This is not a style rule. `destinationStage()` returns an OBJECT LITERAL,
     * and a duplicate key in an object literal is not an error, not a warning, and
     * not a lint failure in most configurations: the LAST one silently wins and
     * the earlier one is discarded.
     *
     * It happened here, and it disabled a safety net while every test still passed.
     * A replacement anchored on `offsetOf()`'s docblock rather than on its whole
     * body added the new definition above the old one; the old definition then won,
     * so the `unreadable` fallback that stops a stage with no payload from
     * re-indexing itself onto the first panel in the DOM was dead code -- present,
     * commented at length, asserted by name, and never once executed.
     *
     * A duplicate here is the most dangerous possible edit outcome: the file
     * reads correctly, the feature reads as implemented, and the browser runs the
     * version nobody meant. So the count is asserted, per method name.
     */
    #[Test]
    public function no_method_in_the_stage_is_defined_twice(): void
    {
        preg_match_all('/^\s{8}(\w+)\(/m', $this->script(), $matches);

        $names = array_map('trim', $matches[1]);

        $this->assertNotEmpty($names, 'no component methods were found, which cannot be right');

        $duplicates = array_keys(array_filter(array_count_values($names), fn ($n) => $n > 1));

        $this->assertSame(
            [],
            $duplicates,
            'the stage defines '.implode(', ', $duplicates).' more than once. A duplicate key in '
            .'an object literal is not an error: the LAST definition silently wins, so this '
            .'ships dead code that still reads as implemented.'
        );
    }

    /**
     * THE DARK SURFACE REACHES BOTH EDGES AT EVERY BREAKPOINT.
     *
     * `.tm-page` cancels `main`'s own padding with negative margins, which is the
     * only way a background can run under padding without a wrapper. The
     * arithmetic has to be exact at EVERY breakpoint the layout declares, and it
     * was not: `main` goes to `lg:px-12` while the page only reached back to
     * `lg:-mx-8`, leaving a 1rem band of the page's own background down each side
     * at the widest sizes. Which is a white edge on a dark page -- small, and only
     * on a big screen, which is why it is easy to accept as a rendering quirk.
     *
     * This reads `main`'s own classes out of the layout rather than hard-coding
     * the breakpoints, so adding `xl:px-16` there without a matching
     * `xl:-mx-16` here fails this test instead of shipping.
     */
    #[Test]
    public function the_dark_surface_cancels_the_layout_padding_at_every_breakpoint(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertMatchesRegularExpression(
            '/px-5 py-10 sm:px-8 lg:px-12/',
            $layout,
            'the layout\'s own padding on `main` has changed. This test reads it above; if '
            .'it no longer matches, `.tm-page` needs re-checking by hand.'
        );

        $dom = $this->dom(
            (string) $this->get(route('destinations.show', $this->destination()))->assertOk()->getContent()
        );

        $page = $dom->query('//*[contains(concat(" ", normalize-space(@class), " "), " tm-page ")]')->item(0);

        $this->assertNotNull($page, 'the dark surface wrapper is missing from the page');

        $classes = preg_split('/\s+/', (string) $page->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY);

        /*
         * `main` pads on the inline axis and the page pads back out on the same
         * axis, so each negative margin needs its positive counterpart at the SAME
         * breakpoint -- and the two live under the same prefix in the class list.
         */
        foreach ([
            '-mx-5' => 'px-5',
            'sm:-mx-8' => 'sm:px-8',
            'lg:-mx-12' => 'lg:px-12',
        ] as $negative => $positive) {
            $this->assertContains(
                $negative,
                $classes,
                "`.tm-page` no longer reaches back by {$negative}, so the teal does not reach "
                .'the edge at that breakpoint'
            );

            $this->assertContains(
                $positive,
                $classes,
                "`.tm-page` no longer pads back out by {$positive}, so its own content has "
                .'moved outwards with the background'
            );
        }

        $this->assertContains('-my-10', $classes, 'the page no longer bleeds under main\'s block padding');
    }

    // =====================================================================
    // The frozen-stage regression. Read this one first.
    // =====================================================================
    /**
     * THE FAN ACTUALLY MOVES.
     *
     * Four conditions, all of which have to hold, and the failure mode of
     * dropping any one of them is the same and is invisible: the panels render in
     * the right places, the active one is highlighted, and nothing moves.
     *
     *   1. every panel BINDS `data-offset` (rather than only having it set once);
     *   2. the bound value is computed FROM THE ACTIVE INDEX;
     *   3. the CSS derives geometry from `[data-offset]`;
     *   4. that geometry TRANSITIONS.
     *
     * The version this replaces wrote `data-stage-offset` from PHP, left it
     * alone, and toggled a class. State updated; layout did not. Because the
     * server-rendered markup was genuinely correct, every HTTP-level test passed.
     */
    #[Test]
    public function the_fan_actually_moves(): void
    {
        $html = (string) $this->get(route('destinations.show', $this->destination()))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/x-bind:data-offset="offsetOf\(\d+\)"/',
            $html,
            'no panel binds data-offset. The value would be written once by Blade and never '
            .'rewritten, so advancing changes which panel is active and never where the '
            .'panels are -- the fan looks frozen and nothing reports an error.'
        );

        $this->assertStringContainsString(
            'offsetOf(i) {',
            $this->script(),
            'offsetOf() is gone from the script. The panels must compute their own signed '
            .'offset from the active index, not read one from the markup.'
        );

        /*
         * `return i - this.index` must be the LAST statement, not the first: it is
         * now preceded by the `unreadable` fallback, which returns the server's
         * own offset instead. Anchoring on "immediately after the brace" would
         * have failed the moment that fallback was added, and the obvious
         * response -- loosening it to "contains the string somewhere" -- is what
         * let a DUPLICATE `offsetOf` sit underneath this one and win.
         *
         * So the method body is captured and the last return is checked. That
         * distinguishes the real implementation from a dead copy, which a plain
         * substring check cannot.
         */
        preg_match('/offsetOf\(i\) \{(.*?)\n        \},/s', $this->script(), $body);

        $this->assertArrayHasKey(1, $body, 'offsetOf() has no single body to inspect');

        $this->assertMatchesRegularExpression(
            '/return i - this\.index;\s*$/',
            $body[1],
            'offsetOf() no longer ENDS by returning the panel\'s index minus the active '
            .'index. Every panel would report the same offset and the geometry would not '
            .'move.'
        );

        $this->assertMatchesRegularExpression(
            "/\[data-offset='-1'\]\s*\{[^}]*left:/s",
            $this->css(),
            'no CSS positions a panel by its offset. The geometry has to be derived from '
            .'data-offset in the stylesheet, or there is nothing for the browser to interpolate.'
        );

        $this->assertMatchesRegularExpression(
            '/\.tm-fan-panel\s*\{[^}]*transition:[^}]*620ms/s',
            $this->css(),
            'the panels do not transition. A new offset would be applied instantly and the '
            .'fan would jump rather than move.'
        );

        $this->assertStringNotContainsString(
            'data-stage-offset',
            $html,
            'a panel still carries a PHP-written offset attribute. Any offset set from the '
            .'server is the starting state only; a second one is a second source of truth.'
        );
    }

    /**
     * The component is registered BEFORE `Alpine.start()`.
     *
     * A registration that lands after start() leaves `x-data` unevaluated: the
     * panels render, the caption renders, the dots render, and the script that
     * moves them is silently never attached. Indistinguishable from a frozen
     * stage, and it reports nothing.
     */
    #[Test]
    public function the_alpine_component_is_registered_before_alpine_starts(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.js'));

        /*
         * Comments are stripped before searching. The note above the registration
         * in app.js is ABOUT this ordering and contains the literal text
         * `Alpine.start()`, so an unstripped search finds the comment first and
         * reports that the registration is late -- which is precisely how a check
         * like this ends up reading a sentence about itself and passing on code
         * that is wrong.
         */
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $app);

        $register = strpos($code, "Alpine.data('destinationStage'");
        $start = strpos($code, 'Alpine.start()');

        $this->assertIsInt($register, 'the stage component is never registered, so nothing on the page is interactive');
        $this->assertIsInt($start);
        $this->assertLessThan(
            $start,
            $register,
            'the stage component is registered after Alpine.start(). x-data is evaluated '
            .'during start, so every panel would render and the script would never attach.'
        );
    }

    /**
/**
 * THE STAGE MIGRATION IS SAFE WHERE THE COLUMNS ALREADY EXIST.
 *
 * THE PRODUCTION SCENARIO, AS A TEST. These two columns were added, and then the
 * migration was DELETED, when the first fan of this stage was built and reverted.
 * The server had already run the deleted migration, so it has both columns
 * physically present and a `migrations` row for a file no longer on disk. The
 * re-added migration has a DIFFERENT FILENAME, so Laravel sees an unrecorded
 * migration and `migrate` dies with:
 *
 *     SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name 'sort_order'
 *
 * Reproduced here by adding the columns the way that old server has them and then
 * forgetting to record the migration -- which is exactly the state a server is in
 * when a migration file that ran is removed from disk and a new one takes its
 * place.
 */
#[Test]
    public function the_stage_migration_is_safe_where_the_columns_already_exist(): void
    {
        /*
         * THE SERVER'S STATE, reached by deleting the record rather than by adding
         * the columns: `RefreshDatabase` has already migrated from scratch, so both
         * columns are present, and a missing `migrations` row is exactly what a
         * server is left with when a migration that ran is removed from disk and a
         * differently named one takes its place.
         */
        $this->assertTrue(Schema::hasColumn('destinations', 'sort_order'));
        $this->assertTrue(Schema::hasColumn('destinations', 'is_featured'));

        DB::table('migrations')
            ->where('migration', 'like', '%add_carousel_fields_to_destinations_table')
            ->delete();

        /*
         * WITHOUT THE `hasColumn` GUARDS THIS IS WHERE IT DIES:
         *
         *     SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name 'sort_order'
         *
         * Which is how this migration shipped, and how the server is currently
         * stuck. The guards are the fix, and this test is the only thing in the
         * repo that would notice their removal.
         */
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $this->assertTrue(Schema::hasColumn('destinations', 'sort_order'));
        $this->assertTrue(Schema::hasColumn('destinations', 'is_featured'));

        $this->assertSame(
            1,
            DB::table('migrations')
                ->where('migration', 'like', '%add_carousel_fields_to_destinations_table')
                ->count(),
            'the migration did not record itself, so it would be retried on every deploy'
        );

        /*
         * And the columns must still WORK, not merely exist. An `is_featured` that
         * came out NULL rather than true would silently drop every destination out
         * of the stage with no error anywhere.
         */
        $this->destination();

        $this->assertCount(1, $this->stage()->all());
    }

/**
     * THE STAGE MUST DECLARE A WIDTH, not only a max-width.
     *
     * THE BUG THIS EXISTS FOR. `.tm-page__opening` is a COLUMN flex container, so
     * the cross axis is horizontal — and on the cross axis `margin-inline: auto`
     * DISABLES the default `align-items: stretch`. The stage stopped being
     * stretched and was sized by its content instead. Every panel inside it is
     * `position: absolute`, so the stage's content width was zero, and
     * shrink-to-fit of zero is zero.
     *
     * So `.tm-stage` rendered at `width: 0`, `--u` became `calc(100cqw / 1088)` of
     * nothing, `.tm-fan`'s `height: calc(660 * var(--u))` resolved to `0px`, and
     * every absolutely-positioned panel was clipped out of sight by the stage's own
     * `overflow: hidden`.
     *
     * Everything else was perfect and everything reported success: the panels were
     * in the HTML, every image URL returned 200, the stylesheet carried every
     * geometry rule, and all 350-odd HTTP-level tests passed. The console showed
     * `stageWidth: 0`. It worked before the stage became the page because the stage
     * was in block layout then, where `margin-inline: auto` does what it looks
     * like — which is why nothing in review caught it and the browser did, in one
     * glance, in about a second.
     *
     * NO TEST HERE MEASURES GEOMETRY. This one asserts the DECLARATION that
     * geometry depends on, because a declaration can be asserted and computed
     * width cannot.
     */
#[Test]
    public function the_stage_declares_a_definite_width(): void
    {
        preg_match('/\.tm-stage-wrap\s*\{(.*?)\n    \}/s', $this->css(), $rule);

        $declarations = (string) preg_replace('/\/\*.*?\*\//s', '', $rule[1] ?? '');

        foreach ([
            'width: 100%',
            'margin-inline: auto',
            'container-type: inline-size',
        ] as $declaration) {
            $this->assertStringContainsString(
                $declaration,
                $declarations,
                'the stage wrapper has lost `'.$declaration.'`. It is a flex item with '
                .'auto inline margins, so it needs a DEFINITE width or it collapses to '
                .'its content width -- which is zero, because every panel inside it is '
                .'absolutely positioned.'
            );
        }
    }

    /**
     * THE STAGE FILLS ITS CONTAINER, AND `main` IS WHAT BOUNDS IT.
     *
     * The stage used to be capped at 68rem so that `--u` could not exceed 1px. The
     * cap did its job -- until it became the reason the page looked unfinished: a
     * 1088px dark object centred in a 1600px dark field, with the same colour on
     * both sides of it, which reads as a layout that ran out rather than one that
     * was chosen.
     *
     * So the cap is gone, `--u` grows with the container, and the composition
     * scales up as one piece -- which is what `--u` was built to do in the first
     * place.
     *
     * THE BOUND MOVED RATHER THAN DISAPPEARED, and that is the part worth
     * guarding: `main` is capped at 1600px in `layouts/app.blade.php`, so the
     * stage can only ever be as wide as the page around it. Removing that cap as
     * well would let `--u` run away on a 4K display and the panels would grow
     * without limit.
     *
     * Both halves are asserted together, because neither means anything alone: a
     * stage with no cap and no page cap is the failure, and a page cap with no
     * assertion that the stage actually grew is the bug this page had.
     */
    #[Test]
    public function the_stage_grows_with_its_container_and_main_bounds_it(): void
    {
        preg_match('/\.tm-stage-wrap\s*\{(.*?)\n    \}/s', $this->css(), $rule);

        $declarations = (string) preg_replace('/\/\*.*?\*\//s', '', $rule[1] ?? '');

        $this->assertStringNotContainsString(
            'max-width',
            $declarations,
            'the stage wrapper is capped again. On a wide page that puts a small dark '
            .'object in the middle of a large dark one, which is what the cap looked '
            .'like and is why it was removed.'
        );

        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertMatchesRegularExpression(
            '/max-w-\[1600px\]/',
            $layout,
            'the stage no longer caps `--u`, so `main` must. Without this the panels grow '
            .'without limit on a wide display and nothing else on the page does.'
        );
    }

    /**
     * The stage wrapper is a flex item, and that is what makes the width matter.
     *
     * Asserted from the rendered markup as well as the stylesheet, because the two
     * together are the whole mechanism: the wrapper really is inside the flex
     * column, so the auto-margin rule really does apply to it.
     */
#[Test]
    public function the_stage_wrapper_is_a_flex_item_of_the_opening_column(): void
    {
        $html = (string) $this->get(route('destinations.show', $this->destination()))
            ->assertOk()
            ->getContent();

        $dom = $this->dom($html);

        $opening = $dom->query('//*[contains(concat(" ", normalize-space(@class), " "), " tm-page__opening ")]');

        $this->assertSame(1, $opening->length);

        $this->assertSame(
            1,
            $dom->query('.//*[contains(concat(" ", normalize-space(@class), " "), " tm-stage-wrap ")]', $opening->item(0))->length,
            'the stage has drifted out of the opening column'
        );

        $this->assertMatchesRegularExpression(
            '/\.tm-page__opening\s*\{[^}]*flex-direction:\s*column/s',
            $this->css(),
            'the opening is no longer a column flex container, which would change the rule '
            .'that makes the stage\'s width load-bearing'
        );
    }

    /**
     * A comment in this stylesheet must not contain a brace.
     *
     * Legal CSS, and the stage shipped looking broken because of an adjacent
     * mistake -- but a `}` inside a comment terminates every naive brace-matching
     * reader of this file, which is how a build gets "verified" against a rule that
     * was never actually parsed. Comments are prose; prose does not need braces.
     */
#[Test]
    public function no_comment_in_the_stylesheet_contains_a_brace(): void
    {
        preg_match_all('~/\*.*?\*/~s', $this->css(), $comments);

        $this->assertNotEmpty($comments[0], 'the stylesheet has no comments, which cannot be right');

        foreach ($comments[0] as $comment) {
            $this->assertDoesNotMatchRegularExpression(
                '~[{}]~',
                $comment,
                'a CSS comment contains a brace, which breaks any tool that reads this '
                .'stylesheet by matching braces. Comment: '
                .trim(preg_replace('/\s+/', ' ', $comment) ?? '')
            );
        }
    }

    /**
     * THE STAGE IS ON THE DESTINATION PAGE AND NOWHERE ELSE.
    /**
     * THE STAGE IS ON THE DESTINATION PAGE AND NOWHERE ELSE.
     *
     * Asserted in BOTH directions, because the obvious way to "share the stage"
     * later is to add one line to `destinations/index.blade.php` and the only
     * thing that would notice is this.
     *
     * The reason it is one route and not two: the stage opens on the destination
     * you have ARRIVED at, with that destination's own photographs in the fan. On
     * the listing it would march 65 destinations' photographs past above a grid of
     * 9 of the same destinations, and its chevrons -- which navigate to the next
     * destination's page -- would be taking you away from the one page whose
     * whole purpose is to let you choose between them.
     */
    #[Test]
    public function the_stage_is_on_the_destination_page_and_nowhere_else(): void
    {
        $this->destination(['name' => 'Alpha', 'slug' => 'alpha', 'sort_order' => 1]);
        $second = $this->destination(['name' => 'Bravo', 'slug' => 'bravo', 'sort_order' => 2]);

        /*
         * A SECOND PHOTOGRAPH ON THIS DESTINATION, and not merely a second
         * destination. The stage holds one destination's own photographs, so
         * "Bravo" needs an upload for its stage to have more than one slide: a
         * pager, dots and chevrons are all correctly absent from a
         * one-photograph stage, and asserting them here would be asserting a bug.
         */
        $this->photograph($second, 'https://images.example.com/bravo-1.jpg');

        $show = $this->dom(
            (string) $this->get(route('destinations.show', $second))->assertOk()->getContent()
        );

        $this->assertSame(1, $show->query('//*[@data-stage]')->length);
        $this->assertSame(1, $show->query('//*[@data-stage-pager]')->length);
        $this->assertSame(1, $show->query('//script[@data-stage-data]')->length);

        // Two destinations, so there is more than one slide and a pager at all. A
        // one-slide stage correctly has neither, and asserting one would be
        // asserting a bug.
        $this->assertGreaterThan(1, $show->query('//*[@data-stage-panel]')->length);

        $index = $this->dom(
            (string) $this->get(route('destinations.index'))->assertOk()->getContent()
        );

        foreach ([
            '//*[@data-stage]',
            '//*[@data-stage-panel]',
            '//*[@data-stage-pager]',
            '//script[@data-stage-data]',
        ] as $selector) {
            $this->assertSame(
                0,
                $index->query($selector)->length,
                'the stage has appeared on /destinations ('.$selector.'). It belongs to the '
                .'destination page only.'
            );
        }

        /*
         * The listing is still a listing. Asserting only the stage's ABSENCE would
         * pass just as happily on a page that lost its search form and its grid.
         */
        $this->assertGreaterThan(
            0,
            $index->query('//input[@name="search"]')->length,
            'the listing has lost its search field'
        );

        $this->assertGreaterThan(
            0,
            $index->query('//article')->length,
            'the listing has lost its destination cards'
        );
    }

    // =====================================================================
    // The page composition. ONE dark surface, edge to edge.
    // =====================================================================

    /**
     * THE WHOLE PAGE IS ONE DARK SURFACE, WITH THE STAGE AS ITS OPENING.
     *
     * Asserted as DOCUMENT ORDER, because order is the composition: a stage that
     * is correctly coloured but sitting below the map is still the wrong page.
     *
     * The heading is **Island White**, and that is the load-bearing detail. It
     * was Deep Volcanic Teal, because it was on Palawan Sand. Deep Volcanic Teal
     * on Deep Volcanic Teal is 1:1 and the heading simply disappeared when the
     * ground changed -- which is why this page's colour rule had to be re-derived
     * rather than copied from the stage.
     */
    #[Test]
    public function the_whole_page_is_one_dark_surface_with_the_stage_as_its_opening(): void
    {
        $html = (string) $this->get(route('destinations.show', $this->destination()))
            ->assertOk()
            ->getContent();

        $dom = $this->dom($html);

        $this->assertSame(
            1,
            $dom->query('//main//*[contains(concat(" ", normalize-space(@class), " "), " tm-page ")]')->length,
            'the page has no .tm-page ground, so it is still a paper page with a dark panel on it'
        );

        /*
         * One surface means no sand anywhere inside the page.
         */
        $this->assertSame(
            0,
            $dom->query('//main//*[contains(@class, "bg-palawan-sand")]')->length,
            'Palawan Sand still appears on the destination page. The stage became the whole '
            .'page and the paper went with it.'
        );

        $opening = $dom->query('//*[contains(concat(" ", normalize-space(@class), " "), " tm-page__opening ")]');

        $this->assertSame(1, $opening->length, 'the page has no opening screen to hold the stage');

        $heading = $dom->query('//main//h1')->item(0);
        $stage = $dom->query('//*[@data-stage]')->item(0);
        $facts = $dom->query('//main//p[contains(normalize-space(.), "01 / The place")]')->item(0);

        foreach ([$heading, $stage, $facts] as $node) {
            $this->assertInstanceOf(\DOMElement::class, $node);
        }

        $this->assertSame(1, $dom->query('//main//h1')->length, 'the page has more than one h1');

        /*
         * `compareDocumentPosition` returns a BITMASK, so it is compared against
         * the flag rather than asserted true. `assertTrue(4)` fails, and reading
         * that as "the stage is above the heading" is a very plausible mistake.
         */
        $this->assertSame(
            \DOMNode::DOCUMENT_POSITION_FOLLOWING,
            $heading->compareDocumentPosition($stage) & \DOMNode::DOCUMENT_POSITION_FOLLOWING,
            'the stage is above the heading'
        );

        $this->assertSame(
            \DOMNode::DOCUMENT_POSITION_FOLLOWING,
            $stage->compareDocumentPosition($facts) & \DOMNode::DOCUMENT_POSITION_FOLLOWING,
            'the facts are above the stage. The stage is the opening, not the footer.'
        );

        $headingClasses = (string) $heading->getAttribute('class');

        $this->assertStringContainsString('text-island-white', $headingClasses);
        $this->assertStringNotContainsString(
            'text-volcanic-teal',
            $headingClasses,
            'the heading is Deep Volcanic Teal on a Deep Volcanic Teal page, which is 1:1'
        );

        $this->assertStringContainsString('font-display', $headingClasses);

        // The stage lives INSIDE the opening screen, not beside it.
        $this->assertSame(
            1,
            $dom->query('.//*[@data-stage]', $opening->item(0))->length,
            'the stage has drifted out of the opening screen'
        );

        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/\.tm-page\s*\{[^}]*background-color:\s*var\(--color-volcanic-teal\)/s',
            $css,
            'the page ground is not the Deep Volcanic Teal the composition is built on'
        );

        /*
         * `min-height`, never `height`. The stage's height follows from `--u` and
         * the composition's aspect ratio, so a fixed `height` would crush the fan
         * on a short viewport instead of letting the opening grow past it. `svh`
         * rather than `vh` because mobile browser chrome changes the viewport
         * height while you scroll.
         */
        $this->assertMatchesRegularExpression(
            '/\.tm-page__opening\s*\{[^}]*min-height:\s*calc\(100svh/s',
            $css,
            'the opening screen is not full-height'
        );

        /*
         * Scoped to `main`, and to non-hover tokens. The layout's mobile nav is
         * `max-w-[calc(100vw-2.5rem)]` and the page's one filled button hovers to
         * `hover:bg-island-white`; both are outside what this guard is about, and a
         * blanket string search would have had to be wrong about them to be useful.
         */
        $this->assertSame(
            0,
            $dom->query('//main//*[contains(@class, "100vw")]')->length,
            'a viewport unit is back on the destination page, and it measures the viewport '
            .'INCLUDING the vertical scrollbar'
        );
    }

    /**
     * NO DARK-PALETTE TEXT SURVIVES ON THE TEAL GROUND.
     *
     * The page inverted its colour rule when the ground changed. Gold and Boracay
     * Turquoise were forbidden as small text because they fail on SAND; on teal
     * they are the accent colours. What must not appear is the DARK palette --
     * `text-benguet-charcoal` and bare `text-volcanic-teal` on the ground are
     * both effectively invisible.
     *
     * Deep Volcanic Teal as text IS legal in one place, on a filled button:
     * turquoise fill with teal text is the pairing the palette asks for and it
     * passes. So the guard is "dark text only on a fill", NOT a blanket ban -- a
     * blanket ban would forbid the one legal use.
     */
    #[Test]
    public function no_dark_palette_text_survives_on_the_teal_ground(): void
    {
        $destination = $this->destination();
        $destination->tags()->attach(Tag::create(['name' => 'Waterfalls', 'slug' => 'waterfalls']));

        $html = (string) $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->getContent();

        $dom = $this->dom($html);

        /*
         * Scoped to `main`. The layout's flash banner is `text-benguet-charcoal`
         * on a pale green panel and is shared by every page in the project; this
         * guard is about the destination page's own surface, and a global assert
         * would have to be wrong about the layout to be useful.
         */
        $this->assertSame(
            0,
            $dom->query('//main//*[contains(@class, "text-benguet-charcoal")]')->length,
            'Benguet Charcoal text is left on a Deep Volcanic Teal page, where it is about 1.3:1'
        );

        foreach ($dom->query('//main//*[contains(@class, "text-volcanic-teal")]') as $element) {
            $this->assertMatchesRegularExpression(
                '/bg-\S/',
                (string) $element->getAttribute('class'),
                'Deep Volcanic Teal text on an element with no fill behind it is invisible on '
                .'this ground. It is only legal on a filled button.'
            );
        }

        /*
         * Token-by-token rather than a substring search, so a legitimate
         * `hover:bg-island-white` on the one filled button is not mistaken for the
         * opaque light card this is looking for. A hover state is a filled button
         * changing fill; a resting `bg-island-white` is a bright rectangle in the
         * middle of a dark page.
         */
        foreach (['bg-palawan-sand', 'bg-island-white'] as $fill) {
            foreach ($dom->query('//main//*[@class]') as $element) {
                foreach (preg_split('/\s+/', trim((string) $element->getAttribute('class'))) as $token) {
                    if ($token === $fill) {
                        $this->fail(
                            $fill.' is left on the destination page as a resting fill. An opaque '
                            .'light card in the middle of a dark page is the flat colour block '
                            .'the stage forbids.'
                        );
                    }
                }
            }
        }

        $this->assertStringContainsString(
            '.tm-gold-badge--dark',
            $this->css(),
            'the dark rating-badge modifier is not in the stylesheet'
        );
    }

    /**
     * THE FILTERED BAR IS TRACKED UPPERCASE LABELS IN A LIGHT COLOUR.
     *
     * Identified by its content rather than its position, because the layout's
     * navigation contains lists too and asserting on "any ul in main" would check
     * their colours and call it a pass.
     *
     * NOT gold, even though gold is legal on this ground and is used for the
     * accents below: a decorative label is the one place a colour has no job, and
     * spending the accent there devalues it everywhere else.
     */
    #[Test]
    public function the_filtered_bar_is_tracked_uppercase_labels_in_a_light_colour(): void
    {
        $destination = $this->destination();
        $destination->tags()->attach(Tag::create(['name' => 'Waterfalls', 'slug' => 'waterfalls']));

        $dom = $this->dom(
            (string) $this->get(route('destinations.show', $destination))->assertOk()->getContent()
        );

        $bars = $dom->query('//main//ul[li[contains(normalize-space(.), "Waterfalls")]]');

        $this->assertSame(1, $bars->length, 'the destination has a tag but no filtered bar rendered it');

        $markup = (string) $bars->item(0)->ownerDocument->saveHTML($bars->item(0));

        $this->assertStringContainsString('uppercase', $markup);
        $this->assertStringContainsString('tracking-', $markup);
        $this->assertStringContainsString(
            'text-boracay-light',
            $markup,
            'the filtered bar is not in a light colour, so it is invisible on the teal ground'
        );

        $this->assertStringNotContainsString('text-philippine-gold', $markup);
    }

    /**
     * EVERY FACT THE PAGE HAS ALWAYS SHOWN IS STILL HERE.
     *
     * Written as a list on purpose. The page was restyled from a paper one to a
     * dark one, and a restyle is exactly when a section quietly stops rendering:
     * "assert the description is present" passes just as happily on a page that
     * lost its fee, its map or its review form. Each entry is one the page has
     * always carried, so dropping one in a future restyle fails here.
     */
    #[Test]
    public function every_fact_the_page_carries_is_still_here(): void
    {
        $destination = $this->destination([
            'name' => 'Caliraya Lake',
            'description' => 'A reservoir in the Sierra Madre. The boat leaves at dawn.',
            'entrance_fee' => 150,
            'estimated_cost' => 1250,
            'recommended_minutes' => 120,
            'opening_time' => '08:00',
            'closing_time' => '17:00',
            'daily_hours' => [
                'monday' => ['open' => '08:00', 'close' => '17:00'],
                'tuesday' => ['open' => '08:00', 'close' => '17:00'],
                'wednesday' => ['open' => '08:00', 'close' => '17:00'],
                'thursday' => ['open' => '08:00', 'close' => '17:00'],
                'friday' => ['open' => '08:00', 'close' => '17:00'],
                'saturday' => ['open' => '06:00', 'close' => '17:00'],
                'sunday' => ['open' => '06:00', 'close' => '17:00'],
            ],
            'hours_note' => 'The gate closes early on public holidays.',
            'hours_source_url' => 'https://example.gov.ph/caliraya',
            'hours_source_label' => 'Caliraya LGU',
            'last_verified_at' => now(),
            'budget_level' => 'mid-range',
        ]);

        $destination->tags()->attach(Tag::create(['name' => 'Waterfalls', 'slug' => 'waterfalls']));

        $html = (string) $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->getContent();

        foreach ([
            'the name' => 'Caliraya Lake',
            'the municipality' => 'Caliraya',
            'the province' => 'Laguna',
            'an interest tag' => 'Waterfalls',
            'the prose' => 'The boat leaves at dawn.',
            'the entrance fee' => '₱150.00',
            'the estimated cost' => '₱1,250.00',
            'the visit length' => '120 min',
            'the hours label' => 'Opening hours',
            'a per-day row' => 'Monday',
            'the weekend split' => '06:00–17:00',
            'the hours note' => 'The gate closes early on public holidays.',
            'the timezone' => 'Philippine time (UTC+8)',
            'the last-checked date' => 'Last checked '.now()->format('j M Y'),
            'the cited hours source' => 'Caliraya LGU',
            'the location section' => '02 / Location',
            'the budget tier' => 'Mid-range',
            'the reviews section' => 'Traveler notes',
            'the empty reviews state' => 'No reviews yet.',
        ] as $fact => $text) {
            $this->assertStringContainsString(
                $text,
                $html,
                $fact.' is no longer on the destination page. The restyle dropped it.'
            );
        }

        $this->assertStringContainsString('href="https://example.gov.ph/caliraya"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $html);

        // The map, with its coordinates both on the element and printed beneath it.
        $this->assertSame(1, $this->dom($html)->query('//*[@data-destination-map]')->length);
        $this->assertStringContainsString('data-lat="14.1"', $html);
        $this->assertStringContainsString('14.1, 121.5', $html);

        // The actions.
        $this->assertStringContainsString('Find similar places', $html);
        $this->assertStringContainsString('Log in to leave a review.', $html);
        $this->assertMatchesRegularExpression(
            '/<form[^>]*method="POST"/',
            $html,
            'the review form has gone'
        );
    }
    // =====================================================================
    // Panels. Photographs, and only on the active one.
    // =====================================================================

    /**
     * Every panel is a real photograph, and the stage never falls back to a flat
     * colour block.
     *
     * A destination with no photograph is left out of the stage entirely rather
     * than given a placeholder -- see DestinationGalleryTest. This pins the other
     * half: that nothing in the fan renders without an image.
     */
    #[Test]
    public function every_panel_is_a_photograph_and_the_active_one_is_the_only_one_labelled(): void
    {
        $destination = $this->destination(['province' => 'Laguna']);

        $dom = $this->dom(
            (string) $this->get(route('destinations.show', $destination))->assertOk()->getContent()
        );

        $panels = $dom->query('//*[@data-stage-panel]');

        $this->assertGreaterThan(0, $panels->length);

        foreach ($panels as $panel) {
            $image = $dom->query('.//img[@data-src]', $panel);

            $this->assertSame(
                1,
                $image->length,
                'a stage panel has no photograph behind it, which is a flat colour block '
                .'wearing the composition of one'
            );

            $this->assertSame(
                '',
                $image->item(0)->getAttribute('alt'),
                'a panel photograph has alt text. The panel is a link named after the '
                .'destination and the caption states the same name directly above it, so alt '
                .'text announces it twice.'
            );
        }

        $this->assertSame(
            1,
            $dom->query('//*[@data-offset="0"]//*[contains(@class, "tm-fan-card__pill")]')->length,
            'the province pill is missing from the active panel'
        );

        foreach ($panels as $panel) {
            if ($panel->getAttribute('data-offset') !== '0') {
                $this->assertSame(
                    0,
                    $dom->query('.//*[contains(@class, "tm-fan-card__pill")]', $panel)->length,
                    'a side panel carries the province pill. Only the active panel is labelled; '
                    .'a label on all five reads as decoration.'
                );
            }
        }

        $css = $this->css();

        $this->assertStringContainsString(
            "rgb(244 180 26 / 0.7)",
            $css,
            'the active panel has no gold hairline at about 70%'
        );

        $this->assertMatchesRegularExpression(
            "/\[data-offset='0'\]\s+\.tm-fan-card\s*\{[^}]*244 180 26/s",
            $css,
            'the gold hairline is not scoped to the active panel, so all five panels carry it'
        );

        $this->assertMatchesRegularExpression(
            '/\[data-offset=\'-1\'\]\s+\.tm-fan-card__pill,.*?\[\[data-offset|\[data-offset=\'-1\'\]\s+\.tm-fan-card__scrim/s',
            $css,
            'the side panels are not dimmed beneath a teal overlay'
        );
    }

    /**
     * The fan's geometry is in the stylesheet, in the units the composition was
     * drawn in.
     *
     * Every number below is a stated part of the design and each one is a value
     * the page is visibly wrong without, so they are asserted rather than
     * described: the 320px panel width and its 9:16 ratio, the 300px and 532px
     * pushes, the 0.79 and 0.58 scales, and the 7 and 14 degree rotations.
     *
     * The two offsets are not eyeballed. The centre panel's half-width is 160 and
     * the flanking panel's scaled half-width is 126.4, so 300 leaves a 13.6px
     * gap; the outer panel's near edge at 532 is 443.2 and the flanking panel's
     * far edge is 426.4, a 12.8px gap. Both sit in the 10-16px band the
     * composition asks for. Move an offset and the gaps stop being gaps.
     */
    #[Test]
    public function the_fan_geometry_is_the_composition_in_the_stylesheet(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/\.tm-fan-panel\s*\{[^}]*width:\s*calc\(320 \* var\(--u\)\)/s',
            $css,
            'the centre panel is not 320 units wide'
        );

        $this->assertMatchesRegularExpression(
            '/\.tm-fan-panel\s*\{[^}]*aspect-ratio:\s*9 \/ 16/s',
            $css,
            'the panels are not 9:16 portraits, which is what makes 320 wide come out at 570 tall'
        );

        $this->assertMatchesRegularExpression(
            '/\[data-offset=\'-1\'\]\s*\{[^}]*left:\s*calc\(50% - \(300 \* var\(--u\)\)\)/s',
            $css
        );

        $this->assertMatchesRegularExpression(
            '/\[data-offset=\'-2\'\]\s*\{[^}]*left:\s*calc\(50% - \(532 \* var\(--u\)\)\)/s',
            $css,
            'the outer pair is not pushed out far enough, which either closes the gap or '
            .'uncrops it'
        );

        foreach (['0.79', '0.58', 'rotateY(-7deg)', 'rotateY(7deg)', 'rotateY(-14deg)', 'rotateY(14deg)'] as $value) {
            $this->assertStringContainsString($value, $css, 'the fan is missing '.$value);
        }

        /*
         * The rotation sign must MIRROR. A same-sign rotation on both sides tilts
         * the fan into a ">" instead of a shallow V, so one panel's outer edge
         * comes toward the viewer at exactly the moment the other is meant to fall
         * away.
         */
        $this->assertMatchesRegularExpression(
            '/\[data-offset=\'1\'\]\s*\{[^}]*rotateY\(7deg\)/s',
            $css,
            'the right-hand panels do not rotate the other way from the left-hand ones'
        );

        /*
         * The whole fan moves as ONE formation. A per-panel transition-delay is a
         * stagger, and a stagger here reads as five cards animating rather than as
         * one object turning.
         */
        $this->assertStringNotContainsString(
            'transition-delay',
            $css,
            'something on the stage is staggered. The panels travel as one formation.'
        );

        $this->assertMatchesRegularExpression(
            '/\.tm-stage-wrap\s*\{[^}]*container-type:\s*inline-size/s',
            $css,
            'the stage is not a container, so `--u` has no container to resolve against'
        );
    }

    // =====================================================================
    // The caption. One slide, never two.
    // =====================================================================

    /**
     * EVERY caption field is read off ONE binding.
     *
     * This is the single most important guard in the file. Slides are photographs
     * grouped by destination, so two adjacent slides can be two different places
     * and the fan can be sitting on any of them. A caption assembled from "the
     * active panel" and "the previous active panel" would eventually show one
     * destination's name over another destination's peso figure, with both halves
     * rendering correctly and every test passing.
     *
     * Asserted by reading the caption's markup and requiring that its only
     * source of slide data is `active()`. The two numbers are the documented
     * exception: the script owns their textContent because it animates them, so
     * they carry `data-stage-value` instead and are asserted separately.
     */
    #[Test]
    public function every_caption_field_is_read_off_one_slide(): void
    {
        $caption = (string) file_get_contents(
            resource_path('views/components/destinations/stage-caption.blade.php')
        );

        // Strip the header comment so its prose about this rule cannot satisfy it.
        $caption = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $caption);

        preg_match_all('/x-text="([^"]+)"/', $caption, $matches);

        $this->assertNotEmpty($matches[1], 'the caption has no bindings, so it would be frozen on the first slide');

        foreach ($matches[1] as $expression) {
            /*
             * `place()` is the one binding that does not read `active()` directly,
             * because it joins two fields with a separator. It is a pure function
             * of ONE slide, which is the property that matters -- so it is checked
             * at its definition rather than waved through by name.
             */
            if (trim($expression) === 'place()') {
                continue;
            }

            /*
             * ONE slide, read ONCE. Not an anchored match on `active().field` --
             * that would reject `'\u2014 ' + active().province`, which is a constant
             * joined to a single field and perfectly safe.
             *
             * A substring match is no better in the other direction: it accepts
             * `active().name + previous().name`, which is the exact bug expressed
             * in a way that looks correct in a template. So the invariant is
             * asserted directly -- the binding calls `active()` once, and reaches
             * for no other slide.
             */
            $this->assertSame(
                1,
                substr_count($expression, 'active()'),
                'a caption binding reads `'.$expression.'`, which does not read the active '
                .'slide exactly once. Two sources for one caption is how one destination ends '
                .'up named over another destination\'s money.'
            );

            foreach (['previous()', 'next()', 'panels[', 'slides['] as $other) {
                $this->assertStringNotContainsString(
                    $other,
                    $expression,
                    'a caption binding reads `'.$expression.'`, which reaches past the active '
                    .'slide for '.$other
                );
            }
        }

        $this->assertStringContainsString(
            'place()',
            $caption,
            'the caption no longer binds its place line, so that field is frozen on the '
            .'first slide'
        );

        /*
         * `place()` is checked at its definition rather than by name.
         */
        $this->assertMatchesRegularExpression(
            '/place\(\)\s*\{[^}]*const slide = this\.active\(\);/s',
            $this->script(),
            'place() no longer reads the active slide, so the caption\'s place line has a '
            .'source of its own and can disagree with the name beside it'
        );

        foreach (['data-stage-name', 'data-stage-province', 'data-stage-place', 'data-stage-standfirst', 'data-stage-budget'] as $hook) {
            $this->assertStringContainsString(
                $hook,
                $caption,
                $hook.' is gone from the caption, so that field can no longer be updated at all'
            );
        }
    }

    /**
     * The caption is a caption, never the page's heading.
     *
     * An `h1` in here would either duplicate the page's own heading or, worse,
     * become it and describe a photograph of somewhere the reader is not.
     */
    #[Test]
    public function the_caption_is_never_the_page_heading(): void
    {
        $caption = (string) file_get_contents(
            resource_path('views/components/destinations/stage-caption.blade.php')
        );

        $this->assertStringContainsString('<h2 data-stage-title', $caption);
        $this->assertStringNotContainsString('<h1', $caption);

        $dom = $this->dom(
            (string) $this->get(route('destinations.show', $this->destination()))
                ->assertOk()
                ->getContent()
        );

        $this->assertSame(1, $dom->query('//main//h1')->length);
    }

    /**
     * The standfirst and the prose block are a PAIR, so no sentence appears twice.
     *
     * The caption prints the description's first sentence; the body prints what
     * is left. Both directions are asserted, because the interesting failure is
     * the one-sided edit -- someone swaps `bodyDescription()` back to
     * `description` and the opening sentence appears twice within one screenful,
     * which no assertion about "is the description present" would notice.
     */
    #[Test]
    public function the_standfirst_and_the_body_description_split_the_description(): void
    {
        $destination = $this->destination([
            'description' => 'A reservoir in the Sierra Madre. The boat leaves at dawn.',
        ]);

        $this->assertSame('A reservoir in the Sierra Madre.', $destination->standfirst());
        $this->assertSame('The boat leaves at dawn.', $destination->bodyDescription());

        $html = (string) $this->get(route('destinations.show', $destination))->assertOk()->getContent();

        /*
         * Counted in the RENDERED TEXT, not in the raw HTML. The stage's payload
         * island is a `<script type="application/json">` holding every slide's
         * standfirst, so the sentence is legitimately in the document twice --
         * once as data, once as type. Counting raw HTML therefore fails a correct
         * page, and counting once would not catch a caption that printed the
         * whole description as well.
         */
        $rendered = (string) preg_replace('#<script\b[^>]*>.*?</script>#si', '', $html);

        $this->assertSame(
            1,
            substr_count($rendered, 'A reservoir in the Sierra Madre.'),
            'the standfirst sentence appears more than once in the rendered page. It belongs '
            .'over the photographs OR in the prose block, and printing it in both puts it on '
            .'screen twice within one screenful.'
        );

        $this->assertStringContainsString('The boat leaves at dawn.', $rendered);
        $this->assertStringNotContainsString('A reservoir in the Sierra Madre.', substr($rendered, (int) strrpos($rendered, '01 / The place')));
    }

    /**
     * A one-sentence description is NOT emptied out of the page.
     *
     * There is no standfirst to split off, so the caption simply has none and the
     * body keeps everything. The alternative -- returning the single sentence as
     * the standfirst too -- would remove it from the page entirely.
     */
    #[Test]
    public function a_one_sentence_description_is_never_split(): void
    {
        $destination = $this->destination(['description' => 'Only one sentence.']);

        $this->assertSame('', $destination->standfirst());
        $this->assertSame('Only one sentence.', $destination->bodyDescription());
    }

    // =====================================================================
    // Controls.
    // =====================================================================

    /**
     * There are exactly two backdrop layers, and only one is shown.
     *
     * A cross-fade needs two photographs: a single `<img>` whose `src` is swapped
     * can only be replaced, not cross-faded. One layer would make the vision's
     * 700-900ms backdrop transition a hard cut behind every panel.
     */
    #[Test]
    public function the_backdrop_has_two_layers_so_it_can_cross_fade(): void
    {
        $dom = $this->dom(
            (string) $this->get(route('destinations.show', $this->destination()))
                ->assertOk()
                ->getContent()
        );

        $backdrops = $dom->query('//img[@data-stage-backdrop]');

        $this->assertSame(
            2,
            $backdrops->length,
            'the stage needs exactly two backdrop layers to cross-fade between. One can only '
            .'be swapped, not cross-faded.'
        );

        $this->assertSame(
            1,
            $dom->query('//img[@data-stage-backdrop][contains(@class, "is-shown")]')->length,
            'the number of shown backdrop layers is not one, so the stage starts over one '
            .'photograph or none'
        );

        $this->assertMatchesRegularExpression(
            '/\.tm-stage__backdrop\s*\{[^}]*blur\((\d+)px\)/s',
            $this->css(),
            'the backdrop is not blurred'
        );

        preg_match('/\.tm-stage__backdrop\s*\{[^}]*blur\((\d+)px\)/s', $this->css(), $blur);

        $this->assertGreaterThanOrEqual(12, (int) $blur[1]);
        $this->assertLessThanOrEqual(16, (int) $blur[1]);
    }

    /**
     * The pause control exists, is reachable, and is not a dead button.
     *
     * Autoplay starts by itself and runs for longer than five seconds, which is
     * WCAG 2.2.2: moving content that moves without being asked must be
     * stoppable. The state is on `aria-pressed`, not inferred from the glyph, so
     * it is communicated rather than drawn.
     */
    #[Test]
    public function there_is_a_reachable_pause_control(): void
    {
        /*
         * A SECOND PHOTOGRAPH ON THIS ONE DESTINATION, which is now the only way a
         * stage gets more than one slide. Two destinations used to be enough,
         * because the stage fanned across all of them. It does not any more.
         */
        $destination = $this->destination();
        $this->photograph($destination, 'https://images.example.com/caliraya-1.jpg');

        $dom = $this->dom(
            (string) $this->get(route('destinations.show', $destination))
                ->assertOk()
                ->getContent()
        );

        $pause = $dom->query('//*[@data-stage-pause]');

        $this->assertSame(1, $pause->length, 'the pause control is missing. Autoplay with no way to stop it fails WCAG 2.2.2.');

        $button = $pause->item(0);

        $this->assertSame('button', $button->tagName);
        $this->assertSame('false', $button->getAttribute('aria-pressed'), 'the control does not report whether autoplay is running');
        $this->assertNotSame('', $button->getAttribute('aria-label'));

        // The glyph swap is CSS keyed off aria-pressed, not x-show.
        $this->assertStringContainsString(
            ".tm-stage__pause[aria-pressed='true'] [data-icon='pause']",
            $this->css(),
            'the pause/play glyph swap is not driven by aria-pressed. x-show would toggle the '
            .'hidden attribute, and with no x-cloak rule both glyphs paint until Alpine runs.'
        );
    }

/**
     * THE CHEVRONS MOVE THE FAN, and they stay mounted at both ends.
     *
     * They were links to a neighbouring DESTINATION, which was correct while the
     * stage fanned across the catalogue and is wrong now that it does not. A
     * chevron that navigates off the page you are reading, shaped exactly like the
     * controls that move the pictures in front of you, is a control that lies
     * about what it does -- and with the fan now holding one destination's own
     * photographs, "the next destination" is not a thing the fan is doing at all.
     *
     * RENDERED UNCONDITIONALLY rather than omitted at each end, which is the
     * opposite of what they used to do. Omission was right for a link whose target
     * did not exist. It is wrong for a button that disables: a keyboard user's
     * focus can be sitting on a control that is about to stop existing, and the
     * stage visibly rearranges itself every time it reaches an end. So the
     * buttons keep their position and carry `disabled` and `aria-hidden` from the
     * script instead.
     */
    #[Test]
    public function the_chevrons_move_the_fan_and_stay_mounted_at_both_ends(): void
    {
        $destination = $this->destination();

        $this->photograph($destination, 'https://images.example.com/bravo-1.jpg');
        $this->photograph($destination, 'https://images.example.com/bravo-2.jpg');

        $html = (string) $this->get(route('destinations.show', $destination))->assertOk()->getContent();
        $dom = $this->dom($html);

        $this->assertSame(2, $dom->query('//*[@data-stage-chevron]')->length);

        foreach (['prev', 'next'] as $side) {
            $chevron = $dom->query('//*[@data-stage-chevron="'.$side.'"]')->item(0);

            $this->assertNotNull($chevron, "the {$side} chevron is missing");

            $this->assertSame(
                'button',
                $chevron->tagName,
                "the {$side} chevron is not a button, so it cannot be the fan's own control"
            );

            $this->assertEmpty(
                $chevron->getAttribute('href'),
                "the {$side} chevron still points at a URL, so clicking it will navigate away "
                .'from the page the visitor is reading'
            );

            $this->assertStringContainsString(
                $side === 'prev' ? 'prev()' : 'next()',
                (string) $chevron->getAttribute('x-on:click'),
                "the {$side} chevron does not move the fan"
            );

            /*
             * Disabled AT THE ENDS, from the script, not by not rendering. The
             * opening slide is index 0, so prev must be inert on arrival and next
             * must be live.
             *
             * Asserted as the plain boolean it now is. This expression was
             * once `index === 0 ? true : count - 1` with its twin
             * `index >= 0 ? true : count`, and the test asserted THAT, so
             * the stage shipped two permanently disabled chevrons with the
             * suite green: `index >= 0` is always true, and `count - 1` is
             * a positive NUMBER, which Alpine treats as truthy when it is
             * bound to `disabled`. A truthy non-boolean disables a button.
             * The stage rendered two arrows and neither could be clicked.
             */
            $this->assertStringContainsString(
                $side === 'prev' ? 'index === 0' : 'index === count - 1',
                (string) $chevron->getAttribute('x-bind:disabled'),
                "the {$side} chevron is not disabled at the ends"
            );
        }

        $this->assertStringContainsString('tm-stage__chevron--prev', $html);
        $this->assertStringContainsString('tm-stage__chevron--next', $html);
    }

    /**
     * A ONE-PHOTOGRAPH DESTINATION HAS NO CHEVRONS AT ALL.
     *
     * Not disabled ones -- none. There is nowhere for them to go, a stage with two
     * dead arrows on it is worse than a stage without them, and this is the
     * normal state for a destination whose owner has uploaded nothing.
     */
    #[Test]
    public function a_one_photograph_destination_has_no_chevrons_and_no_pager(): void
    {
        $dom = $this->dom(
            (string) $this->get(route('destinations.show', $this->destination()))->assertOk()->getContent()
        );

        $this->assertSame(0, $dom->query('//*[@data-stage-chevron]')->length);
        $this->assertSame(0, $dom->query('//*[@data-stage-pager]')->length);

        $this->assertSame(
            1,
            $dom->query('//*[@data-stage-panel]')->length,
            'a one-photograph destination still gets its one panel'
        );
    }

    /**
     * A destination with several photographs has ONE set of controls.
     *
     * The chevrons walk destinations and the dots walk photographs, which is the
     * point of having both -- but only one of each may exist, or the visitor has
     * two different things called "next".
     */
    #[Test]
    public function a_destination_with_several_photographs_has_one_set_of_controls(): void
    {
        $destination = $this->destination();

        foreach (range(0, 2) as $order) {
            DestinationImage::create([
                'destination_id' => $destination->id,
                'path' => 'https://images.example.com/extra-'.$order.'.jpg',
                'sort_order' => $order,
            ]);
        }

        $dom = $this->dom(
            (string) $this->get(route('destinations.show', $destination))->assertOk()->getContent()
        );

        $this->assertSame(4, $dom->query('//*[@data-stage-panel]')->length);
        $this->assertSame(1, $dom->query('//*[@data-stage-pager]')->length);
        $this->assertSame(1, $dom->query('//*[@data-stage-pause]')->length);
        $this->assertGreaterThan(0, $dom->query('//*[@data-stage-dot]')->length);
    }

    // =====================================================================
    // Motion, and the path that is not motion.
    // =====================================================================

    /**
     * Under `prefers-reduced-motion` the fan flattens to a stack and keeps only
     * the cross-fade.
     *
     * The transforms are REMOVED rather than shortened: the fan's whole depth cue
     * is carried by them, so a 620ms arc at 200ms is still an arc and still
     * motion. Shortening the transition without removing the transform is the
     * failure here, because it looks like it was addressed.
     */
    #[Test]
    public function reduced_motion_flattens_the_fan_and_keeps_only_a_cross_fade(): void
    {
        $css = $this->css();

        /*
         * Sliced from the stage's own marker to the next section, rather than to the
         * next `}`. There are several `prefers-reduced-motion` blocks in this
         * stylesheet, and a naive capture picks up whichever came first -- which
         * for a while was `.tm-primary-button`, so the test was reading a rule
         * about buttons and passing on a stage that did nothing.
         */
        $start = strpos($css, 'REDUCED MOTION: A FLAT STACK');

        $this->assertIsInt($start, 'the stage has no reduced-motion note to find');

        $reduced = substr($css, $start, (int) strpos(substr($css, $start), '.tm-drag-rail') - 1);

        $this->assertMatchesRegularExpression(
            '/\.tm-fan-panel\s*\{[^}]*left:\s*50% !important/s',
            $reduced,
            'the panels are not collapsed onto the centre under reduced motion'
        );

        $this->assertMatchesRegularExpression(
            '/\.tm-fan-panel\s*\{[^}]*transform:\s*translate\(-50%, -50%\)\s*;/s',
            $reduced,
            'the arc transforms survive under reduced motion. They are removed, not shortened -- '
            .'a 620ms arc at 200ms is still an arc, and still motion.'
        );

        $this->assertMatchesRegularExpression(
            '/\.tm-fan-panel\s*\{[^}]*opacity:\s*0/s',
            $reduced,
            'under reduced motion every panel is fully opaque, so the incoming one simply '
            .'covers the outgoing one and the transition is decoration rather than a cross-fade'
        );

        $this->assertStringContainsString("[data-offset='0']", $reduced);

        /*
         * No slot may be hidden from the SCRIPT under reduced motion either. A slot
         * the script cannot see is one it steps onto, and the fan then looks frozen
         * for exactly the people who asked for it not to move. Opacity and
         * visibility are safe; display:none is not.
         */
        $this->assertStringNotContainsString(
            'display: none',
            $reduced,
            'something on the stage is display:none under reduced motion. A panel the script '
            .'still walks to is a panel that renders nothing.'
        );
    }

    // =====================================================================
    // The cache, which survives a deploy.
    // =====================================================================

    /**
     * The cached payload is ARRAYS OF SCALARS, and anything unexpected is a MISS
     * rather than an exception.
     *
     * The cache driver here is the database, entries are serialised, and they
     * survive a deploy. An object graph outlives the class that built it,
     * unserialises to `__PHP_INcomplete_Class`, and behind a strict return type
     * takes the page down with a 500 instead of costing one rebuild.
     *
     * That is not hypothetical: it is what happened when this stage shipped with
     * objects in the cache.
     */
    #[Test]
    public function an_unexpected_cache_payload_is_a_miss_and_not_an_error(): void
    {
        $this->destination();

        $key = 'destinations.stage.v2';

        foreach ([
            'an object graph from an older version' => serialize(new \stdClass()),
            'a payload missing its keys' => ['slides' => [['slug' => 'x']], 'destinations' => []],
            'a bare string' => 'not a payload',
        ] as $label => $poison) {
            Cache::put($key, $poison, now()->addMinutes(10));

            $this->get(route('destinations.show', Destination::firstOrFail()))
                ->assertOk();

            $this->assertIsArray(
                $this->stage()->all(),
                $label.' was served to the page instead of being rebuilt'
            );
        }
    }

    /**
     * A destination AND a photograph both invalidate the cached order.
     *
     * Two hooks, because they are two different tables. Without the first, an
     * archived destination stays in both fans for ten minutes while its own page
     * 404s -- so a visitor can click straight from a stage panel onto a 404.
     * Without the second, an admin uploads a photograph, sees it on the
     * destination's page, and the stage does not show it for ten minutes.
     */
    #[Test]
    public function both_a_destination_and_a_photograph_invalidate_the_cached_order(): void
    {
        $destination = $this->destination(['is_active' => false]);

        $this->get(route('destinations.show', $destination->fresh()))->assertNotFound();

        $this->assertSame(
            [],
            $this->stage()->all(),
            'an archived destination is still in the stage, so a visitor can click a panel '
            .'onto a page that 404s'
        );

        $active = $this->destination([
            'name' => 'Bravo',
            'slug' => 'bravo',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $this->assertCount(1, $this->stage()->all());

        DestinationImage::create([
            'destination_id' => $active->id,
            'path' => 'https://images.example.com/new.jpg',
            'sort_order' => 0,
        ]);

        $this->assertCount(
            2,
            $this->stage()->all(),
            'an uploaded photograph did not reach the stage, so an admin uploads a picture, '
            .'sees it on the destination page, and the stage does not'
        );
    }

    /**
     * `is_featured` is CURATION and `is_active` is PUBLISHING. Do not merge them.
     *
     * An archived destination leaves the stage AND 404s. An un-featured one keeps
     * its page and is merely not in the fan. Collapsing them would mean a curation
     * change hides a destination from the public entirely.
     */
    #[Test]
    public function un_featured_is_not_the_same_as_archived(): void
    {
        $this->destination(['name' => 'Alpha', 'slug' => 'alpha', 'sort_order' => 1]);

        $hidden = $this->destination([
            'name' => 'Hidden',
            'slug' => 'hidden',
            'sort_order' => 2,
            'is_featured' => false,
        ]);

        $this->assertCount(1, $this->stage()->all());

        $this->get(route('destinations.show', $hidden))
            ->assertOk()
            ->assertSee('Hidden');
    }

    // =====================================================================
    // The score, which only some visitors have.
    // =====================================================================

    /**
     * A guest sees no match score, and no empty one either.
     *
     * A match score means "how well this fits YOUR travel profile", and there is
     * no profile behind an anonymous request. The honest answer is to omit the
     * label -- not to print a zero, and not to compute a score for someone.
     */
    #[Test]
    public function a_guest_is_shown_no_match_score(): void
    {
        $destination = $this->destination();

        $html = (string) $this->get(route('destinations.show', $destination))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-stage-metric="match"', $html);

        /*
         * The separator that FOLLOWS the score has to go with it, or the row reads
         * "· EST. COST ₱450" to a visitor who has no score at all.
         */
        $this->assertStringNotContainsString('data-stage-separator="match"', $html);

        // The rest of the row is still there, because the cost and the tier do not
        // depend on who is looking.
        $this->assertStringContainsString('data-stage-metric="cost"', $html);
        $this->assertStringContainsString('data-stage-metric="budget"', $html);
    }

    /**
     * A signed-in traveller with a profile SEES their score, in gold.
     *
     * And it is the same number the recommendations page shows, because it is
     * read from `RecommendationService` rather than reimplemented. A second
     * implementation would drift, and the stage would quote a figure nothing else
     * on the site agrees with.
     */
    #[Test]
    public function a_signed_in_traveller_is_shown_their_match_score(): void
    {
        $user = User::factory()->create();

        $profile = TravelProfile::create([
            'user_id' => $user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 2,
        ]);

        $waterfalls = Tag::create(['name' => 'Waterfalls', 'slug' => 'waterfalls']);

        $destination = $this->destination(['budget_level' => 'economy']);
        $destination->tags()->attach($waterfalls);
        $profile->tags()->attach($waterfalls, ['weight' => 3]);

        DestinationSwipe::create([
            'user_id' => $user->id,
            'destination_id' => $destination->id,
            'action' => 'liked',
        ]);

        $dom = $this->dom(
            (string) $this->actingAs($user)
                ->get(route('destinations.show', $destination))
                ->assertOk()
                ->getContent()
        );

        $score = $dom->query('//*[@data-stage-number="match"]');

        $this->assertSame(1, $score->length, 'a traveller with a profile and a liked destination is shown no match score');
        $this->assertSame('100', trim((string) $score->item(0)->textContent));
        $this->assertStringContainsString(
            'tm-stage__metric-value--gold',
            (string) $score->item(0)->getAttribute('class'),
            'the match score is not in gold'
        );

        $this->assertSame(
            1,
            $dom->query('//*[@data-stage-separator="match"]')->length,
            'the score rendered without the separator that follows it'
        );
    }

    /**
     * The budget tier is a WORD plus an indicator, never an indicator alone.
     *
     * Nothing is communicated by position or colour: the tier's meaning is in
     * the word, and the three-step indicator only ranks it.
     */
    #[Test]
    public function the_budget_tier_is_a_word_and_the_indicator_is_decorative(): void
    {
        $dom = $this->dom(
            (string) $this->get(route('destinations.show', $this->destination(['budget_level' => 'mid-range'])))
                ->assertOk()
                ->getContent()
        );

        $word = $dom->query('//*[@data-stage-budget]');

        $this->assertSame(1, $word->length);
        $this->assertSame('Mid-range', trim((string) $word->item(0)->textContent));

        $steps = $dom->query('//*[@data-stage-budget-steps]');

        $this->assertSame(1, $steps->length);
        $this->assertSame('true', $steps->item(0)->getAttribute('aria-hidden'), 'the indicator is in the accessibility tree, so a screen reader reads three dots as a tier');
        $this->assertSame(3, $dom->query('//*[@data-stage-budget-step]')->length);
    }
}
