<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The interaction layer, pinned at the only level available here.
 *
 * There is no browser automation in this project, so drag, arrows, the loading
 * overlay and the hamburger cannot be exercised. What *can* be checked is the
 * thing every one of those bugs actually was: a selector that matches nothing,
 * or a class the markup does not carry, so the effect is silently absent while
 * every other test passes.
 *
 * The hamburger shipped broken this way. `.group-open` reads like a real class,
 * so it was hand-written in app.css instead of using Tailwind's `group-open:`
 * variant -- but that variant expands to `:where(.group):is(:where([open]...) *)`
 * and keys off the ATTRIBUTE the browser sets on <details>. No element ever
 * carries a `group-open` class, so the rule matched nothing and the button was
 * a hamburger permanently. The `group-open:hidden` markup it replaced had
 * worked, so this made it strictly worse.
 */
class InteractionMarkupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * app.css with every comment removed.
     *
     * These rules are documented at length, and several comments quote the very
     * selectors being asserted on -- ".group-open matches no element". Scanning
     * the raw file would trip over its own explanation.
     */
    protected function stylesheet(): string
    {
        $path = resource_path('css/app.css');

        $this->assertFileExists($path);

        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));
    }

    /**
     * Just the selector lines mentioning $needle.
     *
     * Asserting against the whole stylesheet means a failure prints 400 lines of
     * CSS, which is how the first run of this file produced unreadable output.
     */
    protected function selectorsFor(string $needle): string
    {
        $lines = [];

        foreach (explode("\n", $this->stylesheet()) as $line) {
            if (str_contains($line, '{') && str_contains($line, $needle)) {
                $lines[] = trim($line);
            }
        }

        return implode("\n", $lines);
    }

    #[Test]
    public function the_hamburger_is_keyed_off_the_open_attribute_not_a_group_open_class(): void
    {
        $css = $this->stylesheet();

        // <details> sets `open`, and `group` is the class Tailwind's own variant
        // keys off, so `.group[open]` is the only selector that can match this
        // markup.
        $this->assertStringContainsString(
            '.group[open]',
            $css,
            'the burger has no open-state rule, so the icon never becomes an X'
        );

        foreach (['top', 'middle', 'bottom'] as $line) {
            $this->assertStringContainsString(
                '.group[open] .tm-burger-line--'.$line,
                $css,
                'burger line --'.$line.' has no open-state rule, so it never moves'
            );
        }

        /*
         * The exact mistake that shipped. Tailwind never emits a `group-open`
         * class -- `group-open:` is a variant, not a class name -- so a bare
         * `.group-open` selector is dead CSS. Excludes `.group-open\:hidden`,
         * which is the escaped utility form and is legitimate.
         */
        $this->assertDoesNotMatchRegularExpression(
            '/\.group-open(?![-\w:])/',
            $this->selectorsFor('burger'),
            '`.group-open` matches no element: Tailwind\'s group-open: is a variant keyed off the '
            .'[open] attribute, not a class name. This rule silently does nothing.'
        );
    }

    #[Test]
    public function the_hamburger_markup_carries_the_hook_the_stylesheet_selects_on(): void
    {
        $html = $this->renderAppPage();

        $xpath = $this->xpath($html);

        $this->assertGreaterThan(
            0,
            $xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " group ")]')->length,
            'the nav toggle must be a <details class="group">; the stylesheet selects .group[open] on it'
        );

        $this->assertSame(
            3,
            $xpath->query('//*[contains(@class, "tm-burger")]//*[contains(@class, "tm-burger-line")]')->length,
            'the burger needs exactly three lines to rotate into an X'
        );

        foreach (['top', 'middle', 'bottom'] as $line) {
            $this->assertGreaterThan(
                0,
                $xpath->query('//*[contains(@class, "tm-burger-line--'.$line.'")]')->length,
                'no element carries tm-burger-line--'.$line.', which the open-state rule selects on'
            );
        }
    }

    #[Test]
    public function the_loading_overlay_fade_out_outranks_the_visible_state(): void
    {
        $this->assertStringContainsString('.tm-loader:not(.hidden)', $this->stylesheet());
        $this->assertStringContainsString('.tm-loader.tm-loader--leaving', $this->stylesheet());

        /*
         * Specificity, not just presence. `.tm-loader:not(.hidden)` is two class
         * selectors; a bare `.tm-loader--leaving` is one and loses regardless of
         * source order. The fade-out shipped broken that way -- the overlay sat
         * at opacity 1 and blinked out on the frame `hidden` was added, so the
         * 200ms transition never played.
         *
         * Anchored to the start of a line, so the fixed two-class selector is
         * not mistaken for the bare one.
         */
        $this->assertDoesNotMatchRegularExpression(
            '/^[ \t]*\.tm-loader--leaving[ \t]*\{/m',
            $this->selectorsFor('tm-loader--leaving'),
            'a bare .tm-loader--leaving rule loses to .tm-loader:not(.hidden) on specificity, '
            .'so the fade-out never plays'
        );
    }

    #[Test]
    public function the_overlay_is_rendered_on_every_layout_that_loads_the_script(): void
    {
        /*
         * welcome.blade.php loaded page-transitions.js but never rendered the
         * component, so the link handler fired on the hero CTA at a
         * [data-navigation-loading] element that was not on the page. The script
         * optional-chains it, so it failed silently.
         *
         * '/' is an unnamed closure route, so it cannot be reached with route().
         */
        $user = $this->userWithProfile();

        /*
         * Guest pages first, while still unauthenticated. `actingAs` sets the
         * guard for the rest of the test method, and /login 302s to /dashboard
         * for a signed-in user -- so checking it after authenticating would
         * assert against a redirect rather than the layout under test.
         */
        $anonymous = [
            'guest' => route('login'),
            'welcome' => url('/'),
        ];

        foreach ($anonymous as $layout => $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString(
                'data-navigation-loading',
                $html,
                'the '.$layout.' page renders no [data-navigation-loading] but loads '
                .'page-transitions.js, which drives it'
            );
        }

        $html = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'data-navigation-loading',
            $html,
            'the app page renders no [data-navigation-loading] but loads page-transitions.js, '
            .'which drives it'
        );
    }

    /**
     * Every `[data-*]` selector in app.css must name an attribute that a view or
     * a script actually writes.
     *
     * This is the dead-code shape this file keeps running into, in its most
     * convincing form: a rule that reads like a feature. The page-cover
     * transition shipped that way. Forty lines of `[data-page-curtain]`
     * keyframes and four custom properties sat in the stylesheet while the script
     * that created the element had already been deleted, and the
     * `setPendingTransition()` that gated its creation had no caller anywhere.
     * Nothing could match the selector, so the transition silently did not exist
     * -- while the CSS sat there looking like proof that it did.
     */
    /**
     * A link being pressed must look pressed.
     *
     * page-transitions.js waits 350ms before showing the loading overlay, on the
     * reasoning that a navigation finishing inside that window needs no
     * full-screen takeover. Its own comment then claimed the gap was covered by
     * "the button's own :active state" -- true for a <button>, and false for an
     * <a>, because no rule styled a link's press state.
     *
     * So on a fast navigation the sequence was: click, nothing at all, new page.
     * The loading overlay was the backstop and it had nothing to back up, which
     * is why it read as missing rather than subtle. Verified by removing the
     * rule: this fails.
     */
    #[Test]
    public function a_pressed_link_is_visibly_pressed(): void
    {
        $this->assertMatchesRegularExpression(
            '/(^|[\s,{}])a:active[^{]*\{[^}]*opacity\s*:\s*(?!1\b)[0-9.]/',
            $this->stylesheet(),
            'no visible press state for links, so a click on a link has no feedback until the '
            .'loading overlay appears 350ms later -- and on a fast navigation it never appears'
        );
    }

    #[Test]
    public function every_attribute_selector_in_the_stylesheet_is_produced_by_something(): void
    {
        /*
         * Attributes written by a library rather than by us. Lenis sets
         * data-lenis-prevent itself on any element excluded from smooth
         * scrolling; nothing in this repository writes it.
         */
        $writtenByALibrary = [
            'data-lenis-prevent' => 'Lenis writes this attribute itself.',
        ];

        preg_match_all('/\[(data-[a-z-]+)\]/', $this->stylesheet(), $matches);

        $this->assertNotEmpty(
            $matches[1],
            'no attribute selectors were found, so this guard is not looking at anything'
        );

        $sources = $this->markupAndScriptSource();

        foreach (array_unique($matches[1]) as $attribute) {
            if (isset($writtenByALibrary[$attribute])) {
                continue;
            }

            $this->assertStringContainsString(
                $attribute,
                $sources,
                'app.css styles ['.$attribute.'] but no view or script produces it, so the rule '
                .'matches nothing and whatever it was written for never happens'
            );
        }
    }

    /**
     * public/build is committed on purpose, because the host cannot run Vite: the
     * deployed site serves whatever was committed. A source change without a
     * rebuild therefore reaches the repository and never reaches a browser.
     *
     * That is not hypothetical here. The committed CSS was already stale when
     * the first deploy was prepared, and no test could see it, because every
     * other assertion in this file reads resources/ while the site loads the
     * bundle.
     */
    #[Test]
    public function the_committed_build_covers_every_vite_input(): void
    {
        $manifestPath = public_path('build/manifest.json');

        $this->assertFileExists(
            $manifestPath,
            'public/build is not committed, so the deployed site loads no CSS or JS at all'
        );

        // Decoded by hand rather than with JSON_THROW_ON_ERROR, because that
        // surfaces as "Syntax error" and this file's whole purpose is a
        // failure message that names the cause.
        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        $this->assertIsArray(
            $manifest,
            'public/build/manifest.json is not valid JSON, so @vite cannot resolve any asset and '
            .'the deployed site loads no CSS or JS. Run `npm run build`.'
        );

        preg_match_all("/'([^']+\.(?:css|js))'/", (string) file_get_contents(base_path('vite.config.js')), $inputs);

        $this->assertNotEmpty(
            $inputs[1],
            'no inputs were parsed out of vite.config.js, so this guard is not looking at anything'
        );

        foreach ($inputs[1] as $input) {
            $this->assertArrayHasKey(
                $input,
                $manifest,
                'vite.config.js builds '.$input.' but public/build/manifest.json has no entry for it. '
                .'Run `npm run build` and commit the result with `git add -A public/build`.'
            );
        }
    }

    /**
     * Every Blade view and every script, concatenated.
     *
     * An attribute can be created on either side of the divide -- the itinerary
     * editor's notice element is written in Blade and given its tone from JS --
     * so scanning one directory would miss half of them.
     */
    protected function markupAndScriptSource(): string
    {
        $source = '';

        foreach (array_merge(File::allFiles(resource_path('views')), File::allFiles(resource_path('js'))) as $file) {
            $source .= "\n".$file->getContents();
        }

        return $source;
    }

    #[Test]
    public function primary_actions_carry_press_feedback(): void
    {
        /*
         * `.tm-primary-button` is on eight elements in the whole app. The
         * buttons a traveller actually presses -- Log in, Like, Generate
         * itinerary -- are styled with ad-hoc utilities instead, so scoping the
         * press state to that one class left the primary path with no feedback
         * at all.
         */
        $views = [
            'auth/login.blade.php' => 'Log in',
            'discover/index.blade.php' => 'Like',
            'itineraries/create.blade.php' => 'Generate itinerary',
        ];

        foreach ($views as $file => $label) {
            $source = (string) file_get_contents(resource_path('views/'.$file));

            $this->assertStringContainsString(
                'active:scale-[0.98]',
                $source,
                $file.' has no press feedback on its primary action, so the click reads as ignored'
            );

            $this->assertStringContainsString(
                $label,
                $source,
                $file.' no longer contains the "'.$label.'" action this guard was written for'
            );
        }
    }

    #[Test]
    public function the_log_out_action_is_styled_as_destructive(): void
    {
        $xpath = $this->xpath($this->renderAppPage());

        $buttons = $xpath->query('//button[normalize-space(.)="Log out"]');

        $this->assertGreaterThan(
            0,
            $buttons->length,
            'no Log out button rendered, so this guard is checking nothing'
        );

        foreach ($buttons as $button) {
            /*
             * Two legitimate ways to read as destructive: red text on a neutral
             * surface (the nav item) or a filled red button (the confirmation).
             * Requiring red *text* would have rejected the second outright.
             */
            $this->assertMatchesRegularExpression(
                '/(?:text|bg)-red-\d{3}/',
                $button->getAttribute('class'),
                'the Log out action carries no red treatment, so it reads as a neutral nav item'
            );
        }
    }

    /**
     * A MISSING CLOSING BRACE SWALLOWS A WHOLE BLOCK, AND NOTHING FAILS.
     *
     * CSS nesting is legal, so a rule written inside another rule is valid CSS:
     * it compiles, it bundles, every other assertion stays green. The selector
     * simply comes out wrong -- `.font-script .tm-place-nav__input` instead of
     * `.tm-place-nav__input` -- and matches nothing, because `.font-script`
     * exists on exactly one decorative span on the welcome page.
     *
     * That is how the whole place strip shipped unstyled: the prev/next links,
     * the Quickhop label, the input and its placeholder, and the Go button, all
     * falling back to inherited colour on the destination page's dark ground.
     * The report was "gray text against a dark background, tough to read". The
     * braces balanced, the build succeeded, and no assertion in the suite read
     * the stylesheet's structure -- so the defect was entirely in computed
     * styling, which this repo has learned twice is the thing nothing measures.
     *
     * At-rules are exempt because they are SUPPOSED to contain rules: @media,
     * @layer, @supports and @keyframes. What must not happen is a plain rule
     * sitting anywhere below another plain rule.
     */
    #[Test]
    public function no_plain_rule_is_swallowed_inside_another_plain_rule(): void
    {
        $rules = $this->ruleNesting();

        $swallowed = array_values(array_filter(
            $rules,
            static fn (array $rule) => $rule['outer'] !== null
        ));

        $described = array_map(
            static fn (array $rule) => $rule['outer']
                .' contains '.$rule['selector']
                .' (line '.$rule['line'].')',
            $swallowed
        );

        $this->assertSame(
            [],
            $swallowed,
            'A closing brace is missing somewhere in app.css, so these rules are nested '
            .'inside another rule and will never match. Swallowed: '.implode('; ', $described)
        );

        /*
         * The specific block that shipped this way. The general assertion
         * above catches any future swallowing, but only this half says that
         * the Quickhop form still HAS rules at all -- deleting them would
         * leave the stylesheet flat and unswallowed and perfectly unstyled.
         */
        foreach ([
            '.tm-place-nav__link',
            '.tm-place-nav__name',
            '.tm-place-nav__label',
            '.tm-place-nav__input',
            '.tm-place-nav__go',
        ] as $selector) {
            $declared = null;

            foreach ($rules as $rule) {
                if ($rule['selector'] === $selector) {
                    $declared = $rule;
                    break;
                }
            }

            $this->assertNotNull(
                $declared,
                'app.css declares no `'.$selector.'` rule, so the place strip renders '
                .'unstyled on the destination page'
            );

            $this->assertNull(
                $declared['outer'],
                '`'.$selector.'` is nested inside `'.$declared['outer'].'`, so the compiled '
                .'selector asks for it beneath an element that never wraps the strip'
            );
        }
    }

    /**
     * Every rule in app.css with its line, and the plain rule that contains it
     * when there is one.
     *
     * Comments are BLANKED rather than deleted -- `stylesheet()` deletes them,
     * which collapses the line count and would send whoever reads a failure to
     * the wrong rule. The replaced text keeps every newline, so line numbers
     * still point at the real file.
     *
     * Braces inside CSS strings would confuse this scanner; app.css carries
     * none, and the scan reports a balanced depth of zero when it is reading
     * the file correctly.
     */
    protected function ruleNesting(): array
    {
        $path = resource_path('css/app.css');

        $this->assertFileExists($path);

        $css = preg_replace_callback(
            '#/\*.*?\*/#s',
            static fn (array $comment) => preg_replace('/[^\n]/', ' ', $comment[0]),
            (string) file_get_contents($path)
        );

        $rules = [];
        $stack = [];
        $pending = '';
        $line = 1;

        for ($i = 0, $length = strlen($css); $i < $length; $i++) {
            $character = $css[$i];

            if ($character === "\n") {
                $line++;
                continue;
            }

            if ($character === '{') {
                $selector = trim(preg_replace('/\s+/', ' ', $pending));
                $pending = '';

                $outer = null;

                foreach ($stack as $frame) {
                    if ($frame['plain']) {
                        $outer = $frame['selector'];
                        break;
                    }
                }

                $rules[] = [
                    'line' => $line,
                    'selector' => $selector,
                    'outer' => $outer,
                ];

                $stack[] = [
                    'selector' => $selector,
                    'plain' => ! str_starts_with($selector, '@'),
                ];

                continue;
            }

            if ($character === '}') {
                array_pop($stack);
                continue;
            }

            if ($character === ';') {
                $pending = '';
                continue;
            }

            $pending .= $character;
        }

        $this->assertSame(
            0,
            count($stack),
            'app.css has unbalanced braces, so this scan is reading the file wrong '
            .'and would report nonsense'
        );

        return $rules;
    }

    /**
     * THE STRIP'S COLOURS, MEASURED AGAINST THE GROUND THEY SIT ON.
     *
     * "Gray text against a dark background, tough to read" was the report, and
     * the root cause was the swallowed block above: none of these rules matched,
     * so everything fell back to inherited colour. Restoring them restores the
     * INTENDED colours -- which is why this test measures those rather than
     * asserting they exist. Three of the eight controls still failed AA once
     * restored, which is a separate defect the structural guard cannot see.
     *
     * Translucent values are BLENDED before measuring: 40% white over the
     * input's translucent fill is not 40% white, and comparing the raw RGBA
     * against the background is not what a reader sees.
     *
     * Nothing else in this repository measures contrast, so this is the only
     * thing that would notice a palette change pushing these back under AA.
     */
    #[Test]
    public function the_place_strips_controls_read_at_wcag_aa_on_the_teal_ground(): void
    {
        $ground = $this->resolveColour('var(--color-volcanic-teal)');
        $ground = array_slice($ground, 0, 3);

        $inputFill = $this->blendOnto(
            $this->resolveColour($this->declarationOf($this->ruleBody('.tm-place-nav__input'), 'background-color')),
            $ground
        );

        $cases = [
            ['Quickhop label', $this->resolveColour($this->declarationOf($this->ruleBody('.tm-place-nav__label'), 'color')), $ground, '10px bold'],
            ['Quickhop input text', $this->resolveColour($this->declarationOf($this->ruleBody('.tm-place-nav__input'), 'color')), $inputFill, '14px'],
            ['Quickhop placeholder', $this->resolveColour($this->declarationOf($this->ruleBody('.tm-place-nav__input::placeholder'), 'color')), $inputFill, '14px'],
            ['Go button', $this->resolveColour($this->declarationOf($this->ruleBody('.tm-place-nav__go'), 'color')), $this->resolveColour($this->declarationOf($this->ruleBody('.tm-place-nav__go'), 'background-color')), '14px bold'],
            ['Go button, hovered', $this->resolveColour($this->declarationOf($this->ruleBody('.tm-place-nav__go:hover'), 'color')), $this->resolveColour($this->declarationOf($this->ruleBody('.tm-place-nav__go:hover'), 'background-color')), '14px bold'],
            ['Previous / next link', $this->resolveColour($this->declarationOf($this->ruleBody('.tm-place-nav__link'), 'color')), $ground, '16px'],
            ['Catalogue position', $this->resolveColour($this->declarationOf($this->ruleBody('.tm-place-nav__position'), 'color')), $ground, '11px bold'],
            ['"Last place" end label', $this->resolveColour($this->declarationOf($this->ruleBody('.tm-place-nav__empty'), 'color')), $ground, '12px'],
        ];

        foreach ($cases as [$label, $foreground, $background, $size]) {
            $ratio = $this->contrastRatio($foreground, $background);

            /*
             * WCAG "large text" starts at 18.66px bold / 24px regular, and
             * nothing in this strip reaches it -- so 4.5:1 applies to all of
             * these, including the 10px label.
             */
            $this->assertGreaterThanOrEqual(
                4.5,
                $ratio,
                $label.' reads at '.round($ratio, 2).':1 on the destination page ('
                .$size.', needs 4.5:1). Someone reported this strip as hard to read; '
                .'do not take it back under AA.'
            );
        }
    }

    /** The declarations inside one rule. No nesting, which the test above pins. */
    protected function ruleBody(string $selector): string
    {
        $pattern = '#'.preg_quote($selector, '#').'\s*\{([^{}]*)\}#';

        $this->assertMatchesRegularExpression(
            $pattern,
            $this->stylesheet(),
            'app.css declares no `'.$selector.'` rule, so this control has no styling to measure'
        );

        preg_match($pattern, $this->stylesheet(), $matches);

        return (string) $matches[1];
    }

    protected function declarationOf(string $body, string $property): string
    {
        /*
         * The lookbehind is load-bearing: `color` also occurs inside
         * `background-color`, and a bare `color:` pattern matches there first
         * and returns the FILL as if it were the text. Comparing a fill with
         * itself then reports ~1.2:1 and blames the text colour.
         */
        $pattern = '#(?<![-\w])'.preg_quote($property, '#').'\s*:\s*([^;]+);#';

        $this->assertMatchesRegularExpression(
            $pattern,
            $body,
            'the rule carries no `'.$property.'` declaration, so there is nothing to measure'
        );

        preg_match($pattern, $body, $matches);

        return trim($matches[1]);
    }

    /**
     * A declaration to [red, green, blue, alpha].
     *
     * Rejects anything it does not recognise rather than guessing: a value
     * this scanner cannot read must fail loudly, because silently returning
     * black would make every colour here "pass" against a dark ground.
     *
     * @return array{0: int, 1: int, 2: int, 3: float}
     */
    protected function resolveColour(string $value): array
    {
        $value = trim($value);

        if (preg_match('/^var\(--color-([a-z-]+)\)$/', $value, $matches)) {
            $hex = $this->paletteTokens()[$matches[1]] ?? null;

            $this->assertNotNull($hex, 'the palette defines no token `--color-'.$matches[1].'`');

            return [...$this->hexToRgb($hex), 1.0];
        }

        if (preg_match('/^rgb\(\s*(\d+)\s+(\d+)\s+(\d+)\s*\/\s*([0-9.]+)\s*\)$/', $value, $matches)) {
            return [(int) $matches[1], (int) $matches[2], (int) $matches[3], (float) $matches[4]];
        }

        if (preg_match('/^rgb\(\s*(\d+)\s+(\d+)\s+(\d+)\s*\)$/', $value, $matches)) {
            return [(int) $matches[1], (int) $matches[2], (int) $matches[3], 1.0];
        }

        if (preg_match('/^#([0-9a-f]{6})$/i', $value, $matches)) {
            return [...$this->hexToRgb('#'.$matches[1]), 1.0];
        }

        if ($value === 'white') {
            return [255, 255, 255, 1.0];
        }

        $this->fail('the colour scanner cannot read `'.$value.'`, so this measurement is worthless');
    }

    /** @return array<string, string> token name => uppercase hex */
    protected function paletteTokens(): array
    {
        $tokens = [];

        preg_match_all(
            '/--color-([a-z-]+):\s*(#[0-9A-Fa-f]{6})/',
            $this->stylesheet(),
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $tokens[$match[1]] = strtoupper($match[2]);
        }

        $this->assertNotEmpty($tokens, 'no palette tokens were read from app.css');

        return $tokens;
    }

    protected function hexToRgb(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }

    /**
     * Blend a possibly-translucent colour over an opaque one, the way the
     * browser composites it before the eye ever measures the pair.
     *
     * @param  array  $over  [r, g, b, alpha]
     * @param  array  $onto  [r, g, b]
     * @return array{0: int, 1: int, 2: int}
     */
    protected function blendOnto(array $over, array $onto): array
    {
        return [
            (int) round($over[0] * $over[3] + $onto[0] * (1 - $over[3])),
            (int) round($over[1] * $over[3] + $onto[1] * (1 - $over[3])),
            (int) round($over[2] * $over[3] + $onto[2] * (1 - $over[3])),
        ];
    }

    protected function contrastRatio($foreground, array $background): float
    {
        $fg = array_slice($foreground, 0, 3);

        if (count($foreground) === 4 && $foreground[3] < 1.0) {
            $fg = $this->blendOnto($foreground, $background);
        }

        $light = $this->relativeLuminance($fg);
        $dark = $this->relativeLuminance($background);

        [$high, $low] = $light >= $dark ? [$light, $dark] : [$dark, $light];

        return ($high + 0.05) / ($low + 0.05);
    }

    protected function relativeLuminance(array $rgb): float
    {
        $channel = static function (int $value): float {
            $normalised = $value / 255;

            return $normalised <= 0.04045
                ? $normalised / 12.92
                : pow(($normalised + 0.055) / 1.055, 2.4);
        };

        return 0.2126 * $channel($rgb[0])
            + 0.7152 * $channel($rgb[1])
            + 0.0722 * $channel($rgb[2]);
    }

    protected function userWithProfile(): User
    {
        $user = User::factory()->create();

        // /dashboard redirects a user with no travel profile, which would make
        // the assertions above pass against a login redirect instead of a page.
        $user->travelProfile()->create([
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 3,
        ]);

        return $user;
    }

    protected function renderAppPage(): string
    {
        return $this->actingAs($this->userWithProfile())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();
    }

    protected function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();

        @$document->loadHTML($html);

        libxml_clear_errors();

        return new \DOMXPath($document);
    }
}
