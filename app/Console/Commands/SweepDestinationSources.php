<?php

namespace App\Console\Commands;

use App\Jobs\CrawlSourceJob;
use App\Models\DestinationSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

/**
 * THE ROUTINE SWEEP: re-check every source and report what moved.
 *
 * This is the counterpart to `sources:check`, which measures whether a page can
 * be READ. This one asks whether the data still agrees with what we curated,
 * which is the only reason to re-crawl on a schedule at all.
 *
 * It queues jobs rather than fetching inline, on purpose. Every crawl sleeps at
 * least `crawling.min_delay_seconds` (5 s) and makes two requests, so 65
 * sources is several minutes of wall clock -- far too long for one process on
 * shared hosting, where the CLI is also the thing `queue:drain` runs from
 * cron. Dispatching lets the ordinary worker do the fetching and keeps this
 * command's runtime to a query and a loop.
 *
 * NOTE `destination_sources` HAS NO `is_active` COLUMN. Publishing on a SOURCE
 * is its `status`; `is_active` lives on the DESTINATION. The first version of
 * this command filtered on a column that does not exist, so it queued nothing
 * and still reported success -- a sweep that silently sweeps nothing is worse
 * than no sweep, because it looks like one.
 */
class SweepDestinationSources extends Command
{
    protected $signature = 'sources:sweep
        {--all : Include sources already marked failed or blocked}';

    protected $description = 'Queue a re-crawl of every destination source, to check for drift';

    public function handle(): int
    {
        $query = DestinationSource::query();

        if (! $this->option('all')) {
            /*
             * A source that cannot be read stays unreadable until someone
             * changes its URL, and re-asking it on every sweep is how a sweep
             * turns into a slow way of rediscovering the same 10 bot walls. It
             * stays listed and stays visible on the dashboard; `--all` covers
             * the case where you want to prove it is still refusing.
             */
            $query->whereNotIn('status', ['failed', 'blocked']);
        }

        $sources = $query->orderBy('id')->get();

        if ($sources->isEmpty()) {
            $this->warn('No sources to sweep.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Sweeping %d source(s)…', $sources->count()));

        $queued = 0;
        $skipped = 0;

        foreach ($sources as $source) {
            if ($source->hasCrawlInFlight()) {
                $skipped++;

                continue;
            }

            $source->update([
                'status' => 'queued',
                'last_checked_at' => now(),
                'error_message' => null,
            ]);

            CrawlSourceJob::dispatch($source->id);
            $queued++;
        }

        $this->info(sprintf('Queued %d, skipped %d already in flight.', $queued, $skipped));

        if ($queued > 0) {
            $this->line(sprintf(
                'Waiting on %d job(s) in the queue. %s',
                Queue::size(),
                'Report drift afterwards with `php artisan sources:drift`.'
            ));
        }

        return self::SUCCESS;
    }
}
