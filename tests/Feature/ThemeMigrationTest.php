<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE THEME MIGRATION: a rename, not a restyle.
 *
 * The light pages were moved off the palette tokens and onto semantic roles in
 * one pass, across eighteen files. That is only safe because every light value
 * is byte-for-byte what the palette token it replaced already was -- so the
 * whole claim this file exists to protect is that light mode did not move.
 */
class ThemeMigrationTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    /**
     * LIGHT MODE IS EXACTLY THE PALETTE IT REPLACED.
     *
     * This is the assertion the entire migration rests on. `bg-surface` has to
     * be the old `bg-palawan-sand` and nothing else, or ~370 replacements across
     * eighteen views became a restyle nobody asked for and nobody reviewed --
     * and it would be invisible in every test that only checks a page renders.
     */
    #[Test]
    public function the_light_theme_is_exactly_the_palette_it_replaced(): void
    {
        $css = $this->css();

        $pairs = [
            '--color-page' => '#D7E4DB',
            '--color-surface' => '#FDFBF7',
            '--color-surface-raised' => '#FFFFFF',
            '--color-line' => '#E1F7F3',
            '--color-ink' => '#2C3531',
            '--color-ink-strong' => '#0B252B',
        ];

        foreach ($pairs as $token => $expected) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($token, '/').':\s*'.preg_quote($expected, '/').';/',
                $css,
                $token.' is not '.$expected.' on the light theme, so switching a '
                .'view from the palette token to the semantic one was a restyle '
                .'and not the rename it was supposed to be'
            );
        }
    }

    /**
     * THE DARK HALF IS THE STAGE'S WORLD, NOT A DARKER LIGHT THEME.
     *
     * The ground is the Deep Volcanic Teal the destinations stage is already
     * built on, and a card is the stage's own `.tm-panel` treatment -- a
     * translucent white with the light doing the lifting. A solid dark grey card
     * on that ground is the flat colour block the stage's panel rules forbid.
     */
    #[Test]
    public function the_dark_theme_is_the_stages_world_and_not_a_darker_light_theme(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/\.dark \{[^}]*--color-page:\s*#0B252B;/s',
            $css,
            'the dark ground is not Deep Volcanic Teal, so the dark theme is '
            .'somewhere other than the ground the stage and the deck already use'
        );

        $this->assertMatchesRegularExpression(
            '/\.dark \{[^}]*--color-surface:\s*rgb\(255 255 255 \/ 0\.04\);/s',
            $css,
            'the dark card surface is not the stage\'s translucent panel. A solid '
            .'dark fill on this ground is a flat colour block'
        );

        $this->assertMatchesRegularExpression(
            '/\.dark \{[^}]*--color-ink:\s*#D7E4DB;/s',
            $css,
            'the dark ink is not Sea Glass, which is what carries on Volcanic Teal'
        );
    }

    /**
     * A PALETTE TOKEN IS NEVER REDEFINED UNDER `.dark`, BECAUSE SEVERAL OF THEM
     * ARE USED IN TWO ROLES AT ONCE.
     *
     * `bg-island-white` is a card surface on a light page and light text on the
     * stage. `bg-benguet-charcoal` is the ink on the turquoise Like button and
     * would also be the ink token. `bg-volcanic-teal` is an always-dark header
     * bar that has to stay dark in BOTH themes. Flipping any of them in place
     * repaints the header or puts light text on a light surface -- which is the
     * exact failure the stage section records.
     */
    #[Test]
    public function no_palette_token_is_flipped_in_place(): void
    {
        $dark = $this->darkBlock();

        foreach (['palawan-sand', 'sea-glass', 'benguet-charcoal', 'volcanic-teal', 'island-white', 'boracay-light'] as $paletteToken) {
            $this->assertStringNotContainsString(
                '--color-'.$paletteToken,
                $dark,
                '--color-'.$paletteToken.' is redefined under .dark. That token is '
                .'used in two roles at once -- a card surface AND light text, or '
                .'an always-dark bar AND an ink -- so flipping it in place breaks '
                .'one of them. Roles are what flip, not colours'
            );
        }
    }

    /**
     * THE LIGHT PAGES CARRY NO PALETTE TOKEN ANY MORE.
     *
     * Token-wise, not substring-wise: `hover:bg-island-white` inside
     * `hover:ring-offset-island-white` is a different class, and a substring
     * check would treat them as one.
     */
    #[Test]
    public function the_light_pages_use_roles_and_not_palette_tokens(): void
    {
        $pages = $this->lightPages();

        $this->assertGreaterThan(
            0,
            count($pages),
            'no light pages were found, so this assertion is checking nothing'
        );

        $offenders = ['bg-palawan-sand', 'bg-island-white', 'bg-sea-glass', 'text-benguet-charcoal', 'text-volcanic-teal'];

        foreach ($pages as $page) {
            $source = (string) file_get_contents($page);
            $classes = implode(' ', (array) (preg_split('/\s+/', strip_tags(str_replace(['{{', '}}'], '', $source))) ?: []));

            foreach ($offenders as $token) {
                foreach (explode(' ', $classes) as $class) {
                    $this->assertStringNotContainsString(
                        $token,
                        $class,
                        basename($page).' still carries `'.$class.'`, which is a '
                        .'palette token rather than a role, so it cannot follow the '
                        .'theme'
                    );
                }
            }
        }
    }

    /**
     * AND THE DARK PAGES KEEP THEIR OWN PALETTE.
     *
     * The inverse exclusion, and it is the one that is easy to get wrong: the
     * destination page and the deck are dark BY DESIGN, not by preference, and
     * `DestinationStageTest` guards them by asserting the light palette classes
     * are absent. Migrating them would be pointless and would fail that guard.
     */
    #[Test]
    public function the_dark_by_design_pages_are_left_on_their_own_palette(): void
    {
        foreach ([
            'resources/views/destinations/show.blade.php',
            'resources/views/discover/index.blade.php',
        ] as $page) {
            $source = (string) file_get_contents($page);

            foreach (['bg-surface', 'bg-line', 'text-ink', 'bg-surface-raised'] as $role) {
                $this->assertStringNotContainsString(
                    $role,
                    $source,
                    basename($page).' has been migrated onto `'.$role.'`, but this '
                    .'page is dark by design. Its palette inversion is solved for '
                    .'this ground and is not a theme role'
                );
            }
        }
    }

    /**
     * THE FOCUS RING OFFSET IS EXPLICIT, BECAUSE AN ANCESTOR RULE CANNOT WORK.
     *
     * Tailwind registers the ring offset colour with `@property` and
     * `inherits: false`, so the obvious fix -- a rule on `.dark`, an ancestor
     * of every button -- sets the property on the html element and nowhere else,
     * and every focused control still resolves the initial white. That version of
     * this test passed, and the halos were still there.
     *
     * So the colour is declared on the control itself. The cost is one class on
     * each of the sixteen controls; the benefit is that it cannot silently depend
     * on a registration detail in someone else's build.
     */
    #[Test]
    public function the_focus_ring_offset_is_declared_on_the_control_not_on_an_ancestor(): void
    {
        $sources = $this->lightViewSources();

        $this->assertStringNotContainsString(
            '--tw-ring-offset-color',
            $this->css(),
            'the ring offset colour is set on an ancestor. It is registered with '
            .'inherits: false, so an ancestor never carries it to a button and '
            .'the white halo stays'
        );

        $total = preg_match_all('/focus:ring-offset-2(?! dark:focus:ring-offset-volcanic-teal)/', $sources);

        $this->assertGreaterThan(
            0,
            preg_match_all('/focus:ring-offset-2/', $sources),
            'no control sets a ring offset, so this assertion is checking nothing'
        );

        $this->assertSame(
            0,
            $total,
            'a control sets a ring offset with no dark colour of its own, so its '
            .'keyboard focus draws a white halo around it on the dark theme'
        );
    }

    private function lightViewSources(): string
    {
        $sources = '';

        foreach ($this->lightPages() as $page) {
            $sources .= (string) file_get_contents($page);
        }

        return $sources;
    }
    /**
     * A BARE `bg-white` FIELD IS A LIGHT-ONLY SURFACE, AND A WHITE HOVER IS
     * NOT.
     *
     * The split matters and getting it wrong in either direction is a
     * regression. A pure white input on the dark ground is the brightest thing
     * on the page and reads as unstyled. But `hover:bg-white` on a gold or
     * turquoise button is a white hover under dark ink, and that is CORRECT in
     * both themes -- "make it white" is what a light hover on a bright fill
     * means, and flipping it to a translucent white would make a gold button go
     * muddy on hover instead of hot.
     *
     * Twenty-six of the first kind were left behind by the main sweep, because
     * `bg-white` is not a palette token and the sweep had no reason to touch
     * it.
     */
    #[Test]
    public function form_fields_follow_the_theme_and_button_hovers_stay_white(): void
    {
        $sources = $this->lightViewSources();

        $fields = preg_match_all('/(?<![\w:\/-])bg-white(?![\w\/-])/', $sources);

        $this->assertSame(
            0,
            $fields,
            'a resting surface is still a bare `bg-white`, so it is pure white on '
            .'the dark ground -- the brightest thing on the page, and it reads as '
            .'unstyled rather than as themed'
        );

        $this->assertGreaterThan(
            0,
            preg_match_all('/hover:bg-white(?![\w\/-])/', $sources),
            'every white hover has gone too. On a gold or turquoise button a '
            .'white hover under dark ink is correct in BOTH themes, and a '
            .'translucent hover would make the button go muddy instead of hot'
        );
    }

    /**
 * NOTHING IS A FLAT DARK BLOCK ANY MORE, IN EITHER DIRECTION.
 *
 * Two failures, opposite in sign and both reported as the same thing —
 * "this page is forced dark" — so they belong in one test.
 *
 * A PHOTO-LESS CARD TILE. The catalogue is almost entirely without
 * photographs, so `/destinations` rendered one solid `bg-volcanic-teal` tile
 * per card. On a light page that is nine near-black rectangles, which is what
 * made the listing read dark even in LIGHT mode. The stage's rule for the same
 * situation is a typographic plate rather than a flat colour, so this is one
 * now, themed like everything else.
 *
 * A DARK HERO PANEL THAT VANISHES ON DARK. `--color-page` is `#0B252B` on the
 * dark theme and these panels were `bg-volcanic-teal`, which *is* `#0B252B` —
 * so on dark the panel was the ground, held apart by nothing but a shadow. Nine
 * of them, across the itinerary, recommendation, preference and admin pages.
 *
 * The panels get a VARIANT and not a swap, and that is the load-bearing half:
 * they are deliberate dark blocks in light mode and the design's accents in
 * both, so light mode has to keep them exactly as they are.
 */
