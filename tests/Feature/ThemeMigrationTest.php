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