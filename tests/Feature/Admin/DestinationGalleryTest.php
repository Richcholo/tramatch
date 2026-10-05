<?php

namespace Tests\Feature\Admin;

use App\Models\Destination;
use App\Models\DestinationImage;
use App\Models\Tag;
use App\Models\User;
use DOMDocument;
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
     * The carousel is the same width as the hero, and that width is uncapped.
     *
     * This asserts a decision with a known cost, and it is here so the cost is
     * visible rather than rediscovered.
     *
     * The page container runs to max-w-[1600px], so both blocks render ~1504px
     * wide, while uploads are stored verbatim with no resize and are typically
     * 1080-1170px wide. That is a 1.3-1.4x upscale, so the photos render soft on
     * wide screens. There is a sharp version: max-w-5xl (1024px) on both, which
     * was implemented and then reverted. The owner judged the narrower pair the
     * wrong look and asked twice for the carousel to match the hero instead.
     *
     * So the honest state is: consistent and full width, knowingly soft. The real
     * fix is resizing the uploads so a 1504px box has enough pixels, which also
     * makes srcset possible -- and that needs GD or Imagick, which are absent
     * from both the CLI and XAMPP.
     *
     * If this test fails on the cap assertion, read the note above before
     * "fixing" it: re-adding the cap is a real option, but it is a design change
     * the owner rejected twice, not a bug.
     *
     * The object-fit assertions are unaffected by any of that -- cover is correct
     * at any width, and "try another object-fit" remains the wrong fix for an
     * upscale because no object-fit value prevents one.
     */
    #[Test]
    public function the_carousel_matches_the_hero_and_neither_is_capped(): void
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

        // The real requirement: they match. Compared as resolved width classes,
        // not against one shared literal, so the guard survives the cap being
        // added to both or to neither.
        $this->assertSame(
            preg_grep('/^(max-w-|mx-auto)/', $carousel),
            preg_grep('/^(max-w-|mx-auto)/', $hero),
            'the carousel and the hero must resolve to the same width, or one of '
            .'them reads as a mistake against the other'
        );

        $this->assertSame(
            [],
            preg_grep('/^max-w-/', $carousel),
            'the carousel has been capped. That was implemented and reverted: the '
            .'owner asked twice for it to match the full-width hero, accepting that '
            .'the photos are upscaled and soft. Re-adding a cap is a valid design '
            .'change but it is not a bug fix -- see the note on this test.'
        );

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