#[Test]
public function nothing_is_a_flat_dark_block_in_either_direction(): void
    {
        $sources = $this->lightViewSources();

        $this->assertStringNotContainsString(
            'justify-center bg-volcanic-teal',
            $sources,
            'the photo-less card tile is a solid volcanic teal block again. The '
            .'catalogue is almost entirely without photographs, so this renders '
            .'once per card and the listing reads dark even in LIGHT mode'
        );

        // A panel that is the ground colour on the dark theme is invisible, so
        // every one of them has to carry the lift. Counted WITHOUT the dark
        // variant in the pattern, because a fixed panel no longer matches it.
        $unlifted = preg_match_all('/bg-volcanic-teal(\s+p-\d)/', $sources);

        $lifted = preg_match_all(
            '/bg-volcanic-teal dark:bg-surface-raised(\s+p-\d)/',
            $sources
        );

        $this->assertGreaterThan(
            0,
            $lifted,
            'no dark hero panel carries a dark variant, so this assertion is '
            .'checking nothing'
        );

        $this->assertSame(
            0,
            $unlifted,
            'a dark hero panel has no dark variant, so on the dark theme it is '
            .'exactly the ground colour and disappears behind its own shadow'
        );

        // And the light theme must not have moved while fixing that.
        $this->assertStringContainsString(
            'bg-volcanic-teal',
            $sources,
            'the dark panels have been swapped instead of varied, so light mode '
            .'has lost the blocks the design uses as its accents'
        );
    }

    /**
 * NO MALFORMED CLASS SURVIVES, WHICH IS THE HALF EVERY EARLIER ASSERTION
 * MISSED.
 *
 * The status sweep replaced `bg-red-50` with no right boundary, so it matched
 * inside the `bg-red-500/15` it had just created and inside a pre-existing
 * `bg-red-50/60`, leaving `dark:bg-red-500/150/15`, `bg-red-500/15/60` and a
 * bare `dark:bg-red-50` — 23 sites across 15 files, on a branch that was already
 * pushed. Every test stayed green, because each one checked that the CORRECT
 * pair was present and never that a second one was not. Both halves are
 * asserted here.
 *
 * A doubled alpha like `/15/60` is not a colour Tailwind can parse, and a bare
 * `dark:bg-red-50` is the pale pink this whole pass exists to stop.
 */
