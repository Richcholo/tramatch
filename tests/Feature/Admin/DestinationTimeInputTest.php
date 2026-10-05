<?php

namespace Tests\Feature\Admin;

use App\Models\Destination;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin destination form must not reject a field nobody touched.
 *
 * `opening_time` and `closing_time` are MySQL TIME columns, so PHP returns
 * "09:00:00". The form pre-filled that raw value into `<input type="time">`, the
 * browser posted it back untouched alongside every other field, and
 * `date_format:H:i` rejected the seconds. Editing *any* field on a destination
 * that had opening hours therefore failed with "the opening time field must match
 * the format H:i" -- for a field the admin never went near.
 *
 * The important half is what must still fail: a time that is genuinely
 * malformed. Silently reducing an unparseable value to null would make the
 * `nullable` rule skip the field, so a tampered-with time would be stored as
 * "no hours" and the wrong opening window would ship.
 */
class DestinationTimeInputTest extends TestCase
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
     * Every field the admin form posts, so a test can change one thing.
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
            // required|array|min:1 -- an empty array is a validation failure.
            'tags' => [Tag::firstOrCreate(['slug' => 'nature'], ['name' => 'Nature'])->id],
        ], $overrides);
    }

    /**
     * The bug, restated as a test: the form round-trips its own stored value.
     *
     * The value submitted here is what the browser would send after rendering
     * the form and not touching the hours -- "09:00:00", straight from the TIME
     * column.
     */
    #[Test]
    public function editing_another_field_survives_a_stored_time_with_seconds(): void
    {
        $destination = $this->destination([
            'opening_time' => '09:00:00',
            'closing_time' => '18:00:00',
        ]);

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, ['description' => 'A better description.'])
            )
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.destinations.index'));

        $this->assertSame(
            'A better description.',
            $destination->refresh()->description,
            'the update was rejected by the hours validation'
        );
    }

    /**
     * And the value that is actually stored is the canonical one, not the
     * three-column form of it.
     */
    #[Test]
    public function a_time_posted_with_seconds_is_stored_without_them(): void
    {
        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, [
                    'opening_time' => '09:00:00',
                    'closing_time' => '18:30:00',
                ])
            )
            ->assertSessionHasNoErrors();

        $this->assertSame('09:00', $destination->refresh()->opening_time);
        $this->assertSame('18:30', $destination->refresh()->closing_time);
    }

    /**
     * The other half of the contract: tampering is still refused.
     *
     * This is the assertion that stops the fix being done by nulling anything
     * unparseable, which would make `nullable` skip the field and silently store
     * "no hours" for a tampered value.
     */
    #[Test]
    public function a_genuinely_malformed_time_is_still_refused(): void
    {
        $destination = $this->destination(['opening_time' => '09:00:00']);

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, ['opening_time' => 'half past nine'])
            )
            ->assertSessionHasErrors('opening_time');

        $this->assertSame(
            '09:00:00',
            $destination->refresh()->opening_time,
            'a rejected time was still written to the row'
        );
    }

    /**
     * An out-of-range clock time is tampering too. `date_format:H:i` is lenient
     * about the hour in some PHP builds, so this pins the check rather than
     * trusting it.
     */
    #[Test]
    public function an_out_of_range_time_is_refused(): void
    {
        $destination = $this->destination(['opening_time' => '09:00:00']);

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, ['closing_time' => '99:99'])
            )
            ->assertSessionHasErrors('closing_time');
    }

    /**
     * The per-day table had the same defect, from a different source: it reads
     * `old('daily_hours')` or the normalised array, and either can hold a
     * three-column time.
     */
    #[Test]
    public function per_day_times_survive_seconds_too(): void
    {
        $destination = $this->destination(['opening_time' => '09:00:00']);

        $this->actingAs($this->admin())
            ->put(
                route('admin.destinations.update', $destination),
                $this->payload($destination, [
                    'daily_hours' => [
                        'monday' => ['open' => '08:00:00', 'close' => '17:00:00'],
                    ],
                ])
            )
            ->assertSessionHasNoErrors();

        $stored = $destination->refresh()->normalisedDailyHours();

        $this->assertSame(['open' => '08:00', 'close' => '17:00'], $stored['monday'] ?? null);
    }

    /**
     * The rendered form must hand the browser `HH:MM`.
     *
     * Server-side normalisation alone would leave the input carrying a value the
     * browser may not even accept, so this pins the markup half too.
     */
    #[Test]
    public function the_form_prefills_times_the_browser_can_submit(): void
    {
        $destination = $this->destination([
            'opening_time' => '09:00:00',
            'closing_time' => '18:00:00',
        ]);

        $html = $this->actingAs($this->admin())
            ->get(route('admin.destinations.edit', $destination))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="09:00"', $html, 'the form prefilled a raw TIME value');
        $this->assertStringContainsString('value="18:00"', $html);
        $this->assertStringNotContainsString('value="09:00:00"', $html);
        $this->assertStringNotContainsString('value="18:00:00"', $html);
    }
}
