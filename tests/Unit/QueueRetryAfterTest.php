<?php

namespace Tests\Unit;

use App\Jobs\CrawlSourceJob;
use Tests\TestCase;

/**
 * retry_after and the per-job timeout are two halves of one rule, and the
 * failure mode when they disagree is silent: the job runs twice.
 *
 * A job is only released for another attempt once retry_after has passed, not
 * once it has finished. So if retry_after is 90 and CrawlSourceJob allows 300
 * seconds, a crawl that takes 100 seconds is handed to a second worker while
 * the first is still fetching, and both write a snapshot and proposals for the
 * same source. Nothing errors. The queue just quietly does the work twice, and
 * `proposals_created` doubles with it.
 *
 * That needs two workers to happen, which is exactly the production shape:
 * hPanel cron running queue:work alongside a queue:listen.
 *
 * config/queue.php derives retry_after from QUEUE_WORKER_TIMEOUT for this
 * reason. These assertions exist so that someone raising one without the other
 * finds out here rather than on the sources dashboard.
 */
class QueueRetryAfterTest extends TestCase
{
    public function test_no_connection_releases_a_job_before_the_crawl_can_finish(): void
    {
        $timeout = $this->crawlTimeout();

        foreach (['database', 'redis', 'beanstalkd'] as $connection) {
            $this->assertGreaterThan(
                $timeout,
                (int) config("queue.connections.{$connection}.retry_after"),
                "the {$connection} connection hands a crawl to a second worker while the first is "
                .'still running it, so one click produces two crawls and two sets of proposals'
            );
        }
    }

    public function test_the_worker_timeout_cannot_outlive_the_retry_window(): void
    {
        // AppServiceProvider re-registers queue:listen with this value for
        // `php artisan dev`. The crawl job's own $timeout outranks any worker
        // flag, so whichever of the two is larger is the real ceiling.
        $workerTimeout = (int) env('QUEUE_WORKER_TIMEOUT', 300);

        $this->assertGreaterThan(
            $workerTimeout,
            (int) config('queue.connections.database.retry_after'),
            'the queue:listen registered for `php artisan dev` outlives the retry window'
        );
    }

    public function test_the_crawl_job_still_declares_a_per_job_timeout(): void
    {
        // A crawl sleeps at least five seconds, makes two HTTP requests and can
        // read a 2 MB body. With no timeout a hung host blocks the worker
        // forever and every later job waits behind it.
        $this->assertGreaterThan(
            0,
            $this->crawlTimeout(),
            'CrawlSourceJob lost its $timeout, so a hung crawl blocks the worker indefinitely'
        );
    }

    private function crawlTimeout(): int
    {
        $job = new CrawlSourceJob(0);

        $this->assertGreaterThan(
            0,
            $job->timeout,
            'CrawlSourceJob lost its $timeout, so a hung crawl blocks the worker indefinitely'
        );

        return $job->timeout;
    }
}