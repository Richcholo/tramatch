<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\User;
use App\Services\ItineraryCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE TRIP IN A CALENDAR, AND A LINK TO THE DRIVE.
 *
 * A `.ics` is a promise about somebody's day, and a directions link is a
 * claim about a drive. Neither is decorative, so:
 *
 * - **A STOP WITH NO TIME IS NOT INVENTED.** All-day, transparent and
 *   tentative — it occupies the day without claiming the traveller is there
 *   all day, and without putting 08:00 into a calendar that never had one.
 * - **TIMES ARE UTC, NOT FLOATING.** A floating local time is read in the
 *   VIEWER's timezone, which quietly moves a Baguio morning for anyone
 *   planning from outside the Philippines. 08:00 in Manila is 00:00Z, and
 *   that conversion is pinned here rather than trusted.
 * - **AN UNDATED TRIP IS NOT A CALENDAR FILE.** No days to put anything on,
 *   and a file of undated events would be filed arbitrarily by every client.
 * - **THE FILE SAYS WHAT IT IS** — a plan, not a booking (ADR 0003: this app
 *   sells neither bookings nor availability).
 * - **CRLF, FOLDING AND ESCAPING ARE NOT OPTIONAL.** RFC 5545 wants all
 *   three, a stop called "Le Chef, Baguio" is a broken file without the
 *   comma escape, and folding counts OCTETS so a name like "Pinto" is not
 *   split inside its own encoding.
 * - **A DIRECTION IS NOT A MEASUREMENT.** The link hands the traveller to a
 *   map app's live estimate; it must never read like the measured OSRM leg
 *   printed beside it, and a stop without both coordinates gets no link at
 *   all.
 */
class ItineraryCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $traveller;

    private Itinerary $trip;

    private ItineraryDay $day;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traveller = User::factory()->create();

        $this->trip = Itinerary::create([
            'user_id' => $this->traveller->id,
            'title' => 'Benguet weekend',
            'area' => 'Benguet',
            'budget_level' => 'economy',
            'trip_duration_days' => 1,
            'start_date' => '2026-11-03',
        ]);

        $this->day = ItineraryDay::create([
            'itinerary_id' => $this->trip->id,
            'day_number' => 1,
            'date' => '2026-11-03',
        ]);
    }

    // ------------------------------------------------------------ the file itself

    public function test_the_file_is_a_calendar_and_says_it_is_a_plan_not_a_booking(): void
    {
        $this->timedStop('Burnham Park', '08:00', '10:00');

        $ics = $this->calendar();

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('VERSION:2.0', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
        // The comma is escaped, which is the point: an unescaped one ends the
        // property. Assert the ESCAPED form, or this test would pass on a
        // file the spec says is malformed.
        $this->assertStringContainsString('A plan\\, not a booking', $ics);
    }

    public function test_every_stop_becomes_one_event(): void
    {
        $this->timedStop('Burnham Park', '08:00', '10:00');
        $this->timedStop('Wright Park', '11:00', '12:30');

        $ics = $this->calendar();

        $this->assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('SUMMARY:Burnham Park', $ics);
        $this->assertStringContainsString('SUMMARY:Wright Park', $ics);
    }

    /**
     * 08:00 in Manila is 00:00Z. A floating local time would be read in the
     * VIEWER's timezone instead, which quietly moves a Baguio morning for
     * anyone planning the trip from abroad.
     */
    public function test_times_are_written_in_utc_not_as_floating_local_time(): void
    {
        $this->timedStop('Burnham Park', '08:00', '10:00');

        $ics = $this->calendar();

        $this->assertStringContainsString('DTSTART:20261103T000000Z', $ics);
        $this->assertStringContainsString('DTEND:20261103T020000Z', $ics);
        $this->assertStringNotContainsString('DTSTART:20261103T080000', $ics);
    }

    // ------------------------------------------------------------ the honest states

    /**
     * A STOP WITH NO TIME GETS NO TIME. All-day, transparent and tentative,
     * rather than an invented 08:00 sitting in somebody's calendar.
     */
    public function test_a_stop_without_a_time_is_all_day_tentative_and_invents_nothing(): void
    {
        $this->untimedStop('The Mansion');

        $ics = $this->calendar();

        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261103', $ics);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20261104', $ics);
        $this->assertStringContainsString('TRANSP:TRANSPARENT', $ics);
        $this->assertStringContainsString('STATUS:TENTATIVE', $ics);
        $this->assertStringContainsString('No time is set for this stop', $ics);

        // No DTSTART:...T...Z anywhere: not one invented instant.
        $this->assertStringNotContainsString('DTSTART:2026', $ics);
    }

    public function test_the_file_counts_its_own_untimed_stops(): void
    {
        $this->timedStop('Burnham Park', '08:00', '10:00');
        $this->untimedStop('The Mansion');

        $this->assertStringContainsString(
            '1 stop(s) have no time set and appear as all-day entries',
            $this->calendar()
        );
    }

    public function test_an_undated_trip_is_not_a_calendar_file(): void
    {
        $this->day->update(['date' => null]);

        $this->timedStop('Somewhere', '09:00', '10:00');

        $this->assertNull(app(ItineraryCalendar::class)->build($this->trip->fresh(['days'])));
    }

    public function test_an_undated_trip_explains_itself_and_does_not_download_anything(): void
    {
        // Both halves: the trip carries no start date AND no day carries a
        // date. The writer refuses on the DAYS, because a day is the thing it
        // has to put an event on.
        $this->trip->update(['start_date' => null]);
        $this->day->update(['date' => null]);

        $this->actingAs($this->traveller)
            ->get(route('itineraries.calendar', $this->trip))
            ->assertRedirect(route('itineraries.show', $this->trip))
            ->assertSessionHas('status');
    }

    public function test_the_calendar_link_is_not_offered_for_an_undated_trip(): void
    {
        $this->trip->update(['start_date' => null]);

        $html = (string) $this->actingAs($this->traveller)
            ->get(route('itineraries.show', $this->trip))
            ->assertOk()
            ->getContent();

        // A button whose only possible outcome is a refusal is worse than no
        // button.
        $this->assertStringNotContainsString('itineraries/' . $this->trip->id . '/calendar', $html);
    }

    public function test_somebody_elses_trip_has_no_calendar(): void
    {
        $this->timedStop('Burnham Park', '08:00', '10:00');

        $this->actingAs(User::factory()->create())
            ->get(route('itineraries.calendar', $this->trip))
            ->assertForbidden();
    }

    // --------------------------------------------------- the download contract

    public function test_the_download_is_a_file_named_for_the_trip(): void
    {
        $this->timedStop('Burnham Park', '08:00', '10:00');

        $response = $this->actingAs($this->traveller)
            ->get(route('itineraries.calendar', $this->trip));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');

        $this->assertStringContainsString(
            'attachment; filename="benguet-weekend-' . $this->trip->id . '.ics"',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    // ------------------------------------------------- what every writer gets wrong

    /**
     * RFC 5545 §3.1: CRLF line endings, and lines folded at 75 OCTETS with a
     * continuation space. Counting CHARACTERS instead would split "Pinto"
     * inside its own encoding and corrupt the file for every reader.
     */
    public function test_lines_end_in_crlf_and_are_folded_at_seventy_five_octets(): void
    {
        $this->timedStop(str_repeat('Pinto ', 30), '08:00', '09:00');

        $ics = $this->calendar();

        $this->assertStringNotContainsString("\n\n", $ics);

        $continuations = 0;

        foreach (explode("\r\n", $ics) as $index => $line) {
            if ($index > 0) {
                // A folded continuation is a leading space, and the reader
                // strips it back off.
                $isContinuation = str_starts_with($line, ' ');

                $this->assertLessThanOrEqual(
                    $isContinuation ? 75 : 75,
                    strlen($isContinuation ? substr($line, 1) : $line) + ($isContinuation ? 1 : 0),
                    'A folded line is 74 octets plus the leading space.'
                );

                $continuations += $isContinuation ? 1 : 0;
            }
        }

        $this->assertGreaterThan(0, $continuations, 'A long SUMMARY must be folded.');
    }

    /**
     * TEXT ESCAPING, OR THE FILE IS BROKEN. An unescaped comma ends the
     * property: "Le Chef, Baguio" becomes a SUMMARY of "Le Chef" followed by
     * a property called "Baguio".
     */
    public function test_a_stop_name_with_a_comma_is_escaped(): void
    {
        $this->timedStop('Le Chef, Baguio', '12:00', '13:00');

        $ics = $this->calendar();

        $this->assertStringContainsString('SUMMARY:Le Chef\\, Baguio', $ics);
        $this->assertStringNotContainsString('SUMMARY:Le Chef, Baguio', $ics);
    }

    public function test_the_event_id_is_stable_so_a_second_download_updates_rather_than_duplicates(): void
    {
        $this->timedStop('Burnham Park', '08:00', '10:00');

        $first = $this->calendar();
        $second = $this->calendar();

        preg_match('/^UID:(.+)$/m', $first, $matches);

        $this->assertNotEmpty($matches, 'Every event needs a UID.');
        $this->assertStringContainsString('UID:' . $matches[1], $second);
        $this->assertStringContainsString($this->trip->id === 1 ? 'itinerary-' : 'itinerary-', $matches[1]);
    }

    // ------------------------------------------------------------ the directions link

    public function test_a_stop_with_a_pin_offers_directions_from_the_previous_stop(): void
    {
        $first = $this->pinnedStop('Burnham Park', 16.4095, 120.5960);
        $second = $this->pinnedStop('Wright Park', 16.3950, 120.5610);

        $url = $second->fresh()->directionsUrl($first->fresh());

        $this->assertStringContainsString('origin=16.409500%2C120.596000', $url);
        $this->assertStringContainsString('destination=16.395000%2C120.561000', $url);
        $this->assertStringContainsString('travelmode=driving', $url);
    }

    public function test_the_first_stop_of_a_day_asks_where_you_actually_are(): void
    {
        $first = $this->pinnedStop('Burnham Park', 16.4095, 120.5960);

        $url = $first->fresh()->directionsUrl(null);

        // Only the traveller knows where they are starting from.
        $this->assertStringNotContainsString('origin=', $url);
        $this->assertStringContainsString('destination=16.409500%2C120.596000', $url);
    }

    public function test_a_stop_without_a_pin_has_no_directions_link(): void
    {
        $this->timedStop('The hotel you already booked', '16:00', '16:00');

        $item = $this->day->items()->firstOrFail();

        $this->assertNull($item->directionsUrl());
    }

    public function test_the_link_appears_on_the_page_and_says_what_it_opens(): void
    {
        $this->pinnedStop('Burnham Park', 16.4095, 120.5960);

        $html = (string) $this->actingAs($this->traveller)
            ->get(route('itineraries.show', $this->trip))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Directions', $html);
        $this->assertStringContainsString('google.com/maps/dir', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    // ---------------------------------------------------------------- helpers

    private function calendar(): string
    {
        return (string) app(ItineraryCalendar::class)->build($this->trip->fresh(['days']));
    }

    private function timedStop(string $name, string $start, string $end): ItineraryItem
    {
        return ItineraryItem::create([
            'itinerary_day_id' => $this->day->id,
            'item_type' => 'custom',
            'sort_order' => ItineraryItem::count() + 1,
            'custom_name' => $name,
            'custom_address' => 'Session Road, Baguio',
            'start_time' => $start,
            'end_time' => $end,
            'estimated_cost' => 100,
        ]);
    }

    private function untimedStop(string $name): ItineraryItem
    {
        return ItineraryItem::create([
            'itinerary_day_id' => $this->day->id,
            'item_type' => 'custom',
            'sort_order' => ItineraryItem::count() + 1,
            'custom_name' => $name,
            'estimated_cost' => 0,
        ]);
    }

    private function pinnedStop(string $name, float $lat, float $lng): ItineraryItem
    {
        $destination = Destination::create([
            'name' => $name,
            'slug' => str($name)->slug(),
            'description' => 'A place.',
            'province' => 'Benguet',
            'municipality' => 'Baguio',
            'latitude' => $lat,
            'longitude' => $lng,
            'budget_level' => 'economy',
            'estimated_cost' => 100,
            'recommended_minutes' => 90,
            'is_featured' => true,
            'is_active' => true,
        ]);

        return ItineraryItem::create([
            'itinerary_day_id' => $this->day->id,
            'destination_id' => $destination->id,
            'sort_order' => ItineraryItem::count() + 1,
            'start_time' => '09:00',
            'end_time' => '10:30',
            'estimated_cost' => 100,
        ]);
    }
}
