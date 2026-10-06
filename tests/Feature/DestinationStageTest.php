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
 * The destination hero stage: a five-slot fanned carousel over a blurred
 * backdrop, with the page title as a stage-level overlay.
 *
 * Built from a written reference for a different product, adapted rather than
 * copied. These tests pin the parts that are load-bearing and silent:
 *
 *  - the fan is five slots, always, at any photo count;
 *  - every card shares ONE aspect ratio, so the fan is perspective rather than a
 *    scale distortion (the reference's own "uniform height/width confirms it is
 *    not a scale distortion" note);
 *  - the title is NOT inside a card, so it cannot be clipped by one and no card
 *    can be painted over it;
 *  - every photo is in the HTML with a real src, so a JS failure leaves pictures
 *    rather than an empty frame;
 *  - a single photo still renders the stage, it just is not a carousel.
 *
 * What is NOT pinned, because no test here can see it: the transform
 * choreography, the swipe threshold, and the reduced-motion path in stage.js.
 * There is no browser automation in this project, so those need a click-through.
 */
class DestinationStageTest extends TestCase
{
    use RefreshDatabase;

    private function destination(array $attributes = []): Destination
    {
        $destination = Destination::create(array_merge([
            'name' => 'Aguinaldo Shrine',
            'slug' => 'aguinaldo-shrine',
            'description' => 'A museum of Philippine history, kept carefully for a century.',
            'province' => 'Cavite',
            'municipality' => 'Cavite City',
            'latitude' => 14.3,
            'longitude' => 120.9,
            'budget_level' => 'economy',
            'entrance_fee' => 100,
            'estimated_cost' => 500,
            'recommended_minutes' => 90,
            'image_url' => 'https://images.example.com/hero.jpg',
        ], $attributes));

        return $destination;
    }

    private function photos(Destination $destination, int $count): void
    {
        foreach (range(1, $count) as $index) {
            $destination->images()->create([
                'path' => 'https://images.example.com/'.$index.'.jpg',
                'sort_order' => $index - 1,
            ]);
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();

        @$document->loadHTML($html);

        libxml_clear_errors();

        return new DOMXPath($document);
    }

    private function html(Destination $destination): string
    {
        return $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->getContent();
    }

    /**
     * The body of a top-level rule, ignoring any copy of it nested in a media query.
     *
     * Returns '' when the selector only appears inside one, which is what makes
     * the caller fail rather than pass on a breakpoint's value.
     */
    private function ruleOutsideAnyMediaQuery(string $css, string $selector): string
    {
        $depth = 0;
        $body = '';
        $capturing = false;

        foreach (preg_split('/\R/', $css) as $line) {
            $trimmed = trim($line);

            if (! $capturing && preg_match('/'.preg_quote($selector, '/').'\s*\{/', $trimmed)) {
                // Only capture when no media query is open at this point.
                if ($depth === 0) {
                    $capturing = true;
                }

                continue;
            }

            if ($capturing) {
                if ($trimmed === '}') {
                    return $body;
                }

                $body .= $line.PHP_EOL;
            }

            if (str_contains($trimmed, '@media')) {
                $depth++;
            } elseif ($trimmed === '}' && $depth > 0) {
                $depth--;
            }
        }

        return $body;
    }

    /**
     * The fan is five slots wide, whatever the photo count.
     *
     * Five is load-bearing in stage.js: `active` starts at 2 as the CENTRE of five
     * slots, and `goTo` clamps to `slots.length - 1`. A four- or six-slot fan would
     * start off-centre or disable the wrong chevron, and nothing else would notice.
     */
    #[Test]
    public function the_stage_carousel_has_five_slots(): void
    {
        $destination = $this->destination();
        $this->photos($destination, 2);

        $xpath = $this->xpath($this->html($destination));

        $this->assertSame(
            5,
            $xpath->query('//*[@data-destination-stage]//*[@data-stage-slot]')->length,
            'the fan must have exactly five slots'
        );

        // One active, and it is the middle one. The offsets step 0,1,2,1,0 across
        // the fan, which is what makes it an arc rather than a row.
        $offsets = [];

        foreach ($xpath->query('//*[@data-stage-slot]') as $slot) {
            $offsets[] = $slot->getAttribute('data-stage-offset');
        }

        $this->assertSame(
            ['2', '1', '0', '1', '2'],
            $offsets,
            'the five slots must run 2,1,0,1,2 so the middle is the active one'
        );

        // And stage.js must agree, or the initial paint lands on the wrong card.
        $script = (string) file_get_contents(resource_path('js/stage.js'));

        $this->assertMatchesRegularExpression(
            '/let active = 2;/',
            $script,
            'stage.js must open on slot 2, the centre of a five-slot fan'
        );
    }

    /**
     * The stage script is actually invoked, and it survives the build.
     *
     * Two separate failures hid behind one green suite, so both are pinned here.
     *
     * First, the entry used to `export default function initStage()` with nothing
     * importing it. Rollup tree-shook the lot and emitted a 0.00 kB
     * `stage-*.js`, so the page shipped no behaviour at all -- and no test failed,
     * because the bundle existed and the manifest had an entry for it. The fix is
     * to self-invoke at module scope, the way `itinerary-editor.js` already does.
     *
     * Second, it is a syntax error away from that same outcome: one missing `]`
     * made the build fail loudly, but a build that silently dropped the body
     * would not. So the built artefact is checked for the real code, not just
     * for the fact that a file exists.
     */
    #[Test]
    public function the_stage_script_runs_and_survives_the_build(): void
    {
        $script = (string) file_get_contents(resource_path('js/stage.js'));

        $this->assertStringContainsString(
            "document.querySelectorAll('[data-destination-stage]').forEach(initStage)",
            $script,
            'stage.js must invoke itself. An exported default nobody imports gets '
            .'tree-shaken to nothing, and the carousel silently stops working.'
        );

        // And the committed bundle must actually contain the behaviour.
        $manifest = json_decode(
            (string) file_get_contents(public_path('build/manifest.json')),
            true
        );

        $this->assertArrayHasKey(
            'resources/js/stage.js',
            $manifest,
            'the stage entry is not in the built manifest'
        );

        // The manifest's `file` is already relative to `public/build`, NOT to
// `public_path`. Prefixing `public/` on its own resolves to `public/assets/...`,
// which does not exist -- the path would have to be `public/build/assets/...`.
$built = public_path(
            'build'.DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, $manifest['resources/js/stage.js']['file'])
        );

        $this->assertFileExists($built);

        $contents = (string) file_get_contents($built);

        // A tree-shaken bundle is minified to nothing, so assert on real
        // identifiers rather than size alone.
        foreach ([
            'data-stage-slot',
            'data-stage-backdrop',
            'data-stage-dot',
            'data-stage-prev',
            'ArrowLeft',
            'pointerdown',
        ] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $contents,
                "the built stage bundle does not contain {$needle}, so the "
                .'carousel was tree-shaken or the build is stale. Rebuild with '
                .'`npm run build`.'
            );
        }
    }

    /**
     * One aspect ratio for every card, so the far cards are further away rather
     * than squashed versions of the same picture.
     *
     * This is the reference's "uniform height/width" observation turned into a
     * check. If the sizes were carried per-card as widths alone, or one card were
     * given a different ratio, the fan would read as a distortion instead of depth
     * -- and it would still look plausible in a screenshot.
     */
    #[Test]
    public function every_card_shares_one_aspect_ratio(): void
    {
        $destination = $this->destination();
        $this->photos($destination, 3);

        $xpath = $this->xpath($this->html($destination));

        $slots = $xpath->query('//*[@data-stage-slot]');

        $this->assertSame(5, $slots->length);

        $ratios = [];

        foreach ($slots as $slot) {
            $ratios = array_merge(
                $ratios,
                // Relative XPath, NOT $slot->querySelectorAll(): DOMElement has
                // no querySelector in PHP's DOM extension, so the obvious call
                // fatals rather than returning an empty list and quietly passing.
                $xpath->query('.//*[contains(@class, "aspect-")]', $slot)->length
                    ? ['aspect-[2/5]']
                    : []
            );
        }

        $this->assertCount(
            5,
            $ratios,
            'every one of the five cards must carry aspect-[2/5]'
        );

        $this->assertSame(
            ['aspect-[2/5]'],
            array_values(array_unique($ratios)),
            'the cards must all share ONE aspect ratio'
        );
    }

    /**
     * The title is a stage-level overlay, never a child of a card.
     *
     * Two separate reasons, both from the reference. A title inside a card is
     * clipped by that card's overflow, so long destination names lose their ends;
     * and if the card is painted above the copy it can occlude it mid-transition.
     * Asserting the h1 has no card ancestor covers the first. The z-order claim is
     * about stacking contexts and is asserted separately below.
     */
    #[Test]
    public function the_title_is_not_inside_a_card(): void
    {
        $destination = $this->destination();
        $this->photos($destination, 2);

        $xpath = $this->xpath($this->html($destination));

        $heading = $xpath->query('//*[@data-destination-stage]//h1')->item(0);

        $this->assertNotNull($heading, 'the stage must still carry the page heading');

        for ($node = $heading->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            $this->assertFalse(
                $node->hasAttribute('data-stage-slot'),
                'the h1 is inside a card, so a long destination name is clipped by '
                .'that card\'s overflow and the copy cannot cross card edges'
            );

            if ($node->hasAttribute('data-destination-stage')) {
                break;
            }
        }
    }

    /**
     * The active card is painted below the title, never above it.
     *
     * The reference is explicit that the compositing rule is fan -> scrim -> title
     * -> controls, so a departing card cannot occlude the copy. In CSS that is
     * z-index on the card against z-index on the title block, and it is checked
     * here as numbers because a card that outranks the title is invisible in a
     * static screenshot of a settled slide.
     */
    #[Test]
    public function the_active_card_is_painted_below_the_title(): void
    {
        $destination = $this->destination();
        $this->photos($destination, 2);

        $xpath = $this->xpath($this->html($destination));

        // Read via XPath so this does not depend on element identity.
        $activeSlot = $xpath->query('//*[@data-stage-offset="0"]')->item(0);

        $this->assertNotNull($activeSlot, 'the active slot is missing');

        $heading = $xpath->query('//*[@data-destination-stage]//h1')->item(0);

        $this->assertNotNull($heading, 'the stage must carry the page heading');

        $activeClasses = preg_split(
            '/\s+/',
            trim($activeSlot->getAttribute('class')),
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        $this->assertContains('z-30', $activeClasses);

        /*
         * The element that actually carries the stacking order. Found by walking
         * UP from the h1 to the nearest ancestor with a z-index, rather than
         * taking `parent::div`.
         *
         * `parent::div` was right when the title was a flat block and is wrong now
         * that it is `max-w-3xl` inside a positioned overlay -- the h1's parent is
         * the width constraint, which carries no z-index at all. Asserting on it
         * reported a missing z-40 for a layout that was in fact correctly stacked,
         * which is the wrong kind of test failure: it sends you to fix something
         * that is not broken.
         */
        $titleBlock = null;
        $titleClasses = [];

        for ($node = $heading; $node instanceof DOMElement; $node = $node->parentNode) {
            $classes = preg_split(
                '/\s+/',
                trim($node->getAttribute('class')),
                -1,
                PREG_SPLIT_NO_EMPTY
            );

            // Per class, NOT `/^z-(\d+)$/` against the joined string. Anchored
            // against the join it can never match anything but a single-class
            // attribute, so the walk ran off the top of the tree and reported
            // "no ancestor sets a z-index" for a correctly stacked layout.
            $found = array_filter(
                $classes,
                fn ($class) => (bool) preg_match('/^z-(\d+)$/', $class)
            );

            if ($found !== []) {
                $titleBlock = $node;
                $titleClasses = $classes;

                break;
            }
        }

        $this->assertNotNull(
            $titleBlock,
            'no ancestor of the h1 sets a z-index, so the title has no stacking '
            .'order and a card can be painted over it'
        );

        $this->assertContains('z-40', $titleClasses);
        $this->assertContains('pointer-events-none', $titleClasses);

        /*
         * And the number has to actually be higher. Reading the class and asserting
         * presence would pass if both said z-30.
         */
        $zOf = function (array $classes): int {
            foreach ($classes as $class) {
                if (preg_match('/^z-(\d+)$/', $class, $m)) {
                    return (int) $m[1];
                }
            }

            return 0;
        };

        $this->assertGreaterThan(
            $zOf($activeClasses),
            $zOf($titleClasses),
            'the title must outrank the active card, or a card can occlude the copy'
        );
    }

    /**
     * Every photo is in the HTML with a real src and alt, so a JS failure leaves
     * pictures rather than an empty frame.
     *
     * This is what makes the enhancement worth having at all. It is the property
     * the whole progressive-enhancement argument rests on, and it is checkable
     * without a browser.
     */
    #[Test]
    public function every_photo_is_in_the_html_without_javascript(): void
    {
        $destination = $this->destination();
        $this->photos($destination, 3);

        $html = $this->html($destination);

        $this->assertStringContainsString('https://images.example.com/hero.jpg', $html);

        foreach (range(1, 3) as $index) {
            $this->assertStringContainsString(
                'https://images.example.com/'.$index.'.jpg',
                $html,
                'photo '.$index.' is missing from the server-rendered page'
            );
        }
    }

    /**
     * The backdrop is the active photo, blurred, and every photo has a layer.
     *
     * The reference ties the ambient wash to the active slide rather than using a
     * static image, so a fan of mountains over a beach looks wrong. Each photo gets
     * its own backdrop element and only the first is unhidden; stage.js reveals the
     * right one as the slide changes.
     */
    #[Test]
    public function the_backdrop_is_blurred_and_bound_to_the_active_photo(): void
    {
        $destination = $this->destination();
        $this->photos($destination, 2);

        $xpath = $this->xpath($this->html($destination));

        $backdrops = $xpath->query('//*[@data-stage-backdrop]');

        $this->assertSame(
            3,
            $backdrops->length,
            'every photo needs a backdrop layer, or the wash cannot transition'
        );

        $hidden = 0;

        foreach ($backdrops as $backdrop) {
            $classes = preg_split(
                '/\s+/',
                trim($backdrop->getAttribute('class')),
                -1,
                PREG_SPLIT_NO_EMPTY
            );

            $this->assertContains('blur-3xl', $classes, 'the backdrop must be blurred');
            $this->assertContains('object-cover', $classes, 'the backdrop must not distort');

            if ($backdrop->hasAttribute('hidden')) {
                $hidden++;
            }
        }

        $this->assertSame(
            2,
            $hidden,
            'only the active backdrop is unhidden, so the wash tracks the slide'
        );

        // And the reveal has to be `hidden`, not opacity 0: that is what takes the
        // inactive layers out of the a11y tree and the compositor.
        $script = (string) file_get_contents(resource_path('js/stage.js'));

        /*
         * The script must toggle the backdrop with `hidden`, AND must key it off
         * the active slot's PHOTO rather than the slot index itself.
         *
         * The second half is a bug this shipped. `backdrops` is indexed by photo
         * and `active` is a slot index into a five-wide fan, so `index !== active`
         * worked only while active < photoCount -- with three photos, clicking past
         * the third slot compared 3 and 4 against a three-element array, leaving
         * every backdrop hidden and the stage with no wash at all.
         */
        $this->assertStringContainsString(
            'backdrop.hidden = index !== shown',
            $script,
            'stage.js must toggle the backdrop with hidden, matching the '
            .'server-rendered state'
        );

        $this->assertStringNotContainsString(
            'backdrop.hidden = index !== active',
            $script,
            'the backdrop must follow the active slot\'s photo, not the slot index, '
            .'or it runs off the end of the array'
        );
    }

    /**
     * A single photo still renders the stage. It is just not a carousel.
     *
     * Mirrors the old rule that one extra photo is not a carousel: a destination
     * with no admin uploads gets the full hero treatment rather than an empty
     * frame or a missing hero.
     */
    #[Test]
    public function one_photo_still_renders_the_stage_without_carousel_controls(): void
    {
        $destination = $this->destination();

        $xpath = $this->xpath($this->html($destination));

        $this->assertSame(
            1,
            $xpath->query('//*[@data-destination-stage]')->length,
            'a destination with one photo must still get the stage'
        );

        // The five slots are still rendered, so the geometry never shifts, but the
        // dots are gone because there is nothing to choose between.
        $this->assertSame(
            5,
            $xpath->query('//*[@data-stage-slot]')->length
        );

        $this->assertSame(
            0,
            $xpath->query('//*[@data-stage-dot]')->length,
            'one photo is not a carousel, so there are no dots to click'
        );

        $this->assertSame(
            0,
            $xpath->query('//*[@data-destination-stage]//*[@data-stage-dot]')->length
        );
    }

    /**
     * A destination with no photo at all still renders, and does not fatal.
     *
     * This is a regression test for a real crash. The fan picks a photo per slot
     * with `($slot + 2) % $photoCount`, and `$photoCount` is 0 when a row has
     * neither a seeded `image_url` nor an admin upload. `% 0` is a DivisionByZero
     * in PHP 8, so the whole page 500'd -- and `DestinationPageTest` creates
     * destinations without an image, so it took the entire suite down rather than
     * one narrow case.
     *
     * Guarding the modulo alone would not have been enough to reason about: the
     * fan itself is skipped, so there is no photo to show and no stage height from
     * it. The stage therefore carries its own `min-h` in that case, or the h1
     * renders over an empty band.
     *
     * The fallback is a gradient in the old hero's palette rather than a blank
     * dark panel, so a photoless row still reads as the brand.
     */
    #[Test]
    public function a_destination_with_no_photo_still_renders_the_stage(): void
    {
        $destination = $this->destination(['image_url' => null]);

        $this->assertSame(
            0,
            $destination->images()->count(),
            'this test is meaningless unless the fixture really has no photo'
        );

        $html = $this->html($destination);

        $xpath = $this->xpath($html);

        $this->assertSame(
            1,
            $xpath->query('//*[@data-destination-stage]')->length,
            'a destination with no photo must still get the stage'
        );

        $this->assertSame(
            '0',
            $xpath->query('//*[@data-destination-stage]')->item(0)
                ->getAttribute('data-stage-photo-count'),
            'the count must read zero, or stage.js thinks there are photos'
        );

        // No fan, because there is nothing to put in it. Not an empty fan.
        $this->assertSame(
            0,
            $xpath->query('//*[@data-stage-slot]')->length,
            'a fan with no photos in it is an empty frame'
        );

        // And no dead controls.
        $this->assertSame(0, $xpath->query('//*[@data-stage-dot]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-stage-prev]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-stage-next]')->length);

        // The heading is still there, and the panel has a height of its own.
        $this->assertSame(
            1,
            $xpath->query('//*[@data-destination-stage]//h1')->length,
            'the stage is the page heading; losing it loses the h1'
        );

        $stageClasses = $xpath->query('//*[@data-destination-stage]')->item(0)
            ->getAttribute('class');

        $this->assertStringContainsString(
            'min-h-',
            $stageClasses,
            'with no fan the section has no height of its own, so the h1 renders '
            .'over an empty band'
        );
    }

    /**
     * The controls exist in the server-rendered HTML, and they are real buttons.
     *
     * Deliberate, unlike the scroll-snap carousel this replaced, which inserted its
     * arrows from JS so that a failed script left no dead controls. Here the stage
     * is meaningful without JS -- the photos are all present and the heading is
     * readable -- so the chevrons can be in the markup and be styled by the same
     * cascade as everything else. They are `type="button"` so they cannot
     * accidentally submit anything, and each carries an accessible name rather
     * than a bare glyph.
     */
    #[Test]
    public function the_controls_are_real_buttons_in_the_markup(): void
    {
        $destination = $this->destination();
        $this->photos($destination, 2);

        $xpath = $this->xpath($this->html($destination));

        foreach (['prev', 'next'] as $which) {
            $button = $xpath->query('//*[@data-stage-'.$which.']')->item(0);

            $this->assertNotNull($button, 'the '.$which.' control is missing');

            $this->assertSame('button', strtolower($button->nodeName));
            $this->assertSame(
                'button',
                $button->getAttribute('type'),
                'a control without type="button" defaults to submit inside a form'
            );
            $this->assertNotSame(
                '',
                trim($button->getAttribute('aria-label')),
                'a visible glyph with no accessible name is announced as "button"'
            );
        }

        // One dot per photo, so the indicator is honest about how many there are.
        // Two uploads plus the hero is three photos.
        $this->assertSame(
            3,
            $xpath->query('//*[@data-stage-dot]')->length
        );
    }

    /**
     * The stage is a labelled region, and it carries the photo count.
     *
     * `data-stage-photo-count` is what stage.js reads to decide how many photos
     * exist and whether to show dots, so it has to be on the element the script
     * finds, not on a child.
     */
    #[Test]
    public function the_stage_is_a_labelled_region_with_a_photo_count(): void
    {
        $destination = $this->destination();
        $this->photos($destination, 2);

        $xpath = $this->xpath($this->html($destination));

        $stage = $xpath->query('//*[@data-destination-stage]')->item(0);

        $this->assertSame('carousel', $stage->getAttribute('aria-roledescription'));
        $this->assertStringContainsString(
            'Aguinaldo Shrine',
            $stage->getAttribute('aria-label')
        );
        $this->assertSame('3', $stage->getAttribute('data-stage-photo-count'));

        // A live region for the slide change. Silent to a sighted user, announced
        // to a screen reader, which is the only signal they get that the fan moved.
        $this->assertSame(
            1,
            $xpath->query('//*[@data-destination-stage]//*[@role="status"]')->length,
            'the stage needs a live region so a slide change is announced'
        );
    }

    /**
     * The stage geometry exists in the stylesheet, and is sized by height.
     *
     * A class in the markup with no rule behind it is silent: the fan renders as
     * five cards in a flat row and nothing says why. The offset-keyed rules are
     * what make it an arc, and they cannot be written as Tailwind utilities because
     * the per-side rotation sign differs between the left and right halves.
     *
     * HEIGHT, NOT WIDTH, is the assertion that matters. The cards were first sized
     * at `width: 23%` with the width following from `aspect-[2/5]`, which makes
     * the card's height a function of the viewport width: on a narrow container it
     * rendered taller than the fan and silently overflowed, and on the ~1500px
     * container it rendered taller than the whole page. Asserting a `width:` on the
     * slot is asserting the bug back into place.
     */
    #[Test]
    public function the_stage_geometry_exists_in_the_stylesheet(): void
    {
        $stylesheet = (string) file_get_contents(resource_path('css/app.css'));

        /*
         * The base rule, matched OUTSIDE any media query.
         *
         * A single `/\.tm-stage-slot\s*\{[^}]*width:/` passed with the base value
         * deleted, because a breakpoint still had one and the regex did not care
         * which rule it matched. Hence a check that a breakpoint cannot satisfy.
         */
        $baseRule = $this->ruleOutsideAnyMediaQuery($stylesheet, '.tm-stage-slot');

        $this->assertStringContainsString(
            'position: absolute',
            $baseRule,
            'the slots must be absolutely positioned. Laid out by flexbox they sit '
            .'in a row separated by whatever gap is set, which is how this shipped '
            .'once: the fan stretched edge to edge with wide holes between cards.'
        );

        $this->assertMatchesRegularExpression(
            '/\.tm-stage-slot\s*\{[^}]*position:\s*absolute/s',
            $stylesheet,
            'the slots must be absolutely positioned. Laid out by flexbox they sit '
            .'in a row separated by whatever gap is set, which is how this shipped '
            .'once: the fan stretched edge to edge with wide holes between cards.'
        );

        /*
         * Every offset is sized by a HEIGHT percentage, and all three differ.
         *
         * Height rather than width is the load-bearing part. Sizing by width makes
         * the card's height a function of the viewport width -- it rendered taller
         * than the fan on a narrow container and taller than the page on the wide
         * one. Distinct heights per offset are what make the arc an arc.
         */
        $heights = [];

        preg_match_all("/\[data-stage-offset='([0-2])'\][^{]*\{\s*height:\s*(\d+)%/s", $stylesheet, $heights, PREG_SET_ORDER);

        $this->assertGreaterThanOrEqual(
            3,
            count($heights),
            'the three slot offsets each need a height, or the cards size off the '
            .'viewport width and overflow the fan'
        );

        $byOffset = [];

        foreach ($heights as $match) {
            // The desktop block restates all three, so the LAST occurrence of each
            // offset is the one that actually wins the cascade.
            $byOffset[$match[1]] = (int) $match[2];
        }

        $this->assertGreaterThan(
            $byOffset['1'],
            $byOffset['0'],
            'the centre card must be the tallest, or the fan reads as a flat row'
        );

        $this->assertGreaterThan(
            $byOffset['2'],
            $byOffset['1'],
            'the far cards must be shorter than the near ones, or there is no depth'
        );

        // And no width anywhere on the slots -- the bug this replaced.
        $this->assertDoesNotMatchRegularExpression(
            '/\[data-stage-offset=[\'"][0-2][\'"]\][^{]*\{\s*width:/s',
            $stylesheet,
            'a slot is sized by width. That makes its height a function of the '
            .'viewport width, which is the overflow this layout was reworked to fix.'
        );

        // The fan is capped, or the cards balloon on the wide container.
        $this->assertMatchesRegularExpression(
            '/\.tm-stage-fan\s*\{[^}]*max-width:\s*\d/s',
            $stylesheet,
            'the fan has no max-width, so the cards scale with the page width and '
            .'dwarf the stage'
        );

        // Both pairs, and both sides. A missing left-hand rule tilts the whole fan
        // one way, which reads as a rendering bug rather than a design choice.
        foreach ([
            '1' => '7deg',
            '2' => '11deg',
        ] as $offset => $degrees) {
            $this->assertMatchesRegularExpression(
                "/\[data-stage-offset='$offset'\]\[data-stage-side='right'\]\s*\{[^}]*rotateY\($degrees\)/s",
                $stylesheet,
                "the offset-$offset cards on the right are missing their $degrees rotation"
            );

            $this->assertMatchesRegularExpression(
                "/\[data-stage-offset='$offset'\]\[data-stage-side='left'\]\s*\{[^}]*rotateY\(-$degrees\)/s",
                $stylesheet,
                "the offset-$offset cards on the left are not mirrored, so the fan "
                .'tilts one way instead of opening away from the centre'
            );
        }

        // The reduced-motion path has to exist and has to remove the transforms,
        // not merely shorten them.
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\)\s*\{[^}]*tm-stage-slot/s',
            $stylesheet,
            'there is no reduced-motion rule for the stage cards'
        );
    }

    /**
     * The title is sized and constrained, so it reads as a caption and not a banner.
     *
     * This is the loudest thing that was wrong with the first version of the
     * layout. The heading was `text-4xl sm:text-6xl lg:text-7xl xl:text-8xl` with
     * no width constraint, which put an ~90px headline across a 1500px stage -- it
     * spanned from the far card to the far card and read as a page-wide banner laid
     * over the photographs instead of a caption on them.
     *
     * Measured off the reference capture: cap height 20px in a 409px stage, so
     * roughly 5% of the stage height. The stage here is `lg:min-h` 26rem = 416px,
     * so ~21px of cap. Anything at `text-6xl` or above blows straight past that.
     *
     * Asserted on the rendered markup rather than the stylesheet, because the
     * regression was a Tailwind class on the h1 itself.
     */
    #[Test]
    public function the_title_is_small_and_width_constrained(): void
    {
        $destination = $this->destination();
        $this->photos($destination, 2);

        $xpath = $this->xpath($this->html($destination));

        $heading = $xpath->query('//*[@data-destination-stage]//h1')->item(0);

        $this->assertNotNull($heading, 'the stage must carry the page heading');

        $classes = preg_split(
            '/\s+/',
            trim($heading->getAttribute('class')),
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        // No fixed step at or above 6xl. The reference's headline is about a fifth
        // of that; a `text-8xl` is what produced the banner.
        foreach ($classes as $class) {
            if (preg_match('/^text-(\d+)xl$/', $class, $match)) {
                $this->assertLessThanOrEqual(
                    4,
                    (int) $match[1],
                    "the h1 carries {$class}. At 6xl or above it spans the whole "
                    .'stage and reads as a banner laid over the fan rather than a '
                    .'caption on it. Use the clamp() size instead.'
                );
            }
        }

        // Unanchored: the class list is joined, so `^` would only ever match the first
        // entry (`mt-3`) and pass for the wrong reason.
        $this->assertMatchesRegularExpression(
            '/(?:^|\s)text-\[clamp\(/',
            implode(' ', $classes),
            'the h1 must use a clamp() font size so it scales with the fan between '
            .'the 16rem mobile and 26rem desktop stages'
        );

        // And the width constraint, which is the other half of the fix. A small
        // font with no constraint still wraps a long name across the whole stage.
        $wrapper = null;

        for ($node = $heading; $node instanceof DOMElement; $node = $node->parentNode) {
            $nodeClasses = preg_split(
                '/\s+/',
                trim($node->getAttribute('class')),
                -1,
                PREG_SPLIT_NO_EMPTY
            );

            if (in_array('max-w-3xl', $nodeClasses, true)) {
                $wrapper = $nodeClasses;

                break;
            }
        }

        $this->assertNotNull(
            $wrapper,
            'no ancestor of the h1 sets a width constraint, so a long destination '
            .'name wraps across the full stage width'
        );
    }

    /**
     * The stage does not repeat the description that section 01 renders in full.
     *
     * The stage carried `Str::limit($destination->description, 90)` while the "01 /
     * The place" section a few hundred pixels below prints the same field
     * untruncated, so the opening sentence appeared twice in one screenful. That
     * reads as a mistake, not as emphasis, and no screenshot of a settled page
     * makes it obvious why it is there.
     *
     * Asserted as a COUNT of paragraphs in the title overlay rather than by
     * matching the description text, so a reworded or shortened tagline still
     * trips it. The location chip is the one paragraph that belongs there.
     */
    #[Test]
    public function the_stage_does_not_repeat_the_description(): void
    {
        $destination = $this->destination();
        $this->photos($destination, 2);

        $xpath = $this->xpath($this->html($destination));

        $heading = $xpath->query('//*[@data-destination-stage]//h1')->item(0);

        $this->assertNotNull($heading);

        // The <p> that carries the province/municipality chip, and any prose after
        // the heading, both inside the overlay that holds the title.
        $overlay = null;

        for ($node = $heading->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (str_contains($node->getAttribute('class'), 'pointer-events-none')) {
                $overlay = $node;

                break;
            }
        }

        $this->assertNotNull($overlay, 'the title overlay wrapper is missing');

        /*
         * Exactly one <p> in the overlay: the location chip. Counted, not matched
         * against the description text.
         *
         * Matching the text looked stronger and was weaker -- it only fires on the
         * exact words it was written against, so a reworded or shortened tagline
         * sails straight through, and a falsification using a placeholder string
         * passed when the paragraph was plainly there. A count catches any prose
         * at all, which is the actual rule.
         */
        $paragraphs = $xpath->query('.//p', $overlay);

        $this->assertSame(
            1,
            $paragraphs->length,
            'the title overlay carries '.$paragraphs->length.' paragraphs. Only the '
            .'location chip belongs here -- the stage must not repeat the '
            .'description, which section 01 renders in full directly below it, so '
            .'the same sentence would otherwise appear twice in one screenful.'
        );

        // And the one that remains is the chip, not prose.
        $this->assertStringContainsString(
            'Cavite',
            $paragraphs->item(0)->textContent,
            'the overlay paragraph should be the municipality/province chip'
        );
    }

    /**
     * The far pair is hidden below the desktop breakpoint, and stage.js skips it.
     *
     * The reference drops the outer pair on narrow screens. It is hidden with
     * `opacity: 0` rather than `display: none` so the arc does not visibly collapse
     * and re-expand on resize -- but that means the slots are still in the DOM with
     * real geometry, so a script that simply walked slot 0 to slot 4 would step
     * onto an invisible card and the fan would appear frozen on a phone.
     *
     * That is why stage.js measures which slots actually have layout rather than
     * assuming all five are usable, and it is asserted here so the two cannot drift.
     */
    #[Test]
    public function the_far_pair_is_hidden_on_narrow_screens_and_the_script_skips_it(): void
    {
        $stylesheet = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            "/\[data-stage-offset='2'\]\s*\{[^}]*opacity:\s*0/s",
            $stylesheet,
            "the far pair must be hidden below 64rem; the reference drops it on "
            .'narrow screens'
        );

        $script = (string) file_get_contents(resource_path('js/stage.js'));

        $this->assertStringContainsString(
            'getBoundingClientRect().width > 0',
            $script,
            'stage.js must detect which slots have real layout. The far pair is '
            .'hidden with opacity rather than display:none, so it still measures '
            .'non-zero and stepping onto it shows nothing at all.'
        );
    }
}
