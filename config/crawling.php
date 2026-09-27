<?php

return [

    'contact' => env('CRAWLER_CONTACT', 'admin@example.com'),

    'user_agent' => 'TraMatchBot/1.0 (+mailto:'.env('CRAWLER_CONTACT', 'admin@example.com').')',

    'token' => 'TraMatchBot',

    'stale_after_minutes' => 15,

    /*
    |--------------------------------------------------------------------------
    | Minimum Crawl Delay
    |--------------------------------------------------------------------------
    |
    | Floor applied to the politeness delay before any page request, even when
    | robots.txt asks for less. Set to 0 in the test environment so the suite
    | does not sleep five seconds per fetch.
    |
    */

    'min_delay_seconds' => (int) env('CRAWLER_MIN_DELAY', 5),

];
