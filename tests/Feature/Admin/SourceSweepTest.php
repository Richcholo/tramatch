<?php

namespace Tests\Feature\Admin;

use App\Console\Commands\ReportSourceDrift;
use App\Console\Commands\SweepDestinationSources;
use App\Jobs\CrawlSourceJob;
use App\Models\Destination;
use App\Models\DestinationSource;
use App\Models\DestinationSourceCrawl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SourceSweepTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function source(array $attributes = []): DestinationSource
    {
        $this->sequence++;

        $destination = Destination::create([
            'name' => 'Sweep Place '.$this->sequence,
            'slug' => 'sweep-place-'.$this->sequence,
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
            'source_name' => 'LGU '.$this->sequence,
            'source_url' => 'https://example'.$this->sequence.'.gov.ph/',
            'source_type' => 'official_lgu',
            'status' => 'pending',
        ], $attributes));
    }

    private function crawl(DestinationSource $source, bool $changed): DestinationSourceCrawl
    {
        return $source->crawls()->create([
            'attempt' => 1,
            'outcome' => $changed ? 'success' : 'unchanged',
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 900,
            'http_status' => 200,
            'requested_url' => $source->source_url,
            'final_url' => $source->source_url,
            'bytes' => 1200,
            'content_hash' => $changed ? 'hash-b' : 'hash-a',
            'content_changed' => $changed,
            'extracted_fields' => [],
            'proposals_created' => $changed ? 2 : 0,
        ]);
    }

    /**
     * THE HEADLINE CASE, because it is the one that made the sweep pointless
     * before: a clean sweep must SAY SO.
     *
     * A report that prints only exceptions makes "every page still matches"
     * look identical to "the command is broken", which is how a check nobody
     * trusts stops being run.
     */
    public function test_a_clean_sweep_reports_no_changes_found(): void
    {
        $source = $this->source();
        $this->crawl($source, changed: false);

        $this->artisan('sources:drift')
            ->expectsOutputToContain('No changes found')
            ->assertSuccessful();
    }

    public function test_a_changed_page_is_reported_with_its_proposals(): void
    {
        $source = $this->source(['source_name' => 'Vigan City']);
        $this->crawl($source, changed: true);

        $this->artisan('sources:drift')
            ->expectsOutputToContain('Vigan City')
            ->expectsOutputToContain('1 page has changed; 2 proposals are awaiting review')
            ->assertSuccessful();
    }

    /**
     * THE LAST CRAWL PER SOURCE IS THE DATA POINT, not the sum of the log.
     *
     * A source swept three times is ONE source. Summing its log rows reports
     * one changed page as three, and judging it on its EARLIEST run would leave
     * a source that changed and then changed back looking permanently in flux
     * — which is how an admin learns to ignore the report. It is judged on its
     * most recent crawl: unchanged now, and reported as unchanged now.
     */
    public function test_repeated_crawls_collapse_to_one_source(): void
    {
        $source = $this->source();

        $this->crawl($source, changed: true);
        $this->crawl($source, changed: false);
        $this->crawl($source, changed: false);

        // ONE source, not three -- the join collapses to the latest crawl.
        $this->artisan('sources:drift')
            ->expectsOutputToContain('Checked 1 source. 1 source unchanged, 0 sources changed.')
            ->assertSuccessful();
    }

    public function test_the_latest_crawl_decides_the_outcome(): void
    {
        $source = $this->source();

        // Changed, then changed back.
        $this->crawl($source, changed: true);
        $this->crawl($source, changed: false);

        $this->artisan('sources:drift')
            ->expectsOutputToContain('Checked 1 source. 1 source unchanged, 0 sources changed.')
            ->assertSuccessful();
    }

    /**
     * A FIRST crawl has nothing to compare against, and reporting it as
     * "unchanged" would be a lie that hides the fact it was never checked.
     */
    public function test_a_first_crawl_is_reported_as_having_nothing_to_compare(): void
    {
        $source = $this->source();

        $source->crawls()->create([
            'attempt' => 1,
            'outcome' => 'success',
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 900,
            'http_status' => 200,
            'content_hash' => 'hash-a',
            'content_changed' => null,
            'proposals_created' => 1,
        ]);

        $this->artisan('sources:drift')
            ->expectsOutputToContain('no previous crawl to compare against')
            ->assertSuccessful();
    }

    public function test_it_says_so_when_there_is_nothing_to_report(): void
    {
        $this->artisan('sources:drift')
            ->expectsOutputToContain('No crawls in the last 7 day(s)')
            ->assertSuccessful();
    }

    public function test_the_sweep_queues_every_active_source(): void
    {
        Queue::fake();

        $a = $this->source();
        $b = $this->source();

        $this->artisan('sources:sweep')->assertSuccessful();

        Queue::assertPushed(CrawlSourceJob::class, 2);
        Queue::assertPushed(
            CrawlSourceJob::class,
            fn (CrawlSourceJob $job) => $job->sourceId === $a->id
        );
        Queue::assertPushed(
            CrawlSourceJob::class,
            fn (CrawlSourceJob $job) => $job->sourceId === $b->id
        );

        $this->assertSame('queued', $a->fresh()->status);
    }

    /**
     * A source that cannot be read stays unreadable until someone fixes its
     * URL. Re-asking it every sweep is how a sweep becomes a slow way of
     * rediscovering the same bot walls, so it is skipped -- but `--all` opts
     * back in, because proving a host is STILL refusing is a real question.
     */
    public function test_the_sweep_skips_unreadable_sources_unless_asked(): void
    {
        Queue::fake();

        $readable = $this->source();
        $dead = $this->source(['status' => 'failed']);
        $blocked = $this->source(['status' => 'blocked']);

        $this->artisan('sources:sweep')->assertSuccessful();

        Queue::assertPushed(CrawlSourceJob::class, 1);
        Queue::assertPushed(
            CrawlSourceJob::class,
            fn (CrawlSourceJob $job) => $job->sourceId === $readable->id
        );

        Queue::fake();

        // Only the two that were skipped, because the readable one was queued
        // by the sweep above and is now genuinely in flight.
        $this->artisan('sources:sweep', ['--all' => true])->assertSuccessful();

        Queue::assertPushed(CrawlSourceJob::class, 2);
        Queue::assertPushed(
            CrawlSourceJob::class,
            fn (CrawlSourceJob $job) => $job->sourceId === $dead->id
        );
        Queue::assertPushed(
            CrawlSourceJob::class,
            fn (CrawlSourceJob $job) => $job->sourceId === $blocked->id
        );
    }

    /**
     * A source already queued or crawling must not be dispatched twice, which
     * is the same double-crawl trap `hasCrawlInFlight()` guards everywhere
     * else in this app.
     *
     * `last_checked_at` is set because it is load-bearing: `isQueued()` is
     * `status === 'queued' && !isCrawlStale()`, and a queued row with a NULL
     * timestamp is STALE by definition. So a fixture that sets only `status`
     * reads as stalled, is deliberately retryable, and would have made this
     * test pass for the wrong reason while the guard did nothing.
     */
    public function test_the_sweep_does_not_double_dispatch(): void
    {
        Queue::fake();

        $queued = $this->source(['status' => 'queued', 'last_checked_at' => now()]);
        $crawling = $this->source(['status' => 'crawling', 'last_checked_at' => now()]);

        $this->assertTrue(
            $queued->hasCrawlInFlight(),
            'the fixture must be genuinely in flight, or this test proves nothing'
        );
        $this->assertTrue($crawling->hasCrawlInFlight());

        $this->artisan('sources:sweep')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /**
     * AND A STALLED ROW IS STILL RETRYABLE — the sweep is a recovery path, so
     * it must pick up what a dead worker left behind.
     */
    public function test_the_sweep_retries_a_stalled_row(): void
    {
        Queue::fake();

        $source = $this->source([
            'status' => 'queued',
            'last_checked_at' => now()->subDays(3),
        ]);

        $this->assertTrue(
            $source->isCrawlStale(),
            'a queued row older than the stale threshold is stalled'
        );
        $this->assertFalse(
            $source->hasCrawlInFlight(),
            'a stalled row must stay retryable, or it becomes uncrawlable forever'
        );

        $this->artisan('sources:sweep')->assertSuccessful();

        Queue::assertPushed(CrawlSourceJob::class, 1);
    }
}
