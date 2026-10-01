<?php

namespace Tests\Feature;

use App\Models\Destination;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationPageTest extends TestCase
{
    use RefreshDatabase;

    private function destination(array $attributes = []): Destination
    {
        return Destination::create(array_merge([
            'name' => 'Fort Santiago',
            'slug' => 'fort-santiago',
            'description' => 'A fort in Intramuros.',
            'province' => 'Manila',
            'latitude' => 14.6,
            'longitude' => 120.9,
            'budget_level' => 'economy',
            'entrance_fee' => 200,
            'estimated_cost' => 625,
            'recommended_minutes' => 90,
        ], $attributes));
    }

    public function test_it_shows_opening_hours_and_the_updated_date(): void
    {
        $destination = $this->destination([
            'opening_time' => '09:00',
            'closing_time' => '18:00',
            'operating_status' => 'open',
            'last_verified_at' => now(),
        ]);

        $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertSee('Opening hours')
            ->assertSee('09:00–18:00')
            ->assertSee('Last checked '.now()->format('j M Y'))
            ->assertSee('Philippine time (UTC+8)');
    }

    public function test_it_reports_open_and_closed_states(): void
    {
        Carbon::setTestNow('2026-09-27 10:00:00');

        $open = $this->destination([
            'opening_time' => '09:00',
            'closing_time' => '18:00',
        ]);

        $this->get(route('destinations.show', $open))
            ->assertOk()
            ->assertSee('Open now');

        Carbon::setTestNow('2026-09-27 20:00:00');

        $this->get(route('destinations.show', $open))
            ->assertOk()
            ->assertSee('Closed now');

        Carbon::setTestNow();
    }

    public function test_it_says_hours_are_unknown_when_absent(): void
    {
        $destination = $this->destination();

        $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertSee('Hours not listed')
            ->assertSee('Open/closed unknown');
    }

    public function test_it_lists_a_non_open_operating_status(): void
    {
        $destination = $this->destination([
            'operating_status' => 'temporarily_closed',
        ]);

        $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertSee('temporarily closed');
    }

    public function test_it_normalises_mysql_time_columns(): void
    {
        Carbon::setTestNow('2026-09-27 10:00:00');

        $destination = $this->destination([
            'opening_time' => '09:00:00',
            'closing_time' => '18:00:00',
            'last_verified_at' => now(),
        ]);

        $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertSee('09:00–18:00')
            ->assertSee('Open now')
            ->assertDontSee('09:00:00');

        Carbon::setTestNow();
    }

    public function test_it_renders_for_a_guest(): void
    {
        $destination = $this->destination([
            'opening_time' => '09:00',
            'closing_time' => '18:00',
        ]);

        $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertSee('Opening hours')
            ->assertDontSee('Admin');
    }

    public function test_the_destination_list_shows_hours(): void
    {
        $this->destination([
            'opening_time' => '06:00',
            'closing_time' => '18:00',
        ]);

        $this->get(route('destinations.index'))
            ->assertOk()
            ->assertSee('06:00–18:00');
    }

    public function test_it_shows_the_hours_source_below_the_hours(): void
    {
        $destination = $this->destination([
            'opening_time' => '09:00',
            'closing_time' => '18:00',
            'hours_source_url' => 'https://bencabmuseum.org/location-info/',
            'hours_source_label' => 'BenCab Museum official site',
            'last_verified_at' => now(),
        ]);

        $html = $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertSee('Hours from')
            ->assertSee('BenCab Museum official site')
            ->getContent();

        $this->assertStringContainsString(
            'href="https://bencabmuseum.org/location-info/"',
            (string) $html
        );
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', (string) $html);
    }

    public function test_it_shows_no_source_line_when_there_is_no_source(): void
    {
        $destination = $this->destination([
            'opening_time' => '09:00',
            'closing_time' => '18:00',
        ]);

        $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertDontSee('Hours from');
    }

    public function test_it_explains_a_registration_window_instead_of_just_the_times(): void
    {
        Carbon::setTestNow('2026-09-27 10:00:00');

        $destination = $this->destination([
            'opening_time' => '05:00',
            'closing_time' => '07:00',
            'hours_kind' => Destination::HOURS_REGISTRATION,
        ]);

        $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertSee('05:00–07:00')
            ->assertSee('This is a registration window, not opening hours');

        Carbon::setTestNow();
    }

    public function test_it_marks_an_ungated_site_as_open_24_hours(): void
    {
        $destination = $this->destination([
            'opening_time' => '00:00',
            'closing_time' => '23:59',
            'hours_kind' => Destination::HOURS_ALWAYS_OPEN,
        ]);

        $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertSee('Open 24 hours')
            ->assertSee('no gate');
    }

    public function test_it_warns_when_access_depends_on_a_hazard_alert_level(): void
    {
        $destination = $this->destination([
            'opening_time' => '00:00',
            'closing_time' => '23:59',
            'hours_kind' => Destination::HOURS_ALERT_DEPENDENT,
        ]);

        $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertSee('hazard alert level');
    }

    public function test_it_renders_a_weekly_hours_table(): void
    {
        $destination = $this->destination([
            'daily_hours' => [
                'monday' => ['open' => '08:00', 'close' => '22:00'],
                'saturday' => ['open' => '06:00', 'close' => '22:00'],
            ],
        ]);

        $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertSee('Mon 08:00–22:00 · Sat 06:00–22:00')
            ->assertSee('Monday')
            ->assertSee('Saturday')
            ->assertSee('06:00–22:00')
            ->assertSee('Closed');
    }

    public function test_it_summarises_a_full_week_split_when_all_days_are_set(): void
    {
        $destination = $this->destination([
            'daily_hours' => [
                'monday' => ['open' => '08:00', 'close' => '22:00'],
                'tuesday' => ['open' => '08:00', 'close' => '22:00'],
                'wednesday' => ['open' => '08:00', 'close' => '22:00'],
                'thursday' => ['open' => '08:00', 'close' => '22:00'],
                'friday' => ['open' => '08:00', 'close' => '22:00'],
                'saturday' => ['open' => '06:00', 'close' => '22:00'],
                'sunday' => ['open' => '06:00', 'close' => '22:00'],
            ],
        ]);

        $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertSee('Mon–Fri 08:00–22:00 · Sat–Sun 06:00–22:00');
    }

    public function test_a_per_day_closed_row_is_not_shown_as_closed_now(): void
    {
        Carbon::setTestNow('2026-09-29 10:00:00');

        $destination = $this->destination([
            'daily_hours' => [
                'tuesday' => ['open' => '09:00', 'close' => '18:00'],
            ],
        ]);

        $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertSee('Open now');

        Carbon::setTestNow('2026-09-28 10:00:00');

        $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertSee('Closed today (Monday)');

        Carbon::setTestNow();
    }

    public function test_the_destination_list_renders_for_a_guest(): void
    {
        $this->destination();

        $this->get(route('destinations.index'))->assertOk();
    }

    public function test_it_hides_archived_destinations(): void
    {
        $destination = $this->destination(['is_active' => false]);

        $this->get(route('destinations.show', $destination))->assertNotFound();
    }
}
