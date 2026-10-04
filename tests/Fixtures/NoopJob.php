<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * A job that does nothing.
 *
 * Most of these tests are about queue:drain's own behaviour -- does it pop, does
 * it delete on success, does it release on failure -- and none of that depends
 * on what the job does. An earlier version dispatched the real CrawlSourceJob,
 * which fired the fetcher and made two outbound HTTP requests per test, so the
 * file took 38 seconds and asserted on a network round trip it never cared
 * about.
 *
 * A named class, because the database queue serialises the payload and an
 * anonymous class cannot be serialised.
 */
class NoopJob implements ShouldQueue
{
    use Dispatchable;

    public function handle(): void
    {
        // Nothing. The assertion is on the jobs table afterwards.
    }
}
