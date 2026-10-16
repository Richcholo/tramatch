<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\Tag;
use App\Models\TravelProfile;
use App\Models\User;
use App\Models\Concerns\HasOpeningHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The scheduler reads the opening hours the catalogue already cites.
 *
 * The project curates, cites and date-stamps hours for every row, and until this
 * existed nothing but the destination page read them. So a generated plan would
 * put an 11:00 visit to a place whose own page says 05:00–07:00 — and that
 * window is a *registration cut-off*, not an opening time. The itinerary was
 * contradicting, in its own times, the facts the app is proudest of.
 *
 * The two tests at the bottom exist because of a real failure during this work,
 * and both would have been invisible without them: three generation tests broke
 * because `isClosedOn()` reports a row with BLANK hours as closed every day.
 */
class ItineraryHoursTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

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

        $tag = Tag::create(['name' => 'Nature', 'slug' => 'nature']);
        $profile->tags()->attach($tag, ['weight' => 3]);
    }

    // ------------------------------------------------ the trap, first

    public function test_a_destination_with_no_published_hours_is_not_treated_as_closed(): void
    {
        $destination = $this->destination('No Hours At All');

        $this->assertFalse($destination->hasAnyHours());

        // The API's own behaviour, pinned because the generator depends on it:
        // null from hoursForDay() means "closed" OR "unknown", and callers that
        // skip the hasAnyHours() check exclude every blank-hours row.
        $this->assertTrue($destination->isClosedOn(now()));

        $this->generate([$destination->id]);

        $this->assertDatabaseHas('itinerary_items', [
            'destination_id' => $destination->id,
        ]);
    }

    // ------------------------------------------------------ closed days

    public function test_a_destination_closed_that_weekday_is_skipped_with_a_reason(): void
    {
        $monday = now()->next('Monday');

        $closed = $this->destination('Closed Mondays', [
            'opening_time' => '09:00',
            'closing_time' => '17:00',
            'closed_days' => 'monday',
        ]);

        $response = $this->generate([$closed->id], $monday->toDateString());

        $this->assertDatabaseMissing('itinerary_items', [
            'destination_id' => $closed->id,
        ]);

        // Over HTTP the assertion is that the traveller is told SOMETHING.
        $response->assertSessionHasErrors('itinerary');

        // The wording is asserted against the generator directly, because that
        // string is the only channel the reason has: the controller catches the
        // throw and flashes it, and `back()` in a test has no referer to return
        // to, so following the redirect renders the homepage and proves nothing.
        $this->assertStringContainsString(
            'Closed on Monday.',
            $this->generatorFailureMessage([$closed->id], $monday->toDateString())
        );
    }

    /**
     * The message the generator throws when it planned nothing.
     *
     * Called on the service rather than over HTTP, so the exception is not
     * swallowed by the controller's catch — which is the point, because
     * swallowing it is exactly how the reason used to disappear.
     */
    private function generatorFailureMessage(array $destinationIds, ?string $startDate = null): string
    {
        try {
            app(\App\Services\ItineraryGenerator::class)->create($this->user, [
                'title' => 'An honest week',
                'area' => 'Benguet',
                'days' => 1,
                'destination_ids' => $destinationIds,
                'start_date' => $startDate,
            ]);
        } catch (\RuntimeException $exception) {
            return $exception->getMessage();
        }

        $this->fail('generation was expected to refuse, and it did not');
    }

    public function test_the_same_destination_is_planned_on_a_day_it_is_open(): void
    {
        $tuesday = now()->next('Tuesday');

        $open = $this->destination('Closed Mondays Only', [
            'opening_time' => '09:00',
            'closing_time' => '17:00',
            'closed_days' => 'monday',
        ]);

        $this->generate([$open->id], $tuesday->toDateString());

        $this->assertDatabaseHas('itinerary_items', [
            'destination_id' => $open->id,
        ]);
    }

    // ------------------------------------------------------- the window

    public function test_a_visit_never_starts_before_the_gate_opens(): void
    {
        $late = $this->destination('Opens At Two', [
            'opening_time' => '14:00',
            'closing_time' => '17:00',
        ]);

        $this->generate([$late->id]);

        $item = \App\Models\ItineraryItem::where('destination_id', $late->id)->firstOrFail();

        // The day starts at 08:00; this place does not open until 14:00.
        $this->assertSame('14:00', substr((string) $item->start_time, 0, 5));
    }

    public function test_a_window_too_small_for_the_visit_skips_it_with_the_hours_in_the_reason(): void
    {
        $brief = $this->destination('Ninety Minute Window', [
            'opening_time' => '09:00',
            'closing_time' => '10:30',
        ]);

        $brief->update(['recommended_minutes' => 180]);

        $message = $this->generatorFailureMessage([$brief->id]);

        $this->assertDatabaseMissing('itinerary_items', [
            'destination_id' => $brief->id,
        ]);

        // The window itself, so the traveller can see why and judge.
        $this->assertStringContainsString('09:00', $message);
        $this->assertStringContainsString('10:30', $message);
        $this->assertStringContainsString('180-minute', $message);
    }

    /**
     * The advisory trap, and the most important test in this file.
     *
     * Five destinations in the catalogue store 05:00–07:00 as a REGISTRATION
     * cut-off — Mt Pinatubo, Mt Daraitan, Mt Ulap and two others. Enforcing that
     * as an opening window would produce "closed all day" or "open 05:00", and
     * either is a factual error about a place the traveller can visit from
     * breakfast onwards. It is exactly the mistake AGENTS.md warns about when it
     * says a registration window must not read as "you can walk in at 5am".
     */
    public function test_a_registration_cut_off_is_not_enforced_as_opening_hours(): void
    {
        $registration = $this->destination('Registration Window', [
            'opening_time' => '05:00',
            'closing_time' => '07:00',
            'hours_kind' => Destination::HOURS_REGISTRATION,
        ]);

        // A 120-minute visit at 11:00 is impossible under a real 05:00-07:00
        // gate, and perfectly fine under a registration cut-off.
        $registration->update(['recommended_minutes' => 120]);

        $this->generate([$registration->id]);

        $this->assertDatabaseHas('itinerary_items', [
            'destination_id' => $registration->id,
        ]);

        $item = \App\Models\ItineraryItem::where('destination_id', $registration->id)->firstOrFail();

        $this->assertSame('08:00', substr((string) $item->start_time, 0, 5));
        $this->assertTrue($registration->hoursKindIsAdvisory());
    }

    public function test_an_ungated_overnight_row_is_never_constrained(): void
    {
        // Benguet's Wright Park and a dozen others are stored 00:00-23:59, which
        // is "no gate", not "open for 24 hours".
        $ungated = $this->destination('No Gate', [
            'opening_time' => '00:00',
            'closing_time' => '23:59',
            'hours_kind' => Destination::HOURS_ALWAYS_OPEN,
        ]);

        $this->generate([$ungated->id]);

        $item = \App\Models\ItineraryItem::where('destination_id', $ungated->id)->firstOrFail();

        $this->assertSame('08:00', substr((string) $item->start_time, 0, 5));
    }

    // ------------------------------------------------------- the arithmetic

    public function test_a_gate_opening_after_lunch_is_not_pushed_backwards_by_it(): void
    {
        // The ordering trap in nextStart(): 14:00 is after the lunch block, and
        // a gate that opens at 14:00 must not be nudged back to 13:00 by a
        // lunch rule that only exists to move visits EARLIER out of the way.
        $this->assertSame(
            14 * 60,
            \App\Services\ItinerarySchedule::nextStart(8 * 60, 60, 14 * 60)
        );

        // A visit starting at 12:30 that runs into lunch still moves to 13:00.
        $this->assertSame(
            13 * 60,
            \App\Services\ItinerarySchedule::nextStart(12 * 60 + 30, 60)
        );
    }

    // ---------------------------------------------------------- helpers

    private function destination(string $name, array $attributes = []): Destination
    {
        $destination = Destination::create(array_merge([
            'name' => $name,
            'slug' => str($name)->slug(),
            'description' => 'A place.',
            'province' => 'Benguet',
            'latitude' => 16.4,
            'longitude' => 120.6,
            'budget_level' => 'economy',
            'estimated_cost' => 100,
            'recommended_minutes' => 90,
        ], $attributes));

        $destination->tags()->attach(Tag::where('slug', 'nature')->first());

        DestinationSwipe::create([
            'user_id' => $this->user->id,
            'destination_id' => $destination->id,
            'action' => 'liked',
        ]);

        return $destination;
    }

    private function generate(array $destinationIds, ?string $startDate = null)
    {
        return $this->actingAs($this->user)
            ->post(route('itineraries.store'), array_filter([
                'title' => 'An honest week',
                'area' => 'Benguet',
                'days' => 1,
                'destination_ids' => $destinationIds,
                'start_date' => $startDate,
            ]))
            ->assertRedirect();
    }
}