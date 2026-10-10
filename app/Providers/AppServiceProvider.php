<?php

namespace App\Providers;

use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The stock queue:listen has no per-job timeout, so a crawl that hangs
        // on an unresponsive host blocks the worker indefinitely. config/queue.php
        // derives retry_after from the same QUEUE_WORKER_TIMEOUT, which has to
        // stay above this number or a slow crawl gets picked up twice.
        DevCommands::artisan(
            sprintf('queue:listen --tries=1 --timeout=%d', (int) env('QUEUE_WORKER_TIMEOUT', 300)),
            'queue'
        );
    }

    public function boot(): void
    {
        //
    }
}
