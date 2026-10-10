<?php

namespace Tests\Feature\Admin;

use App\Models\Destination;
use App\Models\Tag;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\LuzonLocationsCsvSeeder;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationHoursTest extends TestCase
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
            'tags' => [Tag::firstOrCreate(['slug' => 'nature'], ['name' => 'Nature'])->id],
        ], $overrides);
    }

    public function test_it_stores_different_hours_per_day(): void
    {
        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->put(route('admin.destinations.update', $destination), $this->payload($destination, [
                'daily_hours' => [
                    'monday' => ['open' => '08:00', 'close' => '22:00'],
                    'saturday' => ['open' => '06:00', 'close' => '22:00'],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $hours = $destination->fresh()->normalisedDailyHours();

        $this->assertSame(['open' => '08:00', 'close' => '22:00'], $hours['monday']);
        $this->assertSame(['open' => '06:00', 'close' => '22:00'], $hours['saturday']);
        $this->assertArrayNotHasKey('tuesday', $hours);
    }

    public function test_a_day_can_be_marked_closed_in_daily_hours(): void
    {
        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->put(route('admin.destinations.update', $destination), $this->payload($destination, [
                'daily_hours' => [
                    'monday' => ['closed' => '1'],
                    'tuesday' => ['open' => '09:00', 'close' => '17:00'],
                ],
            ]));

        $destination->refresh();

        $this->assertNull($destination->hoursForDay(Carbon::parse('2026-09-28')));
        $this->assertSame(
            ['open' => '09:00', 'close' => '17:00'],
            $destination->hoursForDay(Carbon::parse('2026-09-29'))
        );
    }

    public function test_daily_hours_fall_back_to_the_general_pair(): void
    {
        $destination = $this->destination([
            'opening_time' => '09:00',
            'closing_time' => '18:00',
        ]);

        $destination->daily_hours = [
            'saturday' => ['open' => '06:00', 'close' => '22:00'],
        ];

        $this->assertSame(
            ['open' => '06:00', 'close' => '22:00'],
            $destination->hoursForDay(Carbon::parse('2026-10-03'))
        );

        $this->assertSame(
            ['open' => '09:00', 'close' => '18:00'],
            $destination->hoursForDay(Carbon::parse('2026-09-29')),
            'an unlisted day must fall back to the general hours'
        );
    }

    public function test_it_summarises_a_weekday_and_weekend_split(): void
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

        $this->assertSame(
            'Mon–Fri 08:00–22:00 · Sat–Sun 06:00–22:00',
            $destination->perDayHoursLabel()
        );
    }

    public function test_it_computes_overnight_hours_as_open_after_midnight(): void
    {
        Carbon::setTestNow('2026-09-27 23:30:00');

        $destination = $this->destination([
            'daily_hours' => [
                'sunday' => ['open' => '18:00', 'close' => '02:00'],
            ],
        ]);

        $this->assertTrue($destination->isClosedOn(Carbon::parse('2026-09-27 23:30')) === false);

        Carbon::setTestNow();
    }

    public function test_it_stores_the_hours_source_and_note(): void
    {
        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->put(route('admin.destinations.update', $destination), $this->payload($destination, [
                'opening_time' => '08:00',
                'closing_time' => '22:00',
                'hours_source_url' => 'https://intramuros.gov.ph/hours/',
                'hours_source_label' => 'Intramuros Administration',
                'hours_note' => 'Last entry is two hours before closing.',
            ]))
            ->assertSessionHasNoErrors();

        $destination->refresh();

        $this->assertSame('https://intramuros.gov.ph/hours/', $destination->hours_source_url);
        $this->assertSame('Intramuros Administration', $destination->hours_source_label);
        $this->assertSame('Last entry is two hours before closing.', $destination->hours_note);
    }

    public function test_it_rejects_a_malformed_hours_source_url(): void
    {
        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->from(route('admin.destinations.edit', $destination))
            ->put(route('admin.destinations.update', $destination), $this->payload($destination, [
                'hours_source_url' => 'not-a-url',
            ]))
            ->assertSessionHasErrors('hours_source_url');
    }

    public function test_it_rejects_a_malformed_daily_window(): void
    {
        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->from(route('admin.destinations.edit', $destination))
            ->put(route('admin.destinations.update', $destination), $this->payload($destination, [
                'daily_hours' => ['monday' => ['open' => '25:99', 'close' => '10:00']],
            ]))
            ->assertSessionHasErrors('daily_hours.monday.open');
    }

    public function test_changing_only_the_hours_source_restamps_verification(): void
    {
        $destination = $this->destination([
            'opening_time' => '09:00',
            'closing_time' => '18:00',
            'last_verified_at' => '2026-08-01 10:00:00',
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.destinations.update', $destination), $this->payload($destination, [
                'opening_time' => '09:00',
                'closing_time' => '18:00',
                'hours_source_url' => 'https://example.gov.ph/hours',
            ]));

        $this->assertNotSame(
            '2026-08-01 10:00:00',
            $destination->fresh()->last_verified_at->format('Y-m-d H:i:s')
        );
    }

    public function test_the_csv_seeder_restores_per_day_hours_and_the_source(): void
    {
        $this->seed(TagSeeder::class);
        $this->seed(LuzonLocationsCsvSeeder::class);

        $fort = Destination::where('slug', 'fort-santiago')->firstOrFail();

        $this->assertTrue($fort->hasPerDayHours());
        $this->assertSame(
            ['open' => '06:00', 'close' => '22:00'],
            $fort->hoursForDay(Carbon::parse('2026-10-03')),
            'Saturday must keep the earlier weekend opening'
        );
        $this->assertSame(
            ['open' => '08:00', 'close' => '22:00'],
            $fort->hoursForDay(Carbon::parse('2026-09-28')),
            'Monday must use the weekday window'
        );
        $this->assertSame('https://intramuros.gov.ph/hours/', $fort->hours_source_url);
        $this->assertSame('Intramuros Administration', $fort->hours_source_label);
        $this->assertStringContainsString('Last entry', (string) $fort->hours_note);
    }

    public function test_the_csv_seeder_keeps_every_place_name_intact(): void
    {
        $this->seed(TagSeeder::class);
        $this->seed(LuzonLocationsCsvSeeder::class);

        $names = Destination::pluck('name');

        $this->assertCount(65, $names);

        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression(
                '/(Last entry|Gate hours|Schedule|Closed on|^\s*$)/',
                $name,
                'a label or note leaked into the name column: '.$name
            );
        }
    }

    public function test_the_csv_seeder_infers_always_open_for_a_full_day_window(): void
    {
        $this->seed(TagSeeder::class);
        $this->seed(LuzonLocationsCsvSeeder::class);

        $bangui = Destination::where('slug', 'bangui-wind-farm')->firstOrFail();

        $this->assertSame(
            Destination::HOURS_ALWAYS_OPEN,
            $bangui->hours_kind,
            'a 00:00-23:59 window means ungated, not a normal 24-hour opening'
        );
    }

    public function test_the_csv_seeder_infers_per_day_when_daily_hours_are_set(): void
    {
        $this->seed(TagSeeder::class);
        $this->seed(LuzonLocationsCsvSeeder::class);

        $fort = Destination::where('slug', 'fort-santiago')->firstOrFail();

        $this->assertSame(Destination::HOURS_PER_DAY, $fort->hours_kind);
    }

    public function test_the_csv_seeder_keeps_an_explicit_registration_window(): void
    {
        $this->seed(TagSeeder::class);
        $this->seed(LuzonLocationsCsvSeeder::class);

        $pinatubo = Destination::where('slug', 'mt-pinatubo')->firstOrFail();

        $this->assertSame(Destination::HOURS_REGISTRATION, $pinatubo->hours_kind);
        $this->assertTrue($pinatubo->hoursKindIsAdvisory());
        $this->assertStringContainsString('registration', (string) $pinatubo->hoursKindLabel());
    }

    public function test_no_seeded_destination_carries_an_unknown_kind(): void
    {
        $this->seed(TagSeeder::class);
        $this->seed(LuzonLocationsCsvSeeder::class);

        $unknown = Destination::query()
            ->whereNotNull('hours_kind')
            ->whereNotIn('hours_kind', Destination::HOURS_KINDS)
            ->pluck('name')
            ->all();

        $this->assertSame([], $unknown);
    }

    public function test_the_fringe_cases_are_labelled_rather_than_left_implicit(): void
    {
        $this->seed(TagSeeder::class);
        $this->seed(LuzonLocationsCsvSeeder::class);

        $expected = [
            'mt-pinatubo' => Destination::HOURS_REGISTRATION,
            'mt-daraitan' => Destination::HOURS_REGISTRATION,
            'mt-ulap' => Destination::HOURS_REGISTRATION,
            'palaui-island' => Destination::HOURS_REGISTRATION,
            'cape-engano-lighthouse' => Destination::HOURS_REGISTRATION,
            'la-mesa-eco-park' => Destination::HOURS_RESERVATION,
            'taal-volcano-view' => Destination::HOURS_ALERT_DEPENDENT,
            'mayon-volcano-natural-park' => Destination::HOURS_ALERT_DEPENDENT,
            'fort-santiago' => Destination::HOURS_PER_DAY,
            'taal-basilica' => Destination::HOURS_PER_DAY,
            'burnham-park' => Destination::HOURS_ALWAYS_OPEN,
        ];

        foreach ($expected as $slug => $kind) {
            $this->assertSame(
                $kind,
                Destination::where('slug', $slug)->firstOrFail()->hours_kind,
                $slug.' should be labelled as '.$kind
            );
        }
    }

    public function test_the_admin_form_offers_hours_and_closed_day_inputs(): void
    {
        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->get(route('admin.destinations.edit', $destination))
            ->assertOk()
            ->assertSee('name="opening_time"', false)
            ->assertSee('name="closing_time"', false)
            ->assertSee('name="operating_status"', false)
            ->assertSee('name="closed_days[]"', false)
            ->assertSee('value="monday"', false)
            ->assertSee('name="daily_hours[monday][open]"', false)
            ->assertSee('name="hours_source_url"', false)
            ->assertSee('name="hours_source_label"', false)
            ->assertSee('name="hours_note"', false);
    }

    public function test_the_create_form_renders_without_a_destination(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.destinations.create'))
            ->assertOk()
            ->assertSee('name="opening_time"', false)
            ->assertSee('name="closed_days[]"', false);
    }

    public function test_an_admin_can_save_hours_and_closed_days(): void
    {
        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->put(route('admin.destinations.update', $destination), $this->payload($destination, [
                'opening_time' => '09:00',
                'closing_time' => '18:00',
                'operating_status' => 'open',
                'closed_days' => ['monday'],
            ]))
            ->assertRedirect(route('admin.destinations.index'));

        $destination->refresh();

        $this->assertSame('09:00', substr((string) $destination->opening_time, 0, 5));
        $this->assertSame('18:00', substr((string) $destination->closing_time, 0, 5));
        $this->assertSame('monday', $destination->closed_days);
        $this->assertSame('open', $destination->operating_status);
    }

    public function test_saving_hours_stamps_last_verified_at(): void
    {
        $destination = $this->destination(['last_verified_at' => null]);

        $this->assertNull($destination->last_verified_at);

        $this->actingAs($this->admin())
            ->put(route('admin.destinations.update', $destination), $this->payload($destination, [
                'opening_time' => '09:00',
                'closing_time' => '18:00',
            ]));

        $this->assertNotNull($destination->fresh()->last_verified_at);
    }

    public function test_an_unrelated_edit_does_not_restamp_last_verified_at(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');

        $destination = $this->destination([
            'opening_time' => '09:00',
            'closing_time' => '18:00',
            'last_verified_at' => '2026-08-01 10:00:00',
        ]);

        Carbon::setTestNow('2026-09-27 10:00:00');

        $this->actingAs($this->admin())
            ->put(route('admin.destinations.update', $destination), $this->payload($destination, [
                'opening_time' => '09:00',
                'closing_time' => '18:00',
                'name' => 'Renamed Site',
            ]));

        $this->assertSame(
            '2026-08-01 10:00:00',
            $destination->fresh()->last_verified_at->format('Y-m-d H:i:s')
        );

        Carbon::setTestNow();
    }

    public function test_adding_a_closed_day_alone_restamps_last_verified_at(): void
    {
        $destination = $this->destination([
            'opening_time' => '09:00',
            'closing_time' => '18:00',
            'last_verified_at' => '2026-08-01 10:00:00',
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.destinations.update', $destination), $this->payload($destination, [
                'opening_time' => '09:00',
                'closing_time' => '18:00',
                'closed_days' => ['monday'],
            ]));

        $this->assertNotSame(
            '2026-08-01 10:00:00',
            $destination->fresh()->last_verified_at->format('Y-m-d H:i:s')
        );
    }

    public function test_it_rejects_an_unknown_closed_day(): void
    {
        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->from(route('admin.destinations.edit', $destination))
            ->put(route('admin.destinations.update', $destination), $this->payload($destination, [
                'closed_days' => ['funday'],
            ]))
            ->assertSessionHasErrors('closed_days.0');
    }

    public function test_it_rejects_an_unknown_operating_status(): void
    {
        $destination = $this->destination();

        $this->actingAs($this->admin())
            ->from(route('admin.destinations.edit', $destination))
            ->put(route('admin.destinations.update', $destination), $this->payload($destination, [
                'operating_status' => 'maybe',
            ]))
            ->assertSessionHasErrors('operating_status');
    }

    public function test_clearing_every_closed_day_stores_null(): void
    {
        $destination = $this->destination(['closed_days' => 'monday,tuesday']);

        $this->actingAs($this->admin())
            ->put(route('admin.destinations.update', $destination), $this->payload($destination, [
                'closed_days' => [],
            ]));

        $this->assertNull($destination->fresh()->closed_days);
    }

    public function test_the_model_ignores_unknown_days_stored_in_the_column(): void
    {
        $destination = $this->destination(['closed_days' => 'funday,monday,xyz']);

        $this->assertSame(['monday'], $destination->closedDayList());
    }

    public function test_the_public_page_says_closed_today_on_a_closed_day(): void
    {
        Carbon::setTestNow('2026-09-28 10:00:00');

        $destination = $this->destination([
            'opening_time' => '09:00',
            'closing_time' => '18:00',
            'closed_days' => 'monday',
        ]);

        $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertSee('Closed today (Monday)')
            ->assertSee('Closed on Monday');

        Carbon::setTestNow();
    }

    public function test_the_public_page_reports_open_on_an_open_day(): void
    {
        Carbon::setTestNow('2026-09-29 10:00:00');

        $destination = $this->destination([
            'opening_time' => '09:00',
            'closing_time' => '18:00',
            'closed_days' => 'monday',
        ]);

        $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertSee('Open now')
            ->assertSee('Closed on Monday');

        Carbon::setTestNow();
    }

    public function test_a_closed_day_is_not_shown_when_there_are_none(): void
    {
        $destination = $this->destination([
            'opening_time' => '09:00',
            'closing_time' => '18:00',
        ]);

        $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertDontSee('Closed on');
    }

    public function test_the_csv_seeder_reads_hours_columns(): void
    {
        $this->seed(TagSeeder::class);
        $this->seed(LuzonLocationsCsvSeeder::class);

        $pinto = Destination::where('slug', 'pinto-art-museum')->firstOrFail();

        $this->assertSame('10:00', substr((string) $pinto->opening_time, 0, 5));
        $this->assertSame('18:00', substr((string) $pinto->closing_time, 0, 5));
        $this->assertSame('monday', $pinto->closed_days);
        $this->assertNotNull($pinto->last_verified_at);
    }

    public function test_the_csv_seeder_leaves_hours_blank_when_the_cells_are_empty(): void
    {
        $this->seed(TagSeeder::class);
        $this->seed(LuzonLocationsCsvSeeder::class);

        $blank = Destination::whereNull('opening_time')->count();

        $this->assertGreaterThan(
            0,
            $blank,
            'unverified destinations must stay blank rather than inherit a guess'
        );
    }
}
