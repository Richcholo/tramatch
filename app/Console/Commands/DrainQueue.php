<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drain the queue once, for shared hosting that cannot run a resident worker.
 *
 * Why this exists instead of a shell script. hPanel offers cron and nothing
 * else -- no supervisor, no daemon -- so something has to drain the queue from
 * outside the request lifecycle. It was `deploy/queue-worker.sh`, and that had
 * three separate ways to fail silently on this host, all of them invisible:
 *
 *  - The script had to be told where the app root was, via an `APP_ROOT`
 *    variable whose default was a literal placeholder. Edit it wrong and it
 *    exited 0 having done nothing, so cron reported success every minute.
 *  - It resolved its own PHP binary, because cron has a minimal PATH. That is a
 *    second guess at something the host already knows.
 *  - It wrote to `~/queue-worker.log`, a path nothing in the application knows
 *    about and nothing in the UI links to.
 *
 * As an artisan command all three disappear. Laravel already knows the app root
 * and already knows which PHP is running. The log goes to the application's own
 * log file, where the rest of the failures already are. And this is testable,
 * which the shell script never was.
 *
 * What it still cannot do is daemonise. That is fine: cron runs this every
 * minute, each run takes only as long as the work in front of it, and
 * `--stop-when-empty` is what stops a slow run from overlapping the next one.
 */
class DrainQueue extends Command
{
    protected $signature = 'queue:drain
        {--max-jobs=0 : Stop after this many jobs. 0 means no limit.}
        {--max-seconds=240 : Wall-clock cap. Stops one cron run overlapping the next.}
        {--timeout=0 : Seconds to wait for a free job before giving up. 0 means do not wait.}';

    protected $description = 'Process queued jobs until the queue is empty (for shared-hosting cron)';

    public function handle(): int
    {
        $maxJobs = max(0, (int) $this->option('max-jobs'));
        $timeout = max(0, (int) $this->option('timeout'));

        /*
         * A crawl sleeps at least five seconds and makes two HTTP requests, so a
         * run can legitimately last minutes. This cap is not a timeout on the
         * work -- that belongs on CrawlSourceJob's own $timeout -- it stops one
         * cron invocation from running long enough to overlap the next.
         *
         * The default is under the one-minute cron interval for exactly that
         * reason. Two workers on one queue is what config/queue.php's
         * retry_after invariant exists to prevent, and the cheapest way to
         * guarantee only one worker is to never let a run reach the next tick.
         */
        $deadline = microtime(true) + max(1, (int) $this->option('max-seconds'));

        $processed = 0;
        $failed = 0;

        // Logged rather than printed. Cron discards stdout on this host, and
        // every symptom of "the queue is not draining" needs to be findable in
        // one place. The admin UI reads the crawl rows; this explains the rows.
        Log::info('queue:drain start', [
            'queue' => config('queue.default'),
            'max_jobs' => $maxJobs,
            'timeout' => $timeout,
        ]);

        while (true) {
            if ($maxJobs > 0 && $processed + $failed >= $maxJobs) {
                break;
            }

            if (microtime(true) >= $deadline) {
                Log::warning('queue:drain hit its wall-clock cap with work still queued', [
                    'processed' => $processed,
                    'failed' => $failed,
                ]);

                break;
            }

            $job = $this->reserve($timeout);

            if (!$job) {
                Log::info('queue:drain found the queue empty', [
                    'processed' => $processed,
                    'failed' => $failed,
                ]);

                break;
            }

            try {
                $this->process($job);
                $processed++;
            } catch (Throwable $exception) {
                $failed++;

                /*
                 * Stop on the first job that throws outside its own handling.
                 *
                 * Releasing puts the row straight back with `available_at` in
                 * the past, so the very next pop() returns the same job again.
                 * A job that always throws therefore never leaves the queue:
                 * one drain spun on it until the wall-clock cap, burning four
                 * and a half minutes of the hosting account's cron slot every
                 * minute and draining nothing. The suite caught this -- it went
                 * from 10s to 278s -- which is the argument for the drain being
                 * an artisan command at all.
                 *
                 * Bailing out is also what `queue:work` does: it releases and
                 * moves on, and a resident worker comes back to it later. Here
                 * the "later" is the next cron run, which is the correct retry
                 * cadence for a crawl anyway.
                 */
                $this->report($job, $exception);

                break;
            }
        }

        if ($processed || $failed) {
            Log::info('queue:drain finished', [
                'processed' => $processed,
                'failed' => $failed,
            ]);
        }

        // Always success. A single unreachable upstream host must not make cron
        // mail every minute; the job has already recorded its own failure. The
        // only non-zero exits here are for failing to start at all.
        return self::SUCCESS;
    }

    /**
     * Take the next job, reserving it in the database queue's own way.
     *
     * This is what `queue:work` does internally, minus the resident loop, the
     * event loop and the pcntl signal handling -- all of which are either
     * pointless in a cron run or unavailable here.
     */
    private function reserve(int $timeout): ?Job
    {
        $connection = $this->laravel['queue']->connection();

        /*
         * pop() with no argument, deliberately. Passing the queue name
         * explicitly looked like it was being precise, but the argument is a
         * *queue* name, not a connection name: `pop('database')` asked for a
         * queue called "database" and always returned null against the real
         * default of "default". That is why the first version of this drained
         * nothing and still exited 0.
         *
         * The database driver has no blocking pop, so a null return means "no
         * job right now" rather than "the queue is empty forever".
         */
        $job = $connection->pop();

        if ($job || $timeout <= 0) {
            return $job;
        }

        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            sleep(1);

            $job = $connection->pop();

            if ($job) {
                return $job;
            }
        }

        return null;
    }

    private function process(Job $job): void
    {
        // Raises the job's own exception, which is what we want: the job has
        // already written its own status and error_message before rethrowing
        // (see EthicalSourceFetcher), so this catch is for the unexpected.
        $job->fire();

        $job->delete();
    }

    /**
     * A job that blew up outside its own error handling.
     *
     * Released rather than failed, and deliberately: `fail()` would move it to
     * failed_jobs and retry_after would never see it again, but the job had no
     * chance to record why. Releasing puts it back so the next cron run retries
     * it, which is what config/queue.php's retry_after is for.
     */
    private function report(Job $job, Throwable $exception): void
    {
        Log::error('queue:drain job threw outside its own handling', [
            'job' => $job->getName(),
            'queue' => $job->getQueue(),
            'exception' => $exception->getMessage(),
        ]);

        try {
            $job->release();

            return;
        } catch (Throwable $releaseFailure) {
            Log::error('queue:drain could not release the job', [
                'job' => $job->getName(),
                'exception' => $releaseFailure->getMessage(),
            ]);
        }

        // Last resort, so one poisonous job cannot block the queue forever.
        /*
         * `$job->fail()` first, which is what actually moves the row into
         * failed_jobs. The provider is not resolved by hand because its `log()`
         * signature is ($connection, $queue, $payload, $exception) and the
         * payload is the job's *serialized body* -- logging a class name in that
         * slot writes a row nothing can retry.
         */
        try {
            $job->fail($exception);
        } catch (Throwable $failFailure) {
            Log::critical('queue:drain could not fail the job either', [
                'job' => $job->getName(),
                'exception' => $failFailure->getMessage(),
            ]);
        }
    }
}
