<?php

namespace App\Jobs;

use App\Models\DestinationSource;
use App\Services\Crawling\EthicalSourceFetcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CrawlSourceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    private const TRANSPORT_ATTEMPTS = 3;

    public function __construct(public readonly int $sourceId)
    {
    }

    public function handle(EthicalSourceFetcher $fetcher): void
    {
        $source = DestinationSource::find($this->sourceId);

        if (!$source) {
            return;
        }

        $fetcher->fetch($source, self::TRANSPORT_ATTEMPTS);
    }

    public function failed(?Throwable $exception): void
    {
        $source = DestinationSource::find($this->sourceId);

        if (!$source) {
            return;
        }

        if ($source->error_message) {
            return;
        }

        $source->update([
            'status' => 'failed',
            'error_message' => $exception?->getMessage()
                ?: 'The crawl job did not complete.',
            'last_checked_at' => now(),
        ]);
    }
}
