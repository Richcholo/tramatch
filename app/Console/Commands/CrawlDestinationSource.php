<?php

namespace App\Console\Commands;

use App\Models\DestinationSource;
use App\Services\Crawling\EthicalSourceFetcher;
use Illuminate\Console\Command;
use Throwable;

class CrawlDestinationSource extends Command
{
    protected $signature = 'sources:crawl {sourceId : Destination source ID}';

    protected $description = 'Fetch one approved destination source ethically';

    public function handle(EthicalSourceFetcher $fetcher): int
    {
        $source = DestinationSource::find($this->argument('sourceId'));

        if (!$source) {
            $this->error('Destination source not found.');
            return self::FAILURE;
        }

        try {
            $snapshot = $fetcher->fetch($source, 3);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->info(
            'Source fetched. Snapshot ID: ' . $snapshot->id
        );

        $proposalCount = $source
            ->proposals()
            ->where('status', 'pending')
            ->count();

        $this->info(
            'Pending proposals: ' . $proposalCount
        );

        return self::SUCCESS;
    }
}
