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

    /**
     * THE NO-JS FALLBACK, IN ITS NEW FORM.
     *
     * Every photograph is in the HTML with a real URL, so a stage script that
     * fails to load leaves the traveller looking at pictures rather than an empty
     * frame. The property is unchanged; only where the photographs live moved.
     *
     * The `data-src` half is deliberate and is asserted separately below. A
     * destination set is 65 destinations of up to four photographs, so rendering
     * every `src` would have the browser fetch the whole catalogue's photography
     * on first paint; the script promotes `data-src` to `src` as a panel
     * approaches. "In the HTML" is therefore the claim, not "in `src`".
     */
    #[Test]
    public function every_uploaded_photo_is_a_stage_panel_without_javascript(): void
    {
        $destination = $this->destination(['image_url' => 'https://images.example.com/hero.jpg']);

        foreach (range(1, 3) as $index) {
            $destination->images()->create([
                'path' => 'https://images.example.com/'.$index.'.jpg',
                'sort_order' => $index - 1,
            ]);
        }

        $html = $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->getContent();

        foreach (['hero.jpg', '1.jpg', '2.jpg', '3.jpg'] as $name) {
            $this->assertMatchesRegularExpression(
                '/(src|data-src)="https:\/\/images\.example\.com\/'.preg_quote($name, '/').'"/',
                (string) $html,
                $name.' is missing from the server-rendered page, so the stage would '
                .'render a panel with nothing in it if the script never loaded'
            );
        }
    }

    /**
     * The hero photograph is a PANEL, not a separate full-bleed block above the
     * stage.
     *
     * It used to be both: a hero section and, once the stage arrived, a panel.
     * That double-rendered the same photograph twice within one screenful and
     * meant a hard load showed the hero while an in-page advance showed the panel,
     * so the page's opening image depended on how you got there.
     */
    #[Test]
    public function the_hero_photograph_is_not_also_a_block_above_the_stage(): void
    {
        $destination = $this->destination(['image_url' => 'https://images.example.com/hero.jpg']);

        $dom = $this->dom($this->get(route('destinations.show', $destination))->assertOk()->getContent());

        $this->assertSame(
            1,
            $this->images($dom, 'https://images.example.com/hero.jpg'),
            'the hero photograph is rendered more than once. It belongs to the stage '
            .'as a panel, and nowhere else on the page.'
        );
    }

    /**
     * The scroll-snap photo strip is gone, in the markup, in the stylesheet and on
     * disk.
     *
     * Its three jobs are all done by the stage now: the photos are panels, the
     * arrows are the stage's chevrons, and the dots are the stage's pager. What is
     * left behind is the failure AGENTS.md already records once -- a rule and a
     * script that read like proof a feature existed while nothing rendered it.
     *
     * Checked on disk as well as in the output, because a partial that nothing
     * includes produces no markup at all and would otherwise leave nothing to
     * notice.
     */
    #[Test]
    public function the_scroll_snap_photo_strip_is_gone(): void
    {
        $destination = $this->destination([
            'image_url' => 'https://images.example.com/hero.jpg',
            'description' => 'A place. It has a second sentence that says more.',
        ]);

        $destination->images()->create(['path' => 'https://images.example.com/1.jpg', 'sort_order' => 0]);

        $html = (string) $this->get(route('destinations.show', $destination))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-gallery', $html);
        $this->assertStringNotContainsString('tm-no-scrollbar', $html);

        $this->assertFileDoesNotExist(resource_path('views/destinations/partials/carousel.blade.php'));
        $this->assertFileDoesNotExist(resource_path('js/gallery.js'));

        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringNotContainsString(
            '.tm-no-scrollbar',
            $css,
            '.tm-no-scrollbar existed only for the deleted scroll-snap track. A rule with '
            .'no markup behind it reads like proof the strip still exists.'
        );
    }

    /**
     * A destination with no photograph contributes NO panel -- not a placeholder,
     * not a gradient card.
     *
     * The stage promises every panel is a real photograph, and a flat colour
     * block is not one. Leaving photoless destinations out also removes them from
     * the chevron order, so an arrow can never lead to a page whose stage would
     * have to describe some other destination.
     *
     * Asserted by COUNTING PANELS rather than by looking for a URL, because the
     * stage on this page legitimately holds the other destination's panel. What
     * must not appear is a panel belonging to the photoless one.
     */
    #[Test]
    public function a_destination_with_no_photograph_contributes_no_stage_panel(): void
    {
        $this->destination(['image_url' => 'https://images.example.com/hero.jpg']);

        $photoless = $this->destination([
            'name' => 'Photoless Place',
            'slug' => 'photoless-place',
            'image_url' => null,
        ]);

        $dom = $this->dom($this->get(route('destinations.show', $photoless))->assertOk()->getContent());

        $panels = $dom->query('//*[@data-stage-panel]');

        $this->assertSame(
            1,
            $panels->length,
            'expected exactly one panel -- the destination that has a photograph -- on a '
            .'page for a destination that has none'
        );

        $this->assertSame(
            0,
            $dom->query('//*[@data-stage-panel][@data-stage-name="Photoless Place"]')->length,
            'a destination with no photograph was given a panel anyway, which is a flat '
            .'teal block wearing the composition of a photograph'
        );
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument();

        @$document->loadHTML($html, LIBXML_NOERROR);

        return new DOMXPath($document);
    }

    /**
     * How many PANEL `<img>` elements on the page point at this exact URL.
     *
     * Counted rather than asserted-present, because the failure this guards is
     * the same photograph appearing twice, and "the string appears" cannot tell
     * one appearance from two.
     *
     * Backdrops are excluded, and they have to be: the stage's blurred backdrop
     * IS the active photograph, so the same URL legitimately appears once as a
     * backdrop and once as the panel. Counting both would make this test fail on
     * a correct page.
     */
    private function images(DOMXPath $dom, string $url): int
    {
        return $dom->query(
            '//img[@src="'.$url.'" or @data-src="'.$url.'"][not(@data-stage-backdrop)]'
        )->length;
    }
}
