<?php

namespace Tests\Feature\Admin;

use App\Jobs\CrawlSourceJob;
use App\Models\Destination;
use App\Models\DestinationSource;
use App\Models\User;
use App\Services\Crawling\SourceReachabilityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SourceReachabilityTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private const PERMISSIVE_ROBOTS = "User-agent: *\nDisallow: /wp-admin/\nAllow: /\n";

    private function source(array $attributes = []): DestinationSource
    {
        $this->sequence++;

        $destination = Destination::create([
            'name' => 'Reachability '.$this->sequence,
            'slug' => 'reachability-'.$this->sequence,
            'description' => 'A place.',
            'province' => 'Manila',
            'latitude' => 14.6,
            'longitude' => 120.9,
            'budget_level' => 'economy',
            'estimated_cost' => 500,
            'recommended_minutes' => 120,
        ]);

        return DestinationSource::create(array_merge([
            'destination_id' => $destination->id,
            'source_name' => 'Source '.$this->sequence,
            'source_url' => 'https://example'.$this->sequence.'.test/place',
            'source_type' => 'official_lgu',
        ], $attributes));
    }

    private function fakeRobots(string $body = self::PERMISSIVE_ROBOTS, int $status = 200): void
    {
        Http::fake([
            '*/robots.txt' => Http::response($body, $status),
        ]);
    }

    public function test_it_marks_a_page_with_real_text_as_readable(): void
    {
        $source = $this->source();

        $this->fakeRobots();
        Http::fake([
            '*/robots.txt' => Http::response(self::PERMISSIVE_ROBOTS, 200),
            '*' => Http::response('<html><body>'.str_repeat('Entrance fee is 200 pesos. ', 40).'</body></html>', 200),
        ]);

        $result = app(SourceReachabilityChecker::class)->check($source);

        $this->assertSame(DestinationSource::FETCHABLE, $result['fetchability']);
        $this->assertSame(DestinationSource::FETCHABLE, $source->fresh()->fetchability);
        $this->assertNotNull($source->fresh()->fetchability_checked_at);
        $this->assertFalse($source->isCrawlUnreachable());
    }

    public function test_it_marks_a_javascript_shell_as_needing_javascript(): void
    {
        $source = $this->source();

        Http::fake([
            '*/robots.txt' => Http::response(self::PERMISSIVE_ROBOTS, 200),
            '*' => Http::response(
                '<html><head><script id="__NEXT_DATA__" type="application/json">{}</script></head>'
                .'<body>Loading park details...</body></html>',
                200
            ),
        ]);

        $result = app(SourceReachabilityChecker::class)->check($source);

        $this->assertSame(DestinationSource::JS_RENDERED, $result['fetchability']);
        $this->assertTrue($source->fresh()->needsRendering());
        $this->assertFalse($source->isCrawlUnreachable());
    }

    public function test_a_nearly_empty_page_is_not_called_readable(): void
    {
        $source = $this->source();

        Http::fake([
            '*/robots.txt' => Http::response(self::PERMISSIVE_ROBOTS, 200),
            '*' => Http::response('<html><body>Coming soon</body></html>', 200),
        ]);

        $result = app(SourceReachabilityChecker::class)->check($source);

        $this->assertSame(
            DestinationSource::THIN_PAGE,
            $result['fetchability'],
            'a 12-character page must not be reported as readable'
        );
        $this->assertTrue($source->fresh()->isCrawlUnreachable());
        $this->assertStringContainsString('nearly empty', $result['note']);
    }

    public function test_a_framework_mention_on_a_full_page_is_not_treated_as_javascript(): void
    {
        $source = $this->source();

        Http::fake([
            '*/robots.txt' => Http::response(self::PERMISSIVE_ROBOTS, 200),
            '*' => Http::response(
                '<html><body><noscript>Please enable JavaScript</noscript>'
                .str_repeat('Opening hours are 9:00 AM to 6:00 PM daily. ', 40)
                .'</body></html>',
                200
            ),
        ]);

        $result = app(SourceReachabilityChecker::class)->check($source);

        $this->assertSame(
            DestinationSource::FETCHABLE,
            $result['fetchability'],
            'a noscript hint on a text-rich page must not trigger a render'
        );
    }

    public function test_it_marks_a_forbidden_response_as_blocked(): void
    {
        $source = $this->source();

        Http::fake([
            '*/robots.txt' => Http::response(self::PERMISSIVE_ROBOTS, 200),
            '*' => Http::response('Forbidden', 403),
        ]);

        $result = app(SourceReachabilityChecker::class)->check($source);

        $this->assertSame(DestinationSource::BOT_WALL, $result['fetchability']);
        $this->assertStringContainsString('403', $result['note']);
        $this->assertTrue($source->fresh()->isCrawlUnreachable());
    }

    public function test_it_marks_a_gone_page_as_dead(): void
    {
        $source = $this->source();

        Http::fake([
            '*/robots.txt' => Http::response(self::PERMISSIVE_ROBOTS, 200),
            '*' => Http::response('Not found', 404),
        ]);

        $result = app(SourceReachabilityChecker::class)->check($source);

        $this->assertSame(DestinationSource::DEAD, $result['fetchability']);
    }

    public function test_it_marks_a_dns_failure_as_unreachable(): void
    {
        $source = $this->source();

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'robots.txt')) {
                return Http::response(self::PERMISSIVE_ROBOTS, 200);
            }

            return Http::failedConnection('Could not resolve host');
        });

        $result = app(SourceReachabilityChecker::class)->check($source);

        $this->assertSame(DestinationSource::UNREACHABLE, $result['fetchability']);
        $this->assertTrue($source->fresh()->isCrawlUnreachable());
    }

    public function test_an_unavailable_robots_txt_does_not_pretend_the_site_refuses_us(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'robots.txt')) {
                return Http::response('gateway error', 502);
            }

            return Http::response(
                '<html><body>'.str_repeat('Entrance fee is 200 pesos. ', 40).'</body></html>',
                200
            );
        });

        $source = $this->source();

        $result = app(SourceReachabilityChecker::class)->check($source);

        $this->assertSame(
            DestinationSource::FETCHABLE,
            $result['fetchability'],
            'a broken robots.txt must not be reported as a refusal'
        );
        $this->assertStringContainsString('robots.txt was unavailable', $result['note']);
    }

    public function test_a_host_that_does_not_resolve_is_unreachable_not_refused(): void
    {
        Http::fake(fn () => Http::failedConnection('Could not resolve host'));

        $source = $this->source();

        $result = app(SourceReachabilityChecker::class)->check($source);

        $this->assertSame(DestinationSource::UNREACHABLE, $result['fetchability']);
    }

    public function test_a_disallowed_path_is_never_fetched(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/robots.txt' => Http::response("User-agent: *\nDisallow: /place\n", 200),
        ]);

        $source = $this->source(['source_url' => 'https://blocked.test/place']);

        $result = app(SourceReachabilityChecker::class)->check($source);

        $this->assertSame(DestinationSource::ROBOTS_BLOCKED, $result['fetchability']);

        Http::assertNotSent(
            fn ($request) => str_contains($request->url(), '/place')
        );
    }

    public function test_the_dashboard_filters_by_reachability(): void
    {
        $readable = $this->source();
        $readable->update(['fetchability' => DestinationSource::FETCHABLE]);

        $dead = $this->source();
        $dead->update(['fetchability' => DestinationSource::DEAD]);

        $this->source();

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.sources.index', ['reachability' => 'dead']))
            ->assertOk()
            ->assertSee($dead->destination->name)
            ->assertDontSee($readable->destination->name);

        $this->actingAs($admin)
            ->get(route('admin.sources.index', ['reachability' => 'unchecked']))
            ->assertOk()
            ->assertSee('Page reachability')
            ->assertDontSee($readable->destination->name);
    }

    public function test_select_all_respects_the_reachability_filter(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);

        foreach (range(1, 3) as $ignored) {
            $this->source(['fetchability' => DestinationSource::FETCHABLE]);
        }

        $this->source(['fetchability' => DestinationSource::DEAD]);

        $this->actingAs($admin)
            ->from(route('admin.sources.index'))
            ->post(route('admin.sources.bulk-crawl'), [
                'select_all' => 1,
                'filter_search' => '',
                'filter_status' => '',
                'filter_reachability' => 'fetchable',
            ])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(CrawlSourceJob::class, 3);
    }

    public function test_a_thin_page_is_filterable_in_the_dashboard(): void
    {
        $thin = $this->source();
        $thin->update(['fetchability' => DestinationSource::THIN_PAGE]);

        $readable = $this->source();
        $readable->update(['fetchability' => DestinationSource::FETCHABLE]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.sources.index', ['reachability' => 'thin_page']))
            ->assertOk()
            ->assertSee('Nearly empty')
            ->assertSee($thin->destination->name)
            ->assertDontSee($readable->destination->name);
    }

    public function test_fetchability_counts_include_unchecked_sources(): void
    {
        $this->source(['fetchability' => DestinationSource::FETCHABLE]);
        $this->source(['fetchability' => DestinationSource::FETCHABLE]);
        $this->source(['fetchability' => DestinationSource::JS_RENDERED]);
        $this->source();

        $counts = DestinationSource::fetchabilityCounts();

        $this->assertSame(2, $counts[DestinationSource::FETCHABLE]);
        $this->assertSame(1, $counts[DestinationSource::JS_RENDERED]);
        $this->assertSame(1, $counts['unchecked']);
    }
}
