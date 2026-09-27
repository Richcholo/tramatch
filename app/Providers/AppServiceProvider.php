<?php

namespace App\Providers;

use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        DevCommands::artisan('queue:listen --tries=1 --timeout=300', 'queue');
    }

    public function boot(): void
    {
        //
    }
}
