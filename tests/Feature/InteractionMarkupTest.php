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
