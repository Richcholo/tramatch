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

                $this->report($job, $exception);

                /*
                 * MOVE ON RATHER THAN BREAKING OUT, and this is the fix for
                 * "it only ever crawls the same one".
                 *
                 * A crawl that cannot reach its host throws -- a Cloudflare
                 * challenge, a bot wall, a dead LGU domain. The drain used to
                 * release that job and stop, on the reasoning that releasing
                 * with no delay hands the same row straight back to the very
                 * next `pop()`. Both halves of that are true and together they
                 * deadlock the queue:
                 *
                 *   - `DatabaseQueue::getNextAvailableJob()` orders by `id
                 *     asc`, so the LOWEST job id wins every pop; and
                 *   - `DatabaseJob::release($delay = 0)` writes `available_at
                 *     = now()`, so a released job is eligible again at once.
                 *
                 * So the released job went straight back to the head of the
                 * queue, and the drain broke before reaching job #2. Every cron
                 * tick then re-popped the same job, threw on it again, released
                 * it, and stopped -- which is why one unreachable host pinned
                 * the crawl queue at 1 of 65 indefinitely, with the other 64
                 * jobs never being attempted at all.
                 *
                 * `CrawlSourceJob` also declares `$tries = 1`, which is
                 * enforced by `queue:work` but was NEVER enforced here: this
                 * command calls `$job->fire()` directly, bypassing the worker's
                 * attempt tracking entirely. So a job with one try was being
                 * retried forever. `report()` now honours `maxTries()` with the
                 * same `<=` comparison the worker uses, and releases with a
                 * BACKOFF so the job goes to the back of the queue instead of
                 * the front of it.
                 */
                continue;
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
     * A job that blew up: retire it, and never put it back.
     *
     * IT IS NOT RELEASED, AND THAT IS THE FIX.
     *
     * The command used to `release()` a failing job and stop. Releasing looked
     * harmless and was the source of "the crawler only crawls Camp John Hay":
     *
     *  - `DatabaseQueue::deleteAndRelease()` DELETES the row and INSERTS A NEW
     *    ONE, and the new row is written with `attempts => 0`. So the attempt
     *    counter is reset on every release, which means **`$tries` can never be
     *    reached by releasing** -- a job declared with one try would be
     *    released after its first failure and then read `attempts() == 1` again
     *    forever. `queue:work` does not have this problem because it counts
     *    attempts itself; this command calls `$job->fire()` directly, so it
     *    never counted anything at all.
     *  - With `release()` and no argument the new row is eligible at once, so
     *    the next cron tick pops it again and the loop is invisible.
     *  - And the drain `break`ed on the throw, so the jobs behind it were
     *    never reached in that run either.
     *
     * A job-level retry adds nothing here anyway: `EthicalSourceFetcher` already
     * makes its own transport attempts (3 from here, 2 from the admin button)
     * and persists the failure before rethrowing. Re-running the whole job is
     * not a retry, it is the same request again. An admin re-crawls from the UI
     * when they mean to.
     *
     * SO: fail it and carry on with the next job. One unreachable host stops
     * costing anything beyond its single attempt.
     */
    private function report(Job $job, Throwable $exception): void
    {
        Log::error('queue:drain job failed; retiring it and continuing', [
            'job' => $job->getName(),
            'queue' => $job->getQueue(),
            'attempts' => $job->attempts(),
            'max_tries' => $job->maxTries(),
            'exception' => $exception->getMessage(),
        ]);

        $this->retire($job, $exception);
    }

    /**
     * Retire the job: run its own `failed()` hook and take it off the queue.
     *
     * `$job->fail()` does both — it resolves the handler and calls the job
     * class's `failed()` method (which is what writes the source's own status
     * and error message), and then deletes the queue row. **Deleting the row
     * is the part that matters here**, because a job left on the queue is
     * exactly the loop this replaces.
     *
     * It does NOT write a `failed_jobs` row in this application:
     * `FailedJobProviderInterface` is not bound in the container, so
     * `CallQueuedHandler::failed()`'s `failedJobProvider?->log(...)` silently
     * does nothing. Measured, not assumed — a probe called `fail()` on a real
     * popped job and `failed_jobs` stayed empty while `jobs` went to zero.
     *
     * So do not write a test asserting a `failed_jobs` row here. Assert that
     * the job left the `jobs` table, which is the behaviour that matters. The
     * job's own `failed()` hook owns whatever record of the failure its
     * subsystem needs.
     */
    private function retire(Job $job, Throwable $exception): void
    {
        try {
            $job->fail($exception);

            return;
        } catch (Throwable $failFailure) {
            Log::critical('queue:drain could not fail the job', [
                'job' => $job->getName(),
                'exception' => $failFailure->getMessage(),
            ]);
        }

        // Last resort: take the row out by hand, so one poisonous job cannot
        // block the queue even if its own failure handling is what is broken.
        try {
            $job->delete();
        } catch (Throwable $deleteFailure) {
            Log::critical('queue:drain could not remove the job either', [
                'job' => $job->getName(),
                'exception' => $deleteFailure->getMessage(),
            ]);
        }
    }
}
