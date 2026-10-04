<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use RuntimeException;

/**
 * A job that always throws, for testing what queue:drain does with one.
 *
 * A named class rather than an anonymous one because the database queue
 * serialises the payload: an anonymous class is not serialisable, and
 * Queue::push() rejects it outright.
 */
class AlwaysThrowsJob implements ShouldQueue
{
    use Dispatchable;

    public int $tries = 1;

    public function handle(): void
    {
        throw new RuntimeException('upstream refused the connection');
    }
}
