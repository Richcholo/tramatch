<?php

namespace Tests\Feature;

use App\Models\Destination;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE TWO PAGES THAT WERE LEFT OUT OF THE THEME.
 *
 * Both were found by following a URL that does not exist.
 */
class ErrorPageAndGuestThemeTest extends TestCase
{
    use RefreshDatabase;

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument();

        @$document->loadHTML($html, LIBXML_NOERROR);

        return new DOMXPath($document);
    }

    /**
     * A 404 IS A PAGE OF THIS APP, NOT A BARE ERROR SCREEN.
     *
     * `destinations/{destination:slug}` swallows anything under
     * `/destinations/`, so a mistyped slug, a stale share link and a plain wrong
     * address all land here. With no `errors/404.blade.php` Laravel renders its
     * own: no navigation, no logo, no theme toggle, no way back -- and with
     * APP_DEBUG on locally, a debug screen in the middle of an otherwise themed
     * site, which is what "this page is forced dark and unstyled" looks like.
     */
    #[Test]
    public function a_missing_page_renders_in_the_app_layout_with_a_way_back(): void
    {
        $html = (string) $this->get('/destinations/locations')
            ->assertNotFound()
            ->getContent();

        $dom = $this->dom($html);

        $this->assertSame(
            1,
            $dom->query('//*[@data-theme-toggle]')->length,
            'the 404 has no theme toggle, so it is the one page in the app a '
            .'visitor cannot change the theme on'
        );

        $this->assertStringContainsString(
            route('destinations.index'),
            $html,
            'the 404 offers no route back into the site, so a wrong address reads '
            .'as the whole application being broken'
        );

        $this->assertStringContainsString(
            url('/'),
            $html,
            'the 404 has no way home'
        );

        $this->assertSame(
            1,
            $dom->query('//main//h1')->length,
            'the 404 has no heading of its own'
        );
    }

    /**
     * AN ARCHIVED DESTINATION 404s THE SAME WAY, AND THAT IS CORRECT.
     *
     * `is_active = false` is the publish switch, and the archive is deliberately
     * a real 404 rather than a "sorry, this is closed" page: a destination that
     * is not published should not be discoverable by guessing its address. The
     * point of the test is that it inherits the themed page rather than falling
     * back to a bare one.
     */
    #[Test]
    public function an_archived_destination_gets_the_themed_404_not_a_bare_one(): void
    {
        $archived = Destination::create([
            'name' => 'Gone',
            'slug' => 'gone',
            'description' => 'Archived.',
            'province' => 'Laguna',
            'municipality' => 'Real',
            'latitude' => 14.1,
            'longitude' => 121.5,
            'budget_level' => 'economy',
            'estimated_cost' => 450,
            'recommended_minutes' => 120,
            'is_active' => false,
        ]);

        $html = (string) $this->get(route('destinations.show', $archived))
            ->assertNotFound()
            ->getContent();

        $this->assertStringContainsString(
            'data-theme-toggle',
            $html,
            'an archived destination falls back to a bare error page rather than '
            .'the themed one'
        );
    }

    /**
     * THE GUEST PAGES CAN CHANGE THE THEME.
     *
     * The guest layout ran the theme script from the first commit of the
     * feature, so it painted in the visitor's saved theme -- and shipped with no
     * control to change it. Anyone who picked a theme and then signed out, or
     * followed a password-reset link, was stranded on it. A setting you cannot
     * change is not a setting.
     */
    #[Test]
    public function the_guest_pages_offer_the_theme_toggle(): void
    {
        foreach (['login', 'register', 'forgot-password'] as $route) {
            if (! Route::has($route)) {
                continue;
            }

            $html = (string) $this->get(route($route))->getContent();

            $this->assertStringContainsString(
                'data-theme-toggle',
                $html,
                'the '.$route.' page has no theme toggle. It paints in the saved '
                .'theme and offers no way out of it'
            );

            $this->assertStringContainsString(
                'window.tmTheme.toggle()',
                $html,
                'the '.$route.' toggle is not driven by the inline theme API, so '
                .'it depends on a bundle this layout never loads'
            );
        }
    }

    /**
     * THE GUEST HERO IS A DARK ISLAND AND SAYS SO, rather than pretending.
     *
     * `layouts/guest.blade.php` keeps `bg-volcanic-teal` on the body and white
     * copy over photographic art. That is NOT a missed migration and it must not
     * be "fixed" by swapping the ground for `bg-page`: the left column is white
     * text on a photograph, so a pale ground behind it is unreadable. It is the
     * same shape of decision as the destinations stage -- a surface that is
     * dark by design, in both themes.
     *
     * What it needed was the toggle, not a different ground.
     */
    #[Test]
    public function the_guest_hero_stays_a_dark_island_on_purpose(): void
    {
        $guest = (string) file_get_contents(resource_path('views/layouts/guest.blade.php'));

        $this->assertMatchesRegularExpression(
            '/<body[^>]*bg-volcanic-teal/',
            $guest,
            'the guest layout has lost its volcanic teal ground. Its copy is '
            .'white on photographic art, so a pale ground behind it would be '
            .'unreadable -- this is a dark island by design, like the stage'
        );

        $this->assertMatchesRegularExpression(
            '/<section[^>]*text-white/',
            $guest,
            'the guest hero no longer sets white copy, so the dark ground it '
            .'stands on has nothing to be light against'
        );
    }
}