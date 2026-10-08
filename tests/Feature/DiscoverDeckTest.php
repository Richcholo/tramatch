<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The discover deck, restyled into the destinations stage's world.
 *
 * The deck keeps its own job -- one place at a time, a binary
 * decision, the card flies off and the next one takes its place --
 * but it now speaks the stage's language: one dark surface from
 * the nav bar down, the two cards behind the top one fanned out
 * like the stage's flanking pair, and the same protections around
 * the gesture.
 *
 * As with the stage, there is no browser automation here, so none
 * of this can see the fan spread or the card fly off. What is
 * pinned is every contract the browser relies on, so a change that
 * breaks one fails here instead of reading as a styling preference
 * on a click-through.
 */
class DiscoverDeckTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var array<int, Destination> */
    private array $destinations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $profile = TravelProfile::create([
            'user_id' => $this->user->id,
            'budget_level' => 'economy',
            'group_size' => 2,
            'trip_duration_days' => 2,
        ]);

        $nature = Tag::create(['name' => 'Nature', 'slug' => 'nature']);

        /*
         * Three places, so the fan has a card on each side of the
         * top one and one waiting behind them. The third carries no
         * photograph, which is the normal state of a fresh install
         * and the case the typographic plate exists for.
         */
        $names = ['Caliraya Lake', 'Nagsasa Cove', 'Kiltepan View'];

        $this->destinations = [];

        foreach ($names as $index => $name) {
            $destination = Destination::create([
                'name' => $name,
                'slug' => strtolower(str_replace(' ', '-', $name)),
                'description' => 'A place in Luzon.',
                'province' => 'Laguna',
                'municipality' => 'Real',
                'latitude' => 14.1,
                'longitude' => 121.5,
                'budget_level' => 'economy',
                'estimated_cost' => 450,
                'recommended_minutes' => 120,
                'image_url' => $index < 2 ? "https://images.example.com/place-{$index}.jpg" : null,
            ]);

            $destination->tags()->attach($nature);

            $this->destinations[] = $destination;
        }

        $profile->tags()->attach($nature, ['weight' => 3]);
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument();

        @$document->loadHTML($html, LIBXML_NOERROR);

        return new DOMXPath($document);
    }

    private function deckHtml(): string
    {
        return (string) $this->actingAs($this->user)
            ->get(route('discover.index'))
            ->assertOk()
            ->getContent();
    }

    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    private function script(): string
    {
        return (string) file_get_contents(resource_path('js/swipe.js'));
    }

    /**
     * THE PAGE IS ONE DARK SURFACE, from the nav bar down.
     *
     * The layout's `main` is bare on this route (`w-full`, no
     * padding), so `.tm-page` needs none of the destination page's
     * negative-margin bleed. That is worth pinning: copying the
     * destination page's `-mx-*` classes here would overrun the
     * viewport, because there is no padding for them to cancel.
     */
    #[Test]
    public function the_page_is_one_dark_surface_edge_to_edge(): void
    {
        $dom = $this->dom($this->deckHtml());

        $page = $dom->query('//*[contains(concat(" ", normalize-space(@class), " "), " tm-page ")]')->item(0);

        $this->assertNotNull($page, 'the dark surface wrapper is missing from the page');

        $classes = (string) $page->getAttribute('class');

        $this->assertStringNotContainsString(
            '-mx-',
            $classes,
            'the page carries the destination page\'s negative margins, but `main` '
            .'is bare on this route -- there is no padding to cancel, so the teal '
            .'would overrun the viewport instead of bleeding back over it'
        );

        $this->assertSame(
            1,
            $dom->query('//*[@data-swipe-deck]')->length,
            'the deck is missing from the page'
        );
    }

    /**
     * THE TWO CARDS BEHIND THE TOP ONE FAN OUT, one each side, the
     * way the stage's flanking pair does.
     *
     * The old deck stacked them in a pile -- `translateY(index * 10px)`
     * -- which is a stack, not a fan. The formation is the stage's:
     * a horizontal offset to one side each, a rotation, a scale down
     * and a dim, so the cards behind read as depth rather than as
     * a queue.
     */
    #[Test]
    public function the_cards_behind_the_top_one_fan_out_one_each_side(): void
    {
        $script = $this->script();

        $this->assertStringContainsString(
            'const FAN_STEPS = [',
            $script,
            'the deck has no fan formation, so the cards behind the top one still stack in a pile'
        );

        $this->assertStringContainsString(
            'x: -14',
            $script,
            'the first card behind the top one does not fan out to the left'
        );

        $this->assertStringContainsString(
            'x: 14',
            $script,
            'the second card behind the top one does not fan out to the right'
        );

        $this->assertStringContainsString(
            'rotate(${step.rotate}deg) scale(${step.scale})',
            $script,
            'a fanned card is not rotated and scaled, so the formation reads as a pile with a gap in it'
        );

        $this->assertStringContainsString(
            'card.style.filter = `brightness(${step.brightness})`',
            $script,
            'a fanned card is not dimmed, so it carries no depth cue against the card in front of it'
        );

        $this->assertStringNotContainsString(
            'index * 10',
            $script,
            'the deck still offsets its cards by a flat per-index pile'
        );
    }

    /**
     * THE RESTACK ANIMATES, and through the stylesheet rather than
     * a snap.
     *
     * The dragged card's transitions are managed inline by the
     * script -- it needs them off while the finger is down -- but
     * every other card has to move between fan positions through a
     * CSS transition, or the deck snaps into its new formation
     * after every swipe instead of re-forming.
     */
    #[Test]
    public function the_restack_animates_through_the_stylesheet(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.tm-swipe-card\s*\{[^}]*transition:\s*transform 280ms ease,\s*filter 280ms ease,\s*opacity 280ms ease/s',
            $this->css(),
            'the card has no restack transition on transform, filter and opacity, '
            .'so the deck snaps into its new formation after a swipe'
        );
    }

    /**
     * THE BROWSER'S OWN DRAG CANNOT TAKE THE GESTURE.
     *
     * Every card wraps a photograph, and a photograph is draggable
     * by default: pull one sideways and the browser starts a native
     * drag-and-drop, a ghost image follows the pointer, `pointercancel`
     * fires, and the card's gesture dies halfway. That is the same
     * defect the destinations stage was fixed for, and the deck is
     * just as exposed to it.
     */
    #[Test]
    public function the_photographs_cannot_start_the_browsers_own_drag(): void
    {
        $dom = $this->dom($this->deckHtml());
        $script = $this->script();

        $images = $dom->query('//*[@data-swipe-card]//img');

        $this->assertGreaterThan(0, $images->length, 'no card photographs were rendered');

        foreach ($images as $image) {
            $this->assertSame(
                'false',
                $image->getAttribute('draggable'),
                'a card photograph is still draggable, so pulling it starts the '
                ."browser's own image drag and the card's gesture dies halfway"
            );
        }

        $this->assertStringContainsString(
            "addEventListener('dragstart'",
            $script,
            'the deck does not cancel native dragstart, so a drag starting on any '
            .'card still hands the gesture to the browser'
        );
    }

    /**
     * A SWIPE COMMITS ON A FIFTH OF THE CARD IN FRONT OF YOU.
     *
     * The threshold is measured off the card, never a fixed number,
     * because the card is the thing being grabbed and its width
     * changes with the screen: a fixed 110px is a third of the card
     * on a phone and a pull that means nothing on a wide one.
     */
    #[Test]
    public function a_swipe_commits_on_a_fifth_of_the_card(): void
    {
        $script = $this->script();

        $this->assertStringContainsString(
            'dragThreshold()',
            $script,
            'a release does not have to travel far enough to commit to a swipe'
        );

        $this->assertStringContainsString(
            'offsetWidth',
            $script,
            'the swipe threshold is not measured off the card in front of you'
        );

        $this->assertStringNotContainsString(
            '> 110',
            $script,
            'the swipe still commits on a fixed pixel distance, which is a third '
            .'of the card on a phone'
        );
    }

    /**
     * A DESTINATION WITH NO PHOTOGRAPH GETS A TYPOGRAPHIC PLATE.
     *
     * The old deck gave it a gradient and called it a photograph.
     * The stage's rule is the one to keep: a flat colour pretending
     * to be a photograph behind a scrim is a photograph the app does
     * not have. Province and municipality are set in type instead.
     */
    #[Test]
    public function a_destination_with_no_photograph_gets_a_typographic_plate(): void
    {
        $html = $this->deckHtml();
        $dom = $this->dom($html);

        $plate = $dom->query(
            '//*[@data-swipe-card]//*[contains(concat(" ", normalize-space(@class), " "), " tm-swipe-plate ")]'
        );

        $this->assertSame(
            1,
            $plate->length,
            'the photo-less destination does not get a typographic plate'
        );

        $this->assertStringContainsString(
            'Kiltepan View',
            $html,
            'the deck is missing the photo-less destination entirely'
        );

        $this->assertStringNotContainsString(
            'bg-gradient-to-br',
            $html,
            'the photo-less destination still gets a gradient pretending to be a photograph'
        );
    }

    /**
     * THE DECISION IS STILL A BINARY ONE, and every card carries both
     * doors.
     *
     * The restyle is not allowed to eat the interaction: two buttons
     * per card, the same `data-swipe-action` names the script posts,
     * and the press feedback the interaction guard requires.
     */
    #[Test]
    public function every_card_still_carries_both_decisions(): void
    {
        $dom = $this->dom($this->deckHtml());

        $this->assertSame(
            3,
            $dom->query('//*[@data-swipe-card]')->length,
            'the deck does not render every place in the deck'
        );

        $this->assertSame(
            3,
            $dom->query('//*[@data-swipe-action="passed"]')->length,
            'a card is missing its Pass decision'
        );

        $this->assertSame(
            3,
            $dom->query('//*[@data-swipe-action="liked"]')->length,
            'a card is missing its Like decision'
        );

        $this->assertStringContainsString(
            'active:scale-[0.98]',
            (string) file_get_contents(resource_path('views/discover/index.blade.php')),
            'the Like decision has no press feedback, so the click reads as ignored'
        );
    }

    /**
     * AN EMPTY DECK STAYS ON THE DARK GROUND.
     *
     * The "all caught up" state is the page the deck works towards,
     * so it wears the same surface rather than dropping back to the
     * light theme the rest of the app uses.
     */
    #[Test]
    public function an_empty_deck_stays_on_the_dark_ground(): void
    {
        foreach ($this->destinations as $destination) {
            DestinationSwipe::create([
                'user_id' => $this->user->id,
                'destination_id' => $destination->id,
                'action' => 'passed',
            ]);
        }

        $html = $this->deckHtml();

        $this->assertStringContainsString(
            'data-swipe-empty',
            $html,
            'the empty state is missing after every place has been swiped'
        );

        $this->assertStringContainsString(
            'tm-panel',
            $html,
            'the empty state is not on the dark ground'
        );

        $this->assertStringNotContainsString(
            'data-swipe-deck',
            $html,
            'the deck still renders when there is nothing left to swipe'
        );
    }

    /**
     * THE PAGE'S GROUND IS A BLURRED PHOTOGRAPH, which
     * cross-fades to the main photo of whichever card is
     * hovered.
     *
     * The destinations stage's backdrop mechanism, moved to
     * the deck: two layers, because `src` cannot be
     * cross-faded. The script paints the hidden layer with
     * the hovered card's photograph and then swaps which
     * layer is shown. Mouse-only, and per card -- a card
     * with no photograph (the typographic plate) changes
     * nothing.
     */
    #[Test]
    public function the_ground_cross_fades_to_the_hovered_cards_photograph(): void
    {
        $html = $this->deckHtml();
        $dom = $this->dom($html);
        $script = $this->script();

        $layers = $dom->query('//*[@data-discover-backdrop]');

        $this->assertSame(
            2,
            $layers->length,
            'the backdrop does not carry two layers, so the '
            .'ground cannot cross-fade between photographs'
        );

        $this->assertSame(
            1,
            $dom->query('//*[@data-discover-backdrops]')->length,
            'the backdrop container is missing from the page'
        );

        $this->assertStringContainsString(
            "[data-discover-backdrop]",
            $script,
            'the script does not read the backdrop layers'
        );

        $this->assertStringContainsString(
            "card.querySelector('img')",
            $script,
            'the backdrop is not read off the card\'s own photograph'
        );

        $this->assertStringContainsString(
            "incoming.setAttribute('src', src)",
            $script,
            'the hidden layer is not painted with the hovered '
            .'photograph before it is shown'
        );

        $this->assertStringContainsString(
            "incoming.classList.add('is-shown')",
            $script,
            'the incoming layer is not shown, so the ground never '
            .'changes'
        );

        $this->assertStringContainsString(
            "shownBackdrop.classList.remove('is-shown')",
            $script,
            'the outgoing layer is not hidden, so the ground jumps '
            .'between photographs instead of cross-fading'
        );

        $this->assertStringContainsString(
            "card.addEventListener('mouseenter'",
            $script,
            'the backdrop does not follow the card being hovered'
        );

        $this->assertStringContainsString(
            "card.addEventListener('mouseleave'",
            $script,
            'the backdrop does not return to the plain ground when '
            .'the pointer leaves the card'
        );
    }

    /**
     * THE BACKDROP SITS BEHIND EVERY WORD.
     *
     * The stage's backdrop is `z-index: 0` because every
     * child of the stage is positioned. This page's are
     * not, so the backdrop is `z-index: -1` inside the
     * page's own stacking context -- a `0` here would put
     * the wash on top of the words instead of under them.
     */
    #[Test]
    public function the_backdrop_sits_behind_every_word(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/\.tm-discover-backdrops\s*\{[^}]*z-index:\s*-1/s',
            $css,
            'the backdrop is not behind the page content. This '
            .'page\'s children are not all positioned, so a '
            .'z-index of 0 would put the wash on top of the words'
        );

        $this->assertMatchesRegularExpression(
            '/\.tm-discover-backdrop\s*\{[^}]*filter:\s*brightness\(0\.18\) blur\(14px\)/s',
            $css,
            'the backdrop is not blurred and darkened, so a '
            .'hovered photograph shows behind the page as a '
            .'sharp second image'
        );

        $this->assertMatchesRegularExpression(
            '/\.tm-discover-backdrop\.is-shown\s*\{[^}]*opacity:\s*0\.5/s',
            $css,
            'the shown backdrop layer does not fade to half '
            .'opacity, so the wash is either invisible or opaque'
        );
    }
}