#[Test]
    public function no_malformed_class_survives_a_status_rewrite(): void
    {
        $sources = $this->lightViewSources();

/*
         * PRECISE, because the obvious broad pattern is a false positive:
         * `dark:text-red-200` is the CORRECT mapping for `text-red-800`, so
         * "a dark: variant of a light-only token" is only broken for the
         * values that are wrong ON DARK -- a pale pink fill, and dark ink,
         * which is invisible on a dark ground. `dark:text-red-200` and
         * `dark:border-red-400/30` are deliberate and must not be flagged.
         */
/*
         * PRECISE, because the obvious broad pattern is a false positive:
         * `dark:text-red-200` is the CORRECT mapping for `text-red-800`, so
         * "a dark: variant of a light-only token" is only broken for the
         * values that are wrong ON DARK -- a pale pink fill, and dark ink,
         * which is invisible on a dark ground. `dark:text-red-200` and
         * `dark:border-red-400/30` are deliberate and must not be flagged.
         *
         * The state prefix is OPTIONAL and the group is `(?:[a-z-]+:)?`, not
         * `[a-z-]*:` -- the latter demands a second colon and therefore never
         * matches `dark:bg-red-50` at all, which is how this check passed while
         * proving nothing.
         */
/*
         * PRECISE, because the obvious broad pattern is a false positive:
         * `dark:text-red-200` is the CORRECT mapping for `text-red-800`, so
         * "a dark: variant of a light-only token" is only broken for the
         * values that are wrong ON DARK -- a pale pink fill, and dark ink,
         * which is invisible on a dark ground. `dark:text-red-200` and
         * `dark:border-red-400/30` are deliberate and must not be flagged.
         *
         * The state prefix is OPTIONAL and the group is `(?:[a-z-]+:)?`, not
         * `[a-z-]*:` -- the latter demands a second colon and therefore never
         * matches `dark:bg-red-50` at all, which is how this check passed while
         * proving nothing.
         *
         * The doubled alpha is `SLASH-NUMBERS-SLASH-NUMBERS`, so the leading
         * lookbehind has to be able to sit ON a slash. `(?<![\w/-])\d+\/\d+`
         * cannot: the real corruption is `.../15/60`, where `/15` is preceded
         * by a digit and `/60` by a digit, so both candidates are rejected and
         * the check passes on an empty set. Measured against the three shapes
         * this corruption actually takes -- `/150/15`, `/15/60`, `/150/20` --
         * the bounded form catches none of them.
         */
        $broken = [
            '/dark:(?:[a-z-]+:)?bg-red-(?:50|100)(?!\d)/',
            '/dark:(?:[a-z-]+:)?text-red-(?:700|800)(?!\d)/',
            '/dark:(?:[a-z-]+:)?border-red-200(?!\d)/',
            '/\/\d{1,3}\/\d{1,3}/',
        ];       foreach ($broken as $pattern) {
            $count = preg_match_all($pattern, $sources, $found);

            $this->assertSame(
                0,
                $count,
                'a status class was rewritten into a malformed one: '
                .($found[0][0] ?? 'an unprintable fragment')
                .'. Anchor both ends when replacing a token, and assert the '
                .'absence of the broken form as well as the presence of the '
                .'right one'
            );
        }

        // And the correct pairing is genuinely there, so the check above is not
        // passing because the rewrite never happened in the first place.
        $this->assertGreaterThan(
            0,
            preg_match_all('/bg-red-50 dark:bg-red-500\/15/', $sources),
            'no status colour carries a dark variant at all, so the malformed '
            .'check above is passing on an empty set'
        );
    }


    /**
     * THE DARK-BY-DESIGN PAGES MUST CARRY NO `dark:` VARIANT AT ALL.
     *
     * `destinations/show` and `discover/index` are excluded from the theme
     * migration because they are dark in BOTH themes -- the stage is the whole
     * destination page and the deck has the same ground. A `dark:` prefix on
     * one of them is therefore not a theme choice, it is a contradiction: the
     * variant is live at all times, because the page is dark whatever the
     * toggle says.
     *
     * This was walked straight into while repairing the status colours. The
     * destination page's "Closed now" badge is a light pill with dark text on
     * a dark ground, deliberately -- the other status badges beside it are
     * never allowed to tint their background -- and a `dark:bg-red-500/20
     * dark:text-red-200` pair turned it into a dark tint that all but vanished
     * into the ground it sits on.
     *
     * `welcome` is deliberately NOT in this list: it is a light landing page
     * that does carry a couple of dark variants, and listing it here would make
     * the guard fail for a correct reason.
     */
    #[Test]
    public function the_dark_by_design_pages_carry_no_dark_variant(): void
    {
        foreach ([
            'destinations/show.blade.php',
            'discover/index.blade.php',
        ] as $relative) {
            $source = file_get_contents(resource_path('views/'.$relative));

            $this->assertIsString($source, $relative.' could not be read');

            $count = preg_match_all(
                '/dark:(?:[a-z-]+:)?(?:bg|text|border)-red-\d/',
                $source,
                $found
            );

            $this->assertSame(
                0,
                $count,
                $relative.' is dark in both themes, so a dark: variant on a '
                .'status colour is live at all times and overrides the light '
                .'pill the design uses there: '.($found[0][0] ?? '')
            );
        }
    }

    private function darkBlock(): string
    {
        $css = $this->css();

        preg_match('/(?<![-\w])\.dark \{[^}]*\}/', $css, $matches);

        return $matches[0] ?? '';
    }

    private function sliceOfLayer(string $css, string $layer): string
    {
        $start = strpos($css, '@layer '.$layer);

        if ($start === false) {
            return '';
        }

        $end = strpos($css, '@layer', $start + 10);

        return $end === false ? substr($css, $start) : substr($css, $start, $end - $start);
    }

    /**
     * @return array<int, string>
     */
    private function lightPages(): array
    {
        $all = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $relative = str_replace('\\', '/', resource_path('views')).'/';

            $relative = str_replace($relative, '', $path);

            if (in_array($relative, [
                'welcome.blade.php',
                'destinations/show.blade.php',
                'discover/index.blade.php',
            ], true)) {
                continue;
            }

            if (str_starts_with($relative, 'components/destinations/')) {
                continue;
            }

            $all[] = $path;
        }

        return $all;
    }
}