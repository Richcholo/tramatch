<?php

namespace App\Console\Commands;

use App\Models\DestinationSource;
use App\Models\DestinationSourceCrawl;
use Illuminate\Console\Command;

/**
 * THE DRIFT REPORT: what changed since the last sweep.
 *
 * `sources:sweep` re-checks every source; this is how an admin reads the
 * answer. It is deliberately a report and not a crawl, so it is cheap to run
 * as often as you like and it never touches a third-party host.
 *
 * The headline is the one that matters: most sweeps find nothing, and "no
 * changes found" is a RESULT, not an absence of one. Printing only the
 * exceptions makes a clean sweep look like a broken command.
 */
class ReportSourceDrift extends Command
{
    protected $signature = 'sources:drift
        {--days=7 : Only consider crawls from the last N days}';

    protected $description = 'Report which destination sources have changed since their last crawl';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $since = now()->subDays($days);

        /*
         * The LAST crawl per source, not every crawl in the window. A source
         * crawled three times is one data point, and counting its three log
         * rows would report one changed page as three.
         */
        $latest = DestinationSourceCrawl::query()
            ->selectRaw('destination_source_id, MAX(id) as crawl_id')
            ->groupBy('destination_source_id');

        $crawls = DestinationSourceCrawl::query()
            ->joinSub($latest, 'latest', 'latest.crawl_id', '=', 'destination_source_crawls.id')
            ->where('destination_source_crawls.started_at', '>=', $since)
            ->get();

        if ($crawls->isEmpty()) {
            $this->warn(sprintf(
                'No crawls in the last %d day(s). Run `php artisan sources:sweep` first.',
                $days
            ));

            return self::SUCCESS;
        }

        $changed = $crawls->filter(fn (DestinationSourceCrawl $c) => $c->content_changed === true);
        $unchanged = $crawls->filter(fn (DestinationSourceCrawl $c) => $c->content_changed === false);
        $unknown = $crawls->filter(fn (DestinationSourceCrawl $c) => $c->content_changed === null);
        $failed = $crawls->filter(fn (DestinationSourceCrawl $c) => $c->outcome !== 'success'
            && $c->outcome !== 'unchanged');

        $this->newLine();
        $this->line(sprintf(
            '<info>Checked %s.</info> %s unchanged, %s changed.',
            $this->plural($crawls->count(), 'source', 'sources'),
            $this->plural($unchanged->count(), 'source', 'sources'),
            $this->plural($changed->count(), 'source', 'sources'),
        ));

        if ($unknown->isNotEmpty()) {
            $this->line(sprintf(
                '  %d had no previous crawl to compare against (first check).',
                $unknown->count(),
            ));
        }

        if ($failed->isNotEmpty()) {
            $this->warn(sprintf('  %d could not be read at all.', $failed->count()));
        }

        /*
         * A source whose page changed is not yet a problem: the crawler only
         * PROPOSES changes, and a proposal is pending until an admin approves
         * it. The count below is therefore "needs a human", which is the
         * number worth waking someone for.
         */
        $proposals = $changed->sum(fn (DestinationSourceCrawl $c) => $c->proposals_created);

        if ($changed->isEmpty()) {
            $this->newLine();
            $this->info('No changes found. Every checked page still matches the curated data.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->comment(sprintf(
            '%s changed; %s awaiting review at /admin/proposals.',
            $this->plural($changed->count(), 'page has', 'pages have'),
            $this->plural($proposals, 'proposal is', 'proposals are'),
        ));

        $rows = $changed->map(function (DestinationSourceCrawl $crawl) {
            $source = DestinationSource::find($crawl->destination_source_id);

            return [
                $source?->source_name ?? ('source #'.$crawl->destination_source_id),
                $crawl->outcome,
                $crawl->proposals_created,
                /*
                 * An absolute time, not `diffForHumans()`. "0 seconds ago" and
                 * "3 hours ago" are the same precision for a human reading a
                 * drift report and both are useless — the question is whether a
                 * page changed this morning or last month, and only a timestamp
                 * answers that. The column is also the widest thing in the
                 * table, so the format is deliberately short.
                 */
                $crawl->started_at?->format('j M H:i'),
            ];
        })->all();

        $this->table(['Source', 'Outcome', 'Proposals', 'Checked'], $rows);

        return self::SUCCESS;
    }

    /**
     * A count with the verb that goes with it.
     *
     * This output is read by a person deciding whether to look at the queue, so
     * "1 page(s) changed" is not a cosmetic complaint — it is the sentence that
     * has to be trusted to say there is one thing to look at.
     */
    private function plural(int $count, string $singular, string $many): string
    {
        return $count.' '.($count === 1 ? $singular : $many);
    }
}
