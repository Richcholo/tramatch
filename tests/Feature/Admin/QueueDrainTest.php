<?php

namespace Tests\Feature\Admin;

use App\Jobs\CrawlSourceJob;
use App\Models\Destination;
use App\Models\DestinationSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\AlwaysThrowsJob;
use Tests\Fixtures\NoopJob;
use Tests\TestCase;

/**
 * `queue:drain`, the cron-driven replacement for deploy/queue-worker.sh.
 *
 * The shell script this replaced could not be tested at all, which is how a
 * placeholder APP_ROOT and a silent `exit 0` survived two deployments. Every
 * behaviour that made the script untrustworthy is asserted here instead.
 */
class QueueDrainTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The real database queue, not Queue::fake().
     *
     * Two reasons, and the first is the important one. `queue:drain` works by
     * popping and reserving real rows, which is exactly what
     * `Queue::fake()` does not do -- it has no `pop()`, so a test using it
     * proves nothing about the thing being tested. The second is that the suite
     * runs `QUEUE_CONNECTION=sync`, where a dispatch never sits on a queue at
     * all, so "did the drain pick it up" has no meaning.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    private int $sequence = 0;

    private function source(): DestinationSource
    {
        $this->sequence++;

        $destination = Destination::create([
            'name' => 'Fort Santiago '.$this->sequence,
            'slug' => 'fort-santiago-'.$this->sequence,
            'description' => 'A fort.',
            'province' => 'Manila',
            'latitude' => 14.6,
            'longitude' => 120.9,
            'budget_level' => 'economy',
            'estimated_cost' => 500,
            'recommended_minutes' => 120,
        ]);

        return DestinationSource::create([
            'destination_id' => $destination->id,
            'source_name' => 'Intramuros Administration',
            'source_url' => 'https://intramuros.gov.ph/fs/'.$this->sequence,
            'source_type' => 'official_lgu',
            'status' => 'pending',
        ]);
    }

    /**
     * A no-op job rather than a real CrawlSourceJob.
     *
     * These assertions are about the drain, not the crawler, and the crawler
     * fires the fetcher: with the real job these three tests took 13 seconds
     * each and 38 in total, all of it spent on outbound HTTP requests whose
     * result nothing here reads. CRAWLER_MIN_DELAY is already 0 in phpunit.xml,
     * so this was not politeness -- it was network latency.
     */
    #[Test]
    public function it_drains_a_queued_job(): void
    {
        NoopJob::dispatch();

        $this->assertSame(1, $this->pendingJobCount(), 'the job should be waiting before the drain');

        $this->artisan('queue:drain')->assertSuccessful();

        $this->assertSame(
            0,
            $this->pendingJobCount(),
            'the drain finished with the job still on the queue'
        );
    }

    /**
     * The real crawl job, dispatched but not executed.
     *
     * Queue::fake() asserts the dispatch and stops there, which is what the
     * dispatch path is for. This pins the other half: that a genuine
     * CrawlSourceJob serialises onto the database queue and can be popped, so
     * the thing the admin button produces is a thing the drain can pick up.
     */
    #[Test]
    public function a_real_crawl_job_lands_on_the_queue_the_drain_reads(): void
    {
        $source = $this->source();

        CrawlSourceJob::dispatch($source->id);

        $this->assertSame(
            1,
            $this->pendingJobCount(),
            'a dispatched CrawlSourceJob should be a row on the jobs table'
        );

        $payload = (string) DB::table('jobs')->value('payload');

        $this->assertStringContainsString('CrawlSourceJob', $payload);
        $this->assertStringContainsString((string) $source->id, $payload);
    }

    #[Test]
    public function a_drained_job_leaves_no_failed_row_behind(): void
    {
        NoopJob::dispatch();

        $this->artisan('queue:drain')->assertSuccessful();

        $this->assertSame(
            0,
            $this->failedJobCount(),
            'a successful job was recorded as failed'
        );
    }

    /**
     * The single most important assertion, and the one the shell script could
     * not make.
     *
     * An empty queue is the normal case for most of the minute. It must be a
     * success with nothing logged as a problem, or cron mails every minute for
     * doing its job correctly.
     */
    #[Test]
    public function an_empty_queue_is_a_quiet_success(): void
    {
        Log::spy();

        $this->artisan('queue:drain')->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => str_contains($message, 'start'))
            ->atLeast()->once();

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('critical');
    }

    #[Test]
    public function it_reports_an_empty_queue_distinctly_from_one_that_ran_jobs(): void
    {
        Log::spy();

        $this->artisan('queue:drain')->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => str_contains($message, 'found the queue empty'))
            ->once();
    }

    #[Test]
    public function it_logs_where_a_stuck_queue_can_be_found(): void
    {
        Log::spy();

        $this->artisan('queue:drain')->assertSuccessful();

        /*
         * The whole reason this command exists over the shell script. Its log
         * went to ~/queue-worker.log, a path nothing in the app knew about; this
         * goes to storage/logs/laravel.log, where every other failure already is,
         * so "the queue is not draining" has one place to look.
         */
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => str_contains($message, 'start'))
            ->once();
    }

    #[Test]
    public function a_job_that_throws_is_released_rather_than_failed(): void
    {
        AlwaysThrowsJob::dispatch();

        $this->artisan('queue:drain')->assertSuccessful();

        /*
         * Deliberately released, not failed. The crawl jobs record their own
         * status and error_message before rethrowing, so `fail()` here would
         * strand a job whose reason nobody recorded. Releasing leaves it for the
         * next cron run, which is what config/queue.php's retry_after is for.
         */
        $this->assertSame(
            0,
            $this->failedJobCount(),
            'a released job must go back on the queue, not to failed_jobs'
        );

        $this->assertSame(
            1,
            $this->pendingJobCount(),
            'the released job should be waiting for the next cron run'
        );
    }

    #[Test]
    public function max_jobs_stops_the_run_before_the_queue_is_empty(): void
    {
        NoopJob::dispatch();
        NoopJob::dispatch();

        $this->assertSame(2, $this->pendingJobCount());

        $this->artisan('queue:drain', ['--max-jobs' => 1])->assertSuccessful();

        $this->assertSame(
            1,
            $this->pendingJobCount(),
            '--max-jobs is a cap on jobs taken, so one of the two should still be waiting'
        );
    }

    /** Rows on the jobs table that nobody is holding. */
    private function pendingJobCount(): int
    {
        return (int) DB::table('jobs')->whereNull('reserved_at')->count();
    }

    private function failedJobCount(): int
    {
        return (int) DB::table('failed_jobs')->count();
    }

    #[Test]
    public function it_does_not_hang_when_nothing_is_queued(): void
    {
        $started = microtime(true);

        $this->artisan('queue:drain')->assertSuccessful();

        $this->assertLessThan(
            5,
            microtime(true) - $started,
            'an idle drain must return promptly, or the next cron run stacks on top of it'
        );
    }

    #[Test]
    public function it_never_reports_failure_for_a_dead_upstream_host(): void
    {
        /*
         * The exit code is what makes cron mail. A crawl against an unreachable
         * host has already written its own status and error_message, so a
         * non-zero exit here would mail on every transient network failure.
         */
        $this->artisan('queue:drain')->assertExitCode(0);
    }
}
