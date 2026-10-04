<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// TestMail is picked up by auto-discovery from app/Console/Commands, which
// Laravel registers by default. No entry needed here.
