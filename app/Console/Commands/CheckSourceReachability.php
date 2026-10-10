<?php

namespace App\Console\Commands;

use App\Models\DestinationSource;
use App\Services\Crawling\SourceReachabilityChecker;
use Illuminate\Console\Command;

class CheckSourceReachability extends Command
{
    protected $signature = 'sources:check
                            {--id=* : Only check these source ids}
                            {--unchecked : Only check sources that have never been checked}
                            {--delay= : Seconds to wait between requests}';

    protected $description = 'Check whether each source page can actually be read, and record why it cannot';

    public function handle(SourceReachabilityChecker $checker): int
    {
        $query = DestinationSource::query()->with('destination');

        if ($ids = array_filter($this->option('id'))) {
            $query->whereIn('id', $ids);
        }

        if ($this->option('unchecked')) {
            $query->whereNull('fetchability');
        }

        $sources = $query->orderBy('id')->get();

        if ($sources->isEmpty()) {
            $this->components->warn('No sources matched.');

            return self::SUCCESS;
        }

        $delay = max(0, (int) ($this->option('delay') ?? config('crawling.min_delay_seconds', 5)));
        $lastHost = null;
        $index = 0;
        $total = $sources->count();

        foreach ($sources as $source) {
            $index++;
            $host = parse_url($source->crawlUrl(), PHP_URL_HOST) ?: '';

            if ($index > 1 && $host !== $lastHost && $delay > 0) {
                sleep($delay);
            }

            $result = $checker->check($source);
            $lastHost = $host;

            $this->components->twoColumnDetail(
                mb_substr((string) ($source->destination->name ?? $source->source_name), 0, 40),
                $result['fetchability'].' — '.$result['note']
            );

            if ($delay > 0 && $index < $total) {
                sleep($delay);
            }
        }

        $this->newLine();
        $this->components->info('Reachability summary');

        foreach (DestinationSource::fetchabilityCounts() as $state => $count) {
            $this->components->twoColumnDetail($state, (string) $count);
        }

        return self::SUCCESS;
    }
}
