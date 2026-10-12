<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE THEME TOGGLE: applied before first paint, and working with no bundle.
 *
 * A theme switch is the one feature whose entire job is to happen before
 * anything is visible. Everything here is about that: the class lands before
 * the stylesheet is even parsed, and the control does not depend on a script
 * having loaded -- because half this site's pages never load one.
 *
 * `RefreshDatabase` is needed even though this file asserts on markup: the pages
 * it renders (`/destinations` and `/login`) are real requests, and
 * `/destinations` queries the catalogue. A test that renders a page without the
 * database is a test that cannot render a page.
 */
class ThemeToggleTest extends TestCase
{
    use RefreshDatabase;

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument();

        @$document->loadHTML($html, LIBXML_NOERROR);

        return new DOMXPath($document);
    }

    /**
     * THE CLASS IS APPLIED BEFORE THE STYLESHEET, ON BOTH LAYOUTS.
     *
     * Not "early in the body" and not "before `</head>`" -- before the first
     * stylesheet, because a deferred script cannot get ahead of the paint. A
     * bundle-delivered version of this would flash the light theme on a
     * dark-first site, which is worse here than usual: the destination page and
     * the deck are dark by design rather than by preference, so the flash is not
     * a shade of grey, it is the whole ground changing.
     */
    #[Test]
    public function the_theme_is_applied_before_the_stylesheet_on_both_layouts(): void
    {
        $pages = [
            'the app layout' => route('destinations.index'),
            'the guest layout' => route('login'),
        ];

        foreach ($pages as $label => $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            $script = strpos($html, 'window.tmTheme');
            $stylesheet = strpos($html, 'rel="stylesheet"');

            $this->assertNotFalse(
                $script,
                $label.' never runs the theme script, so the page always paints '
                .'in the default theme'
            );

            $this->assertNotFalse(
                $stylesheet,
                $label.' rendered no stylesheet at all, so this assertion cannot '
                .'mean anything'
            );

            $this->assertLessThan(
                $stylesheet,
                $script,
                $label.' applies the theme AFTER its stylesheet, so the page '
                .'paints in the wrong theme first and then corrects itself'
            );
        }
    }

    /**
     * THE TOGGLE IS ON THE HEADER, TO THE LEFT OF THE BURGER.
     *
     * Left because that is the reading order, and because it is the less
     * destructive of the two controls: changing your theme cannot lose
     * anything, opening the menu can.
     */
    #[Test]
    public function the_toggle_sits_to_the_left_of_the_burger(): void
    {
        $html = (string) $this->get(route('destinations.index'))->assertOk()->getContent();

        $toggle = strpos($html, 'data-theme-toggle');
        $burger = strpos($html, 'Toggle navigation menu');

        $this->assertNotFalse($toggle, 'the theme toggle is missing from the header');
        $this->assertNotFalse($burger, 'the burger menu is missing from the header');

        $this->assertLessThan(
            $burger,
            $toggle,
            'the theme toggle renders to the RIGHT of the burger menu, so the '
            .'reading order is the destructive control first'
        );
    }

    /**
     * IT WORKS WITH NO BUNDLE, WHICH IS WHY IT IS AN INLINE `onclick`.
     *
     * The guest pages load `page-transitions.js` and never load `app.js`, so
     * there is no bundle on those pages to bind a listener into. A toggle wired
     * through Alpine or a module import would work on every page except the ones
     * where a visitor most wants to change the theme.
     */
    #[Test]
    public function the_toggle_needs_no_bundle_to_work(): void
    {
        $dom = $this->dom(
            (string) $this->get(route('destinations.index'))->assertOk()->getContent()
        );

        $toggle = $dom->query('//*[@data-theme-toggle]')->item(0);

        $this->assertNotNull($toggle, 'the theme toggle is not on the page');

        $markup = (string) $toggle->ownerDocument->saveHTML($toggle);

        $this->assertStringContainsString(
            'window.tmTheme.toggle()',
            $markup,
            'the toggle is not driven by the inline theme API, so it depends on a '
            .'bundle the guest pages never load'
        );

        foreach (['x-on:', 'x-data', '@click'] as $alpineish) {
            $this->assertStringNotContainsString(
                $alpineish,
                $markup,
                'the toggle is bound with '.$alpineish.', which needs a bundle. On '
                .'the guest pages there is no bundle, so the control would be inert'
            );
        }
    }

    /**
     * THE ORDER OF PREFERENCE IS STORED CHOICE, THEN THE OS, THEN DARK.
     *
     * A stored choice has to beat `prefers-color-scheme`, because somebody who
     * has pressed the toggle once has said what they want and is not asking to
     * be second-guessed every time their laptop changes wallpaper.
     */
    #[Test]
    public function a_stored_choice_beats_the_operating_system(): void
    {
        $script = (string) file_get_contents(
            resource_path('views/components/theme-init.blade.php')
        );

        $this->assertStringContainsString(
            "localStorage.getItem(key)",
            $script,
            'the theme does not remember an explicit choice, so the toggle resets '
            .'on every page load'
        );

        $this->assertStringContainsString(
            "prefers-color-scheme: dark",
            $script,
            'the theme does not follow the operating system on a first visit'
        );

        $this->assertMatchesRegularExpression(
            "/stored === 'dark' \|\| stored === 'light' \? stored :/",
            $script,
            'the stored choice does not take precedence over the OS preference'
        );

        $this->assertStringContainsString(
            'localStorage.setItem(key, next)',
            $script,
            'the theme is applied but never saved, so the toggle does not persist'
        );
    }

    /**
     * A BROWSER THAT CANNOT REMEMBER IS NOT A BROWSER THAT BREAKS.
     *
     * Private browsing and storage-disabled profiles both throw on
     * `localStorage`. The theme still has to apply for this page view; it just
     * will not be remembered. An uncaught throw here happens before the rest of
     * the page's JavaScript, so the cost is not a missing preference, it is a
     * broken page.
     */
    #[Test]
    public function storage_failure_is_caught_rather_than_thrown(): void
    {
        $script = (string) file_get_contents(
            resource_path('views/components/theme-init.blade.php')
        );

        $this->assertSame(
            2,
            substr_count($script, 'catch (error)'),
            'both localStorage calls -- reading the stored choice and writing the '
            .'new one -- need a catch, and a throw in either takes the rest of '
            .'the page\'s JavaScript down with it'
        );

        $this->assertStringContainsString(
            'root.dataset.theme',
            $script,
            'the applied theme is not readable back, so the toggle has no way to '
            .'know what to switch FROM'
        );
    }

    /**
     * THE GLYPH AND THE NAME SWAP IN CSS, AND THE HIDDEN ONE LEAVES THE
     * ACCESSIBILITY TREE.
     *
     * There is no `x-cloak` rule in this stylesheet, so an `x-show` pair paints
     * both glyphs for a frame before Alpine initialises -- the same trap the
     * stage's pause control records. And the accessible name has to change with
     * the theme too, so the two labels are separate elements and `display: none`
     * decides which one exists: only `display: none` takes an element out of the
     * accessibility tree, so a button named by whichever half is rendered cannot
     * claim to do the opposite of what it does.
     */
    #[Test]
    public function the_glyph_and_the_name_are_swapped_by_css_not_by_script(): void
    {
        $dom = $this->dom(
            (string) $this->get(route('destinations.index'))->assertOk()->getContent()
        );

        $toggle = $dom->query('//*[@data-theme-toggle]')->item(0);
        $markup = (string) $toggle->ownerDocument->saveHTML($toggle);

        foreach (['Switch to dark theme', 'Switch to light theme'] as $label) {
            $this->assertStringContainsString(
                $label,
                $markup,
                'the toggle is missing the "'.$label.'" label, so its accessible '
                .'name never says what pressing it will do'
            );
        }

        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\.tm-theme-toggle__sun,\s*\n\s*\.tm-theme-toggle__to-light \{\s*\n\s*display: none;/',
            $css,
            'the sun and the "switch to light" label are not hidden by default'
        );

        $this->assertMatchesRegularExpression(
            '/\.dark \.tm-theme-toggle__to-light \{\s*\n\s*display: block;/',
            $css,
            'the "switch to light" label is not the one shown on the dark ground'
        );
    }

    /**
     * `color-scheme`, SO THE BROWSER'S OWN WIDGETS AGREE.
     *
     * Without it, `dark:` classes repaint the page while scrollbars, form
     * controls and the caret stay light, which reads as a half-broken theme
     * rather than a chosen one.
     */
    #[Test]
    public function the_document_declares_its_colour_scheme(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/html\.dark \{\s*\n\s*color-scheme: dark;/',
            $css,
            'the dark theme does not set color-scheme, so native widgets stay '
            .'light on a dark page'
        );

        $this->assertMatchesRegularExpression(
            '/html \{\s*\n\s*background-color: var\(--color-palawan-sand\);\s*\n\s*color-scheme: light;/',
            $css,
            'the light theme does not declare color-scheme: light'
        );
    }
}