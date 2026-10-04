<?php

namespace Tests\Feature\Admin;

use App\Models\Destination;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Admin destination photo upload.
 *
 * Every test here is about a way this could be a silent no-op, because that is
 * what an upload failure looks like from the admin's side: the form says
 * "Destination updated.", the photo is simply still the old one, and there is
 * nothing anywhere reporting a problem.
 *
 * Two of those traps are historical, not hypothetical:
 *
 *  - A file input inside a form without enctype="multipart/form-data" sends
 *    no file at all. PHP discards $_FILES and the request looks like a plain
 *    one, so `$request->file('image')` is null and the photo silently does not
 *    change. `both_admin_forms_declare_multipart` pins the attribute.
 *  - Dropping the image_url field without thinking about the other 20-odd
 *    inputs meant a destination's photo was wiped every time anyone edited its
 *    description. `editing_another_field_leaves_the_photo_alone` pins that the
 *    column is untouched when no file is sent.
 */
class DestinationImageUploadTest extends TestCase
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

    /**
     * Every field the admin form posts, so a test can change one thing at a
     * time. Mirrors admin/destinations/_form.blade.php.
     */
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
            // required|array|min:1 -- an empty array is a validation failure,
            // not a "no tags".
            'tags' => [Tag::firstOrCreate(['slug' => 'nature'], ['name' => 'Nature'])->id],
        ], $overrides);
    }

    private function fakeImage(string $name = 'photo.jpg', int $kilobytes = 4, string $mime = 'image/jpeg'): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, $mime);
    }

    #[Test]
    public function both_admin_forms_declare_multipart(): void
    {
        $destination = $this->destination();

        $pages = [
            'create' => route('admin.destinations.create'),
            'edit' => route('admin.destinations.edit', $destination),
        ];

        foreach ($pages as $name => $url) {
            $html = $this->actingAs($this->admin())->get($url)->assertOk()->getContent();

            $this->assertStringContainsString(
                'enctype="multipart/form-data"',
                $html,
                'the '.$name.' form has no enctype, so PHP discards the uploaded file and the '
                .'photo silently never changes'
            );

            $this->assertStringContainsString(
                'name="image"',
                $html,
                'the '.$name.' form has no file input named "image"'
            );
        }
    }

    #[Test]
    public function the_admin_form_no_longer_offers_an_image_url_input(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('admin.destinations.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'name="image_url"',
            $html,
            'the URL field is meant to be replaced by the upload'
        );
    }

    #[Test]
    public function uploading_a_photo_stores_an_absolute_url(): void
    {
        Storage::fake('public');

        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, ['image' => $this->fakeImage()])
            )
            ->assertRedirect(route('admin.destinations.index'));

        $destination->refresh();

        Storage::disk('public')->assertExists($destination->imagePath());

        $this->assertStringStartsWith(
            rtrim(Storage::disk('public')->url(''), '/').'/'.Destination::IMAGE_DIRECTORY,
            $destination->image_url,
            'image_url is rendered raw as an <img src> in six views, so a relative path would '
            .'be resolved against the current route'
        );
    }

    #[Test]
    public function creating_with_a_photo_stores_it_too(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->post(route('admin.destinations.store'), $this->payload(
                // No destination row yet -- store() derives the slug from the
                // name, so posting the same name twice is a unique violation.
                $this->destination(),
                ['image' => $this->fakeImage(), 'name' => 'Brand New Place'],
            ))
            ->assertRedirect(route('admin.destinations.index'));

        $created = Destination::where('name', 'Brand New Place')->firstOrFail();

        Storage::disk('public')->assertExists($created->imagePath());
    }

    #[Test]
    public function editing_another_field_leaves_the_photo_alone(): void
    {
        Storage::fake('public');

        $destination = $this->destination();
        $original = 'https://images.example.com/boracay.jpg';

        $destination->update(['image_url' => $original]);

        $this->actingAs($this->admin())
            ->put(route('admin.destinations.update', $destination), $this->payload($destination, [
                'description' => 'A better description.',
            ]))
            ->assertRedirect(route('admin.destinations.index'));

        $this->assertSame(
            $original,
            $destination->refresh()->image_url,
            'a description edit wiped the photo; the admin was told "Destination updated." '
            .'and nothing reported a problem'
        );
    }

    #[Test]
    public function replacing_an_uploaded_photo_deletes_the_old_file(): void
    {
        Storage::fake('public');

        $destination = $this->destination();

        $this->actingAs($this->admin())->put(
            route('admin.destinations.update', $destination),
            $this->payload($destination, ['image' => $this->fakeImage('first.jpg')])
        );

        $firstPath = $destination->refresh()->imagePath();

        $this->actingAs($this->admin())->put(
            route('admin.destinations.update', $destination),
            $this->payload($destination, ['image' => $this->fakeImage('second.jpg')])
        );

        $secondPath = $destination->refresh()->imagePath();

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    #[Test]
    public function replacing_a_seeded_external_url_does_not_delete_anything(): void
    {
        Storage::fake('public');

        $destination = $this->destination([
            'image_url' => 'https://images.example.com/boracay.jpg',
        ]);

        $this->actingAs($this->admin())->put(
            route('admin.destinations.update', $destination),
            $this->payload($destination, ['image' => $this->fakeImage()])
        );

        $this->assertStringStartsWith(
            rtrim(Storage::disk('public')->url(''), '/').'/'.Destination::IMAGE_DIRECTORY,
            $destination->refresh()->image_url
        );

        // The external URL is not ours, so it must not be resolvable to a path
        // on our disk. Had imagePath() stripped the host and returned
        // "boracay.jpg", deleting it would have been a no-op at best.
        $this->assertNull(
            (new Destination(['image_url' => 'https://images.example.com/boracay.jpg']))->imagePath(),
            'an external URL was resolved to a path on our own disk'
        );

        // Exactly one file exists: the one just uploaded. Nothing was deleted.
        $this->assertCount(1, Storage::disk('public')->files('destination-images'));
    }

    #[Test]
    public function remove_image_clears_the_photo(): void
    {
        Storage::fake('public');

        $destination = $this->destination();

        $this->actingAs($this->admin())->put(
            route('admin.destinations.update', $destination),
            $this->payload($destination, ['image' => $this->fakeImage()])
        );

        $path = $destination->refresh()->imagePath();

        $this->actingAs($this->admin())->put(
            route('admin.destinations.update', $destination),
            $this->payload($destination, ['remove_image' => '1'])
        );

        $this->assertNull($destination->refresh()->image_url);
        Storage::disk('public')->assertMissing($path);
    }

    #[Test]
    public function an_upload_is_rejected_when_it_is_not_an_image(): void
    {
        Storage::fake('public');

        $destination = $this->destination();

        $this->actingAs($this->admin())->put(
            route('admin.destinations.update', $destination),
            $this->payload($destination, [
                'image' => UploadedFile::fake()->create('payload.php', 4, 'application/x-php'),
            ])
        )->assertSessionHasErrors('image');

        $this->assertNull($destination->refresh()->image_url);
    }

    #[Test]
    public function a_non_admin_cannot_upload(): void
    {
        Storage::fake('public');

        $destination = $this->destination();

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, ['image' => $this->fakeImage()])
            )
            ->assertForbidden();

        $this->assertNull($destination->refresh()->image_url);
    }
}
