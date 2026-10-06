<?php

namespace Tests\Feature\Admin;

use App\Models\Destination;
use App\Models\DestinationImage;
use App\Models\Tag;
use App\Models\User;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Extra destination photos: the admin upload, the cap, and the public carousel.
 *
 * The thumbnail deliberately stays on `destinations.image_url`. It is what the
 * listing cards, the swipe deck and the map popup all render, and the CSV seeds
 * it for all 65 rows, so moving it would mean a data migration and six view
 * changes to achieve nothing. The gallery is additive.
 */
class DestinationGalleryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function destination(array $attributes = []): Destination
    {
        return Destination::create(array_merge([
            'name' => 'Test Site',
            'slug' => 'test-site',
            'description' => 'A place.',
            'province' => 'Manila',
            'municipality' => 'Manila',
            'latitude' => 14.6,
            'longitude' => 120.9,
            'budget_level' => 'economy',
            'entrance_fee' => 100,
            'estimated_cost' => 500,
            'recommended_minutes' => 90,
        ], $attributes));
    }

    private function payload(Destination $destination, array $overrides = []): array
    {
        return array_merge([
            'name' => $destination->name,
            'description' => $destination->description,
            'province' => $destination->province,
            'municipality' => $destination->municipality,
            'latitude' => $destination->latitude,
            'longitude' => $destination->longitude,
            'budget_level' => $destination->budget_level,
            'entrance_fee' => $destination->entrance_fee,
            'estimated_cost' => $destination->estimated_cost,
            'recommended_minutes' => $destination->recommended_minutes,
            'is_active' => '1',
            'tags' => [Tag::firstOrCreate(['slug' => 'nature'], ['name' => 'Nature'])->id],
        ], $overrides);
    }

    private function photo(string $name = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 4, 'image/jpeg');
    }

    #[Test]
    public function an_admin_can_upload_extra_photos(): void
    {
        Storage::fake('public');

        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, [
                    'gallery' => [$this->photo('one.jpg'), $this->photo('two.jpg')],
                ])
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $destination->images()->count());

        foreach ($destination->images as $image) {
            Storage::disk('public')->assertExists($image->storagePath());
        }
    }

    /**
     * The stored path has to be an absolute URL, not a storage-relative path.
     *
     * Every view renders it straight into `src="..."`, so a relative path would
     * resolve against the current route and /destinations/boracay would request
     * /destinations/storage/destination-images/x.jpg.
     */
    #[Test]
    public function gallery_paths_are_absolute_urls(): void
    {
        Storage::fake('public');

        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, ['gallery' => [$this->photo()]])
            )
            ->assertSessionHasNoErrors();

        $this->assertStringStartsWith(
            rtrim(Storage::disk('public')->url(''), '/'),
            $destination->images()->firstOrFail()->path
        );
    }

    /**
     * Uploads keep their order.
     */
    #[Test]
    public function uploads_are_stored_in_the_order_they_were_chosen(): void
    {
        Storage::fake('public');

        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, [
                    'gallery' => [$this->photo('first.jpg'), $this->photo('second.jpg')],
                ])
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [0, 1],
            $destination->images()->pluck('sort_order')->all()
        );
    }

    /**
     * A second upload appends rather than restarting at zero.
     */
    #[Test]
    public function a_later_upload_lands_after_the_existing_ones(): void
    {
        Storage::fake('public');

        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, ['gallery' => [$this->photo('a.jpg')]])
            )
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, ['gallery' => [$this->photo('b.jpg')]])
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [0, 1],
            $destination->images()->orderBy('sort_order')->pluck('sort_order')->all()
        );
    }

    /**
     * The cap, and the part that matters: the overflow must be *reported*.
     *
     * The first version enforced this with a `max:3` validation rule, which
     * counts the files in the request and not the room left on the destination.
     * A destination already holding two photos, given two more, passes `max:3`
     * and the surplus was then dropped by the slice with no message at all.
     */
    #[Test]
    public function the_cap_is_enforced_against_the_room_left_not_the_request(): void
    {
        Storage::fake('public');

        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, [
                    'gallery' => [$this->photo('a.jpg'), $this->photo('b.jpg')],
                ])
            )
            ->assertSessionHasNoErrors();

        // Two already stored, two more offered: under `max:3`, and over the cap.
        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, [
                    'gallery' => [$this->photo('c.jpg'), $this->photo('d.jpg')],
                ])
            )
            ->assertSessionHasErrors('gallery');

        $this->assertSame(
            2,
            $destination->images()->count(),
            'a refused upload still wrote rows'
        );
    }

    #[Test]
    public function a_non_image_is_refused(): void
    {
        Storage::fake('public');

        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, [
                    'gallery' => [UploadedFile::fake()->create('payload.php', 4, 'application/x-php')],
                ])
            )
            ->assertSessionHasErrors('gallery.0');

        $this->assertSame(0, $destination->images()->count());
    }

    #[Test]
    public function removing_a_photo_deletes_the_file_too(): void
    {
        Storage::fake('public');

        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, [
                    'gallery' => [$this->photo('keep.jpg'), $this->photo('drop.jpg')],
                ])
            )
            ->assertSessionHasNoErrors();

        $keep = $destination->images()->orderBy('sort_order')->firstOrFail();
        $drop = $destination->images()->orderBy('sort_order')->skip(1)->firstOrFail();

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, ['delete_gallery_image' => [$drop->id]])
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $destination->images()->count());
        Storage::disk('public')->assertMissing($drop->storagePath());
        Storage::disk('public')->assertExists($keep->storagePath());
    }

    /**
     * Removal and upload in one save, which is how a photo gets swapped.
     *
     * Ordered deliberately: the delete runs first so that ticking one and adding
     * one is not refused by the cap.
     */
    #[Test]
    public function a_photo_can_be_swapped_in_a_single_save(): void
    {
        Storage::fake('public');

        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, [
                    'gallery' => [$this->photo('a.jpg'), $this->photo('b.jpg'), $this->photo('c.jpg')],
                ])
            )
            ->assertSessionHasNoErrors();

        $oldest = $destination->images()->orderBy('sort_order')->firstOrFail();

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, [
                    'delete_gallery_image' => [$oldest->id],
                    'gallery' => [$this->photo('new.jpg')],
                ])
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(3, $destination->images()->count());
        $this->assertNull(
            $destination->images()->find($oldest->id),
            'the replaced photo is still attached'
        );
    }

    /**
     * The ownership boundary.
     *
     * An id is attacker-supplied, so deleting by id alone would remove a photo
     * from any destination, including somebody else's. Scoped to the destination
     * being edited.
     */
    #[Test]
    public function a_photo_cannot_be_deleted_through_another_destination(): void
    {
        Storage::fake('public');

        $destination = $this->destination(['name' => 'Mine', 'slug' => 'mine']);
        $other = $this->destination(['name' => 'Theirs', 'slug' => 'theirs']);

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $other),
                $this->payload($other, ['gallery' => [$this->photo('theirs.jpg')]])
            )
            ->assertSessionHasNoErrors();

        $theirs = $other->images()->firstOrFail();

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, ['delete_gallery_image' => [$theirs->id]])
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            $other->images()->count(),
            'a photo was deleted from a destination the form was not editing'
        );
    }

    #[Test]
    public function a_non_admin_cannot_upload(): void
    {
        Storage::fake('public');

        $destination = $this->destination();

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, ['gallery' => [$this->photo()]])
            )
            ->assertForbidden();

        $this->assertSame(0, $destination->images()->count());
    }

    /**
     * The no-JS fallback.
     *
     * Every photo is in the HTML with a real src and alt, so a carousel script
     * that fails to load leaves the traveller looking at pictures rather than an
     * empty frame. This is the property that makes the progressive enhancement
     * worth doing, and it is checkable without a browser.
     */
    #[Test]
    public function every_photo_is_in_the_html_without_javascript(): void
    {
        $destination = $this->destination();

        foreach (range(1, 3) as $index) {
            $destination->images()->create([
                'path' => 'https://images.example.com/'.$index.'.jpg',
                'sort_order' => $index - 1,
            ]);
        }

        $html = $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->getContent();

        foreach (range(1, 3) as $index) {
            $this->assertStringContainsString(
                'https://images.example.com/'.$index.'.jpg',
                $html,
                'photo '.$index.' is missing from the server-rendered page'
            );
        }
    }

    /**
     * Which control gets disabled has to come from buildControls(), never from
     * the control's own markup.
     *
     * paint() used to decide by asking each button whether it had a
     * `dataset.galleryPrev`, while buildControls() wrote `dataset[key]` where key
     * was the *keyboard shortcut* -- so the attribute it actually wrote was
     * data-arrow-left. The check was therefore never true for either button, so
     * both fell through to the "last slide" branch: reaching the final photo
     * disabled Previous as well and there was no way back. One argument doing
     * two unrelated jobs, with the two halves never wired to each other.
     *
     * There is no JS test runner in this project, so this reads the source to
     * pin that the two halves agree. That is weaker than executing it, but it
     * fails the moment they drift apart again, which is the thing that actually
     * happened -- and the symptom was invisible to every test that existed.
     */
    #[Test]
    public function the_previous_control_is_only_disabled_on_the_first_photo(): void
    {
        $source = (string) file_get_contents(resource_path('js/gallery.js'));

        /*
         * Matched on the whole expression rather than on the bare property name,
         * so the prose in paint() explaining what went wrong does not trip it.
         */
        $this->assertFalse(
            str_contains($source, 'dataset.galleryPrev !== undefined'),
            'paint() is identifying the previous button by sniffing dataset.galleryPrev '
            .'again. buildControls() does not write that attribute, so the test is never '
            .'true and both buttons end up disabled on the last photo, stranding the '
            .'traveller on the final image with no way back.'
        );

        $this->assertMatchesRegularExpression(
            '/state\.prev\.disabled\s*=\s*state\.current === 0/',
            $source,
            'the previous button is no longer disabled on the first photo'
        );

        $this->assertMatchesRegularExpression(
            '/state\.next\.disabled\s*=\s*state\.current === state\.slides\.length - 1/',
            $source,
            'the next button is no longer disabled on the last photo'
        );

        // paint() can only reach the buttons if buildControls() hands them over.
        $this->assertMatchesRegularExpression(
            '/Object\.assign\(\s*state\s*,\s*\{[^}]*\bprev:\s*previous\b[^}]*\bnext\b[^}]*\}\s*\)/s',
            $source,
            'buildControls() no longer puts prev and next on state, so paint() has '
            .'nothing to disable and neither arrow is ever disabled'
        );
    }

    /**
     * Both blocks are inset cards at the container width, and neither is capped.
     *
     * The layout has been through four versions and this pins the one that is live.
     * Carousel above the hero as inset cards; then hero-first full-bleed via
     * `width: 100vw`, which came out off-centre by half a scrollbar and was
     * described as "not smooth"; then full-bleed via negative margins, aligned but
     * too wide for the page; then down beside the location map, which left half the
     * page empty and shrank the photos to 40% of the container. Now back under the
     * hero at full container width.
     *
     * The cap assertion carries a decision with a known cost, recorded so the cost
     * is visible rather than rediscovered as a bug. The container's content box is
     * roughly 1504px on a 1600px viewport, while uploads are stored verbatim with no
     * resize at typically 1080-1170px wide, so this box UPSCALES about 1.3-1.4x and
     * the photos are soft on a wide screen. Narrower versions were implemented and
     * reverted more than once, each time sharp and each time judged too small. If
     * this ever fails on the cap assertion, read the note before "fixing" it:
     * adding a cap is a design change to raise, not a bug.
     *
     * The object-fit assertions are independent of all that -- cover is correct at
     * any size, and "try another object-fit" remains the wrong fix for an upscale
     * because no object-fit value prevents one.
     */
    #[Test]
    public function both_blocks_are_inset_cards_with_rounded_corners(): void
    {
        // image_url set deliberately: without it the hero renders a gradient div
        // instead of an <img>, and the object-fit assertions would have nothing
        // to look at and quietly stop testing anything.
        $destination = $this->destination([
            'image_url' => 'https://images.example.com/hero.jpg',
        ]);

        foreach (range(1, 2) as $index) {
            $destination->images()->create([
                'path' => 'https://images.example.com/'.$index.'.jpg',
                'sort_order' => $index - 1,
            ]);
        }

        $html = $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->getContent();

        $document = new DOMDocument();

        @$document->loadHTML($html);

        libxml_clear_errors();

        $xpath = new DOMXPath($document);

        $classesAt = function (string $query) use ($xpath): array {
            $nodes = $xpath->query($query);

            $this->assertGreaterThan(
                0,
                $nodes->length,
                'nothing on the rendered page matched '.$query.', so this test is '
                .'asserting on nothing'
            );

            return preg_split(
                '/\s+/',
                trim($nodes->item(0)->getAttribute('class')),
                -1,
                PREG_SPLIT_NO_EMPTY
            );
        };

        // The hero is identified as the section carrying the destination's title,
        // not by its position, so inserting a section above it does not silently
        // repoint these assertions at the wrong element.
        $carousel = $classesAt('//*[@data-gallery]');
        $hero = $classesAt('//main//section[.//h1]');

        /*
         * Both are inset cards again, inside the max-w-[1600px] container, and
         * they now share a radius.
         *
         * The rounded corners are the tell: three layouts have been tried (inset
         * cards, full-bleed via 100vw, full-bleed via negative margins) plus a
         * move down beside the location map, and this is back to the first.
         *
         * The radii are asserted EQUAL now, where they were deliberately different.
         * The carousel spent a while at 1.5rem to match the map card beside it,
         * and 2rem when it sat directly under the hero. It is back under the hero,
         * so it matches the hero -- two adjacent media blocks at one radius read as
         * a single unit, which is the intent. A mismatch here would mean the layout
         * moved without the radius following.
         */
        foreach (['carousel' => $carousel, 'hero' => $hero] as $name => $list) {
            $this->assertContains(
                'rounded-[2rem]',
                $list,
                'the '.$name.' is not a rounded inset card, so the layout has drifted '
                .'from "both blocks are inset cards at the container width"'
            );
        }

        // And no escape mechanism crept back onto either of them.
        foreach (['carousel' => $carousel, 'hero' => $hero] as $name => $list) {
            $this->assertNotContains(
                'tm-full-bleed',
                $list,
                'the '.$name.' is using .tm-full-bleed again. It was '
                .'width:100vw + margin-inline:calc(50% - 50vw), which lands '
                .'roughly half a scrollbar off-centre. Both blocks are inset cards.'
            );

            $this->assertSame(
                [],
                preg_grep('/^-?m?x-/', $list),
                'the '.$name.' carries a horizontal margin again. The full-bleed '
                .'attempt cancelled the container padding with -mx-* and that '
                .'version was abandoned too; these are plain inset cards.'
            );
        }

        foreach ([
            'carousel photo' => '//*[@data-gallery-track]//img',
            'hero photo' => '//main//section[.//h1]//img',
        ] as $name => $query) {
            $classes = $classesAt($query);

            $this->assertContains(
                'object-cover',
                $classes,
                'the '.$name.' no longer uses object-cover, so it is stretched to '
                .'the box instead of cropped to it'
            );

            $this->assertNotContains(
                'object-fill',
                $classes,
                'object-fill stretches an image to the box rather than cropping it, '
                .'which distorts the aspect ratio'
            );
        }
    }

    /**
     * The carousel sits directly under the hero, before everything else.
     *
     * The order matters for more than looks: the hero carries the page's <h1>, so
     * a carousel rendered first hands a screen reader and the tab order the photo
     * strip before the destination's name.
     *
     * The position has changed several times -- above the hero, full-bleed under
     * it, beside the location map, and now back here at full width -- so this
     * asserts ADJACENCY to the hero rather than an index. An index assertion
     * breaks the moment anything is inserted above, and would not have caught the
     * carousel drifting down into the content, which is exactly the move that was
     * made and then undone.
     */
    #[Test]
    public function the_carousel_sits_directly_under_the_hero(): void
    {
        $destination = $this->destination([
            'image_url' => 'https://images.example.com/hero.jpg',
        ]);

        foreach (range(1, 2) as $index) {
            $destination->images()->create([
                'path' => 'https://images.example.com/'.$index.'.jpg',
                'sort_order' => $index - 1,
            ]);
        }

        $html = $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->getContent();

        $document = new DOMDocument();

        @$document->loadHTML($html);

        libxml_clear_errors();

        $xpath = new DOMXPath($document);

        $carousel = $xpath->query('//*[@data-gallery]')->item(0);
        $hero = $xpath->query('//main//section[.//h1]')->item(0);

        $this->assertNotNull($carousel, 'the carousel is missing from the page');
        $this->assertNotNull($hero, 'the hero is missing from the page');

        // Source order, read off the parse order the DOM preserves rather than off
        // a class: visual order can differ from DOM order, and it is the DOM a
        // screen reader and the tab order follow.
        $this->assertSame(
            DOMNode::DOCUMENT_POSITION_FOLLOWING,
            $hero->compareDocumentPosition($carousel)
                & DOMNode::DOCUMENT_POSITION_FOLLOWING,
            'the carousel must come after the hero in the DOM, so the <h1> in the '
            .'hero is reached before the photo strip'
        );

        /*
         * DIRECTLY under it -- sibling, and the next element along.
         *
         * The adjacency is the point, not just the order. The carousel sat below
         * the location map for a while, which is still "after the hero" and still
         * in the right sequence, but it is not what was asked for: it put the
         * photos at 40% of the container and left half the page empty. Comparing
         * nextElementSibling catches that; comparing document order alone does not.
         */
        $this->assertSame(
            $carousel,
            $hero->nextElementSibling,
            'the carousel is not the element immediately after the hero, so it has '
            .'drifted down the page again. It belongs directly beneath the hero, at '
            .'the full container width.'
        );
    }

    /**
     * No viewport-unit full-bleed, and no body clip to hide its overshoot.
     *
     * A guard against re-introducing the approach that shipped and had to be
     * taken back out. `.tm-full-bleed` was `width: 100vw` plus
     * `margin-inline: calc(50% - 50vw)`, and it produced a band off-centre by
     * roughly half a scrollbar: 100vw includes the vertical scrollbar, the excess
     * got split across both edges by the 50vw centring, and body's
     * `overflow-x: clip` only took one of them. One edge visibly short, and the
     * hero's background showing through where the image was cut. It read as "not
     * smooth" and it was a misalignment, not a preference.
     *
     * Both halves are asserted absent, because either alone reintroduces it: a
     * body clip with no 100vw block does nothing, and a 100vw block with no clip
     * pushes a horizontal scrollbar onto the page.
     *
     * Note `assertSame(0, preg_match(...))`, not `assertFalse`: preg_match returns
     * int 0 on no match and assertFalse is strict, so it fails on a correct
     * absence. That bit me while writing this.
     */
    #[Test]
    public function the_viewport_unit_full_bleed_is_not_reintroduced(): void
    {
        $stylesheet = (string) file_get_contents(resource_path('css/app.css'));

        // Matches only an actual rule, so the comment recording why this was
        // removed does not trip it.
        $this->assertSame(
            0,
            preg_match('/^\s*\.tm-full-bleed\s*\{[^}]*\}/ms', $stylesheet),
            '.tm-full-bleed is back. It was width:100vw + margin-inline:calc(50% - 50vw), '
            .'which is off-centre by half a scrollbar because 100vw includes the '
            .'scrollbar. The blocks are inset cards inside the container.'
        );

        $this->assertSame(
            0,
            preg_match('/\bbody\s*\{[^}]*overflow-x/s', $stylesheet),
            'body has an overflow-x rule again. It only existed to swallow the '
            .'100vw overshoot from .tm-full-bleed. Note it must never be `hidden` '
            .'if it ever returns: hidden creates a scroll container and would '
            .'break the itinerary editor\'s sticky save bar.'
        );

        /*
         * Nothing in the markup reintroduces it either.
         *
         * Stripped of Blade comments first, because both destination views carry a
         * comment explaining that 100vw was tried and removed. Asserting on the raw
         * text would match that explanation and fail on a page that is correct --
         * which is what happened when this was written.
         *
         * Deliberately NO assertion about negative margins. The blocks are inset
         * again, in the container, with their rounded corners, and the carousel now
         * lives beside the location map rather than under the hero -- so the `-mx-*`
         * mechanism that briefly replaced 100vw is gone too. Pinning it would be
         * pinning a layout that has since been changed on purpose.
         */
        foreach ([
            resource_path('views/destinations/show.blade.php'),
            resource_path('views/destinations/partials/carousel.blade.php'),
        ] as $view) {
            $markup = (string) file_get_contents($view);
            $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $markup);

            $this->assertSame(
                0,
                preg_match('/\b100vw\b/', (string) $markup),
                basename($view).' uses a 100vw width again, which lands off-centre by '
                .'half a scrollbar because 100vw includes the vertical scrollbar. '
                .'The blocks are inset cards inside the container.'
            );
        }
    }

    /**
     * The wrapper around the two blocks must not clip them.
     *
     * This one shipped broken. The wrapper was given `overflow-x-clip`, which
     * reads as harmless next to the 100vw blocks -- but the wrapper sits INSIDE
     * max-w-[1600px], so clipping at it cuts the blocks back to the container
     * width. The result was a band that was neither full-bleed nor inset, which
     * is exactly what "it doesn't look smooth" turned out to be.
     *
     * The overshoot clip belongs on body, which is outside the container. Any
     * overflow rule on an ancestor *inside* the container defeats full-bleed, so
     * this asserts the wrapper carries no overflow utility at all rather than
     * asserting it carries the right one -- there is no right one to carry.
     */
    #[Test]
    public function the_wrapper_around_the_two_blocks_does_not_clip_them(): void
    {
        $destination = $this->destination([
            'image_url' => 'https://images.example.com/hero.jpg',
        ]);

        foreach (range(1, 2) as $index) {
            $destination->images()->create([
                'path' => 'https://images.example.com/'.$index.'.jpg',
                'sort_order' => $index - 1,
            ]);
        }

        $html = $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->getContent();

        $document = new DOMDocument();

        @$document->loadHTML($html);

        libxml_clear_errors();

        $xpath = new DOMXPath($document);

        $carousel = $xpath->query('//*[@data-gallery]')->item(0);

        $this->assertNotNull($carousel, 'the carousel is missing from the page');

        // Every ancestor up to <main>. The container starts at main, so anything
        // between main and the block that clips will cut it back.
        for ($node = $carousel->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            $classes = preg_split(
                '/\s+/',
                trim($node->getAttribute('class')),
                -1,
                PREG_SPLIT_NO_EMPTY
            );

            $clipping = preg_grep('/^overflow(-x)?-(hidden|clip|auto|scroll)$/', $classes);

            $this->assertSame(
                [],
                $clipping,
                'a <'.$node->nodeName.'> between <main> and the carousel clips '
                .'overflow ('.implode(' ', $clipping).'). That ancestor is inside '
                .'max-w-[1600px], so it cuts the full-bleed blocks back to the '
                .'container width and they read as neither full-bleed nor inset. '
                .'The overshoot clip belongs on body, outside the container.'
            );

            if (strtolower($node->nodeName) === 'main') {
                break;
            }
        }
    }

    /**
     * The track hides its scrollbar, and the class that does it still exists.
     *
     * Two halves, and the second is the one worth having. `tm-no-scrollbar` in
     * the markup is only worth anything while app.css still defines it, and
     * deleting the rule leaves the markup looking perfectly correct while the
     * scrollbar quietly comes back. InteractionMarkupTest already guards the
     * inverse -- CSS styling a `data-*` attribute nothing produces -- but nothing
     * covered a class in markup with no rule behind it.
     *
     * Both spellings are asserted because one is not enough: scrollbar-width
     * covers Firefox and now Chrome, and the -webkit- pseudo-element covers the
     * rest. Dropping either silently reintroduces the bar on some browsers.
     *
     * The buttons being 10px up and more transparent is deliberately not pinned.
     * Those are cosmetic and carry no contract; pinning a Tailwind fraction here
     * would only make the next deliberate tweak look like a failure.
     */
    #[Test]
    public function the_track_hides_its_scrollbar_and_the_rule_still_exists(): void
    {
        $destination = $this->destination();

        foreach (range(1, 2) as $index) {
            $destination->images()->create([
                'path' => 'https://images.example.com/'.$index.'.jpg',
                'sort_order' => $index - 1,
            ]);
        }

        $html = $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->getContent();

        $document = new DOMDocument();

        @$document->loadHTML($html);

        libxml_clear_errors();

        $xpath = new DOMXPath($document);

        $track = $xpath->query('//*[@data-gallery-track]')->item(0);

        $this->assertNotNull(
            $track,
            'the carousel track is missing from the rendered page'
        );

        $classes = preg_split(
            '/\s+/',
            trim($track->getAttribute('class')),
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        $this->assertContains(
            'tm-no-scrollbar',
            $classes,
            'the carousel track no longer hides its scrollbar, so a horizontal bar '
            .'sits under the arrows'
        );

        // Still scrollable, just not visibly so: arrows, dots, keyboard and
        // swipe all drive it, and gallery.js scrollTo() depends on it.
        $this->assertContains(
            'overflow-x-auto',
            $classes,
            'the track must keep overflow-x-auto -- hiding the scrollbar is not a '
            .'reason to stop scrolling, and goTo() relies on scrollLeft'
        );

        $stylesheet = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\.tm-no-scrollbar\s*\{[^}]*scrollbar-width:\s*none/',
            $stylesheet,
            'tm-no-scrollbar has no rule in app.css, so the class on the track does '
            .'nothing and the scrollbar returns while the markup still looks right'
        );

        $this->assertMatchesRegularExpression(
            '/\.tm-no-scrollbar::\-webkit-scrollbar\s*\{[^}]*display:\s*none/',
            $stylesheet,
            'tm-no-scrollbar is missing the -webkit-scrollbar rule, so the scrollbar '
            .'still shows on Chromium browsers'
        );
    }

    /**
     * A single extra photo is not a carousel.
     */
    #[Test]
    public function one_photo_does_not_become_a_carousel(): void
    {
        $destination = $this->destination();

        $destination->images()->create([
            'path' => 'https://images.example.com/only.jpg',
            'sort_order' => 0,
        ]);

        $html = $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'data-gallery',
            $html,
            'a destination with one extra photo should render it plainly, not as a carousel'
        );
    }

    /**
     * The controls are built by script, so the server must not ship dead arrows.
     */
    #[Test]
    public function no_controls_are_rendered_without_javascript(): void
    {
        $destination = $this->destination();

        foreach (range(1, 2) as $index) {
            $destination->images()->create([
                'path' => 'https://images.example.com/'.$index.'.jpg',
                'sort_order' => $index - 1,
            ]);
        }

        $html = $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-gallery-controls', $html);

        $controls = substr(
            $html,
            strpos($html, 'data-gallery-controls') ?: 0,
            200
        );

        $this->assertStringNotContainsString(
            '<button',
            $controls,
            'a rendered button with no script behind it is a dead control'
        );
    }

    #[Test]
    public function the_cap_constant_is_three(): void
    {
        $this->assertSame(3, DestinationImage::MAX_PER_DESTINATION);
    }

    /**
     * nextSortOrder must not collide with the first slot.
     *
     * `max()` returns null on an empty table, and casting that null to int gives
     * 0 -- which is also the first row's slot. Two photos would then share
     * sort_order and the carousel order would depend on the primary key.
     */
    #[Test]
    public function the_first_slot_is_zero_and_subsequent_ones_increment(): void
    {
        $destination = $this->destination();

        $this->assertSame(0, DestinationImage::nextSortOrder($destination->id));

        $destination->images()->create(['path' => 'a.jpg', 'sort_order' => 0]);
        $this->assertSame(1, DestinationImage::nextSortOrder($destination->id));

        $destination->images()->create(['path' => 'b.jpg', 'sort_order' => 1]);
        $this->assertSame(2, DestinationImage::nextSortOrder($destination->id));
    }
}
