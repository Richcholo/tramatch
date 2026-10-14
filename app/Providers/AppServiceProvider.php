<?php

namespace App\Providers;

use App\Services\Routing\OsrmClient;
use App\Services\Routing\RouteService;
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

        // The routing client carries scalar config (base URL, profile, timeout)
        // the container cannot infer, so it is built explicitly. Singleton,
        // because it holds no request state and every leg in a save should
        // share the one client — and the one cache behind it.
        $this->app->singleton(OsrmClient::class, fn () => OsrmClient::fromConfig());
        $this->app->singleton(RouteService::class, fn ($app) => new RouteService(
            $app->make(OsrmClient::class)
        ));
    }

    public function boot(): void
    {
        //
    }
}
