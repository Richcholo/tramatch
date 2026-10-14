<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Itinerary planning
    |--------------------------------------------------------------------------
    |
    | Limits for the two calls Day 8-9 (planning) and Day 6-7 (routing) will
    | make. Nothing reads this file yet. It exists because both numbers are load-
    | bearing and neither can be picked later without measuring them, and because
    | one of them has already bitten this project once.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | HTTP timeouts
    |--------------------------------------------------------------------------
    |
    | EXPLICIT, AND THE ROUTING TIMEOUT IS DELIBERATELY SHORTER THAN THE PLANNING
    | ONE. Neither call may inherit PHP's default_socket_timeout, which on shared
    | hosting is often 60 seconds and on some hosts is 0, meaning no limit at
    | all: one unresponsive upstream then holds a worker for as long as the host
    | tolerates.
    |
    | Routing resolves a single pair of coordinates and has to answer well under
    | three seconds or the traveller is waiting on a number. `Route::optimize()`
    | fans out one call per leg and runs them concurrently, so a day of five stops
    | must not be five times this.
    |
    | Planning makes ONE Gemini request plus the routing calls the plan needs, and
    | must finish inside the queue worker's budget. See the arithmetic below.
    |
    */
    'timeouts' => [
        'routing_seconds' => (int) env('PLANNING_ROUTING_TIMEOUT', 8),

        // Under CrawlSourceJob's 300s and under retry_after by a wide margin.
        // `queue:drain` calls `$job->fire()` directly, so it does NOT enforce a
        // job's $timeout the way `queue:work` does -- which is exactly why these
        // are HTTP-level bounds and not a job property.
        'planning_seconds' => (int) env('PLANNING_TIMEOUT', 90),

        // A generation job that has already spent this long on its own retries
        // gives up and records a failure rather than sitting in the queue.
        'job_budget_seconds' => (int) env('PLANNING_JOB_BUDGET', 240),
    ],

    /*
    |--------------------------------------------------------------------------
    | Free-tier ceilings
    |--------------------------------------------------------------------------
    |
    | The guard against a demo that works on Tuesday and 429s in front of the
    | panel. ONE request per itinerary generation, with no unbounded repair loop:
    | a proposal that fails validation is either repaired from the candidate list
    | deterministically in PHP or reported, and in both cases the request count is
    | 1 or 2.
    |
    | Read these as caps, not targets. A trip the traveller opens, edits, reorders
    | and exports costs NOTHING in calls: routes are cached and the generated plan
    | is saved. Only "generate" and "generate this day again" cost.
    |
    */
    'limits' => [
        'max_candidates_per_request' => (int) env('PLANNING_MAX_CANDIDATES', 40),

        // One proposal, then at most one repair. Never a loop.
        'max_plan_attempts' => (int) env('PLANNING_MAX_ATTEMPTS', 2),

        // Seconds a traveller must wait before a second click is refused rather
        // than queued twice. A duplicate generation is the same class of bug as a
        // duplicate crawl: two plans, two sets of proposals, no error anywhere.
        'regeneration_cooldown' => (int) env('PLANNING_COOLDOWN', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route cache
    |--------------------------------------------------------------------------
    |
    | Road distances go stale slowly and the failure mode of a stale leg is a
    | slightly wrong minute count, not a wrong trip — so legs are cached for a
    | month, not an hour. The driver is whatever the app runs (database in
    | production, array under test), which is why routing needs no table: a
    | dedicated route table would be a second source of truth for data that is
    | legitimately tossable.
    |
    */
    'routing' => [
        'cache_days' => (int) env('ROUTING_CACHE_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | What the model is allowed to decide
    |--------------------------------------------------------------------------
    |
    | The split the whole feature rests on, written down as data so the client
    | cannot drift from the claim the demo makes.
    |
    | THE MODEL CHOOSES AND EXPLAINS. It picks from the candidate ids it was
    | given, assigns them to days, orders them, and says why.
    |
    | LARAVEL DECIDES AND CHECKS. Coordinates, prices, opening hours, durations,
    | distances and times come from the database or the routing provider and are
    | never read out of the model's reply. Every id it returns is verified to
    | exist; a proposal whose schedule cannot be laid out is repaired here or
    | reported.
    |
    | Concretely: an unknown candidate id, a price Gemini invented, a leg with no
    | route, and a day that runs past 18:00 are all decided on this side of the
    | wire. "Only use our sources" in a prompt is not enforcement -- an id check
    | and a server-side overwrite are.
    |
    */
    'authority' => [
        'model_decides' => ['selection', 'day_assignment', 'order', 'reason'],

        'server_decides' => [
            'coordinates',
            'price',
            'opening_hours',
            'duration',
            'distance',
            'travel_time',
            'feasibility',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | The one transport mode this build supports
    |--------------------------------------------------------------------------
    |
    | DRIVING, AND SAYING SO. A routing provider's driving profile is the only
    | one whose numbers mean anything for a self-drive trip, and it is the only
    | one this build will request. A driving route is NOT a jeepney, a bus or a
    | boat journey: those are slower, cost money, and change by the hour, and
    | presenting a driving estimate as one of those is a wrong number presented
    | confidently.
    |
    | The travel time stays an estimate even with a provider. Traffic, the
    | departure clock, transfers, road works and local conditions are not in it.
    | Say "about", show the distance as a distance, and never imply precision the
    | provider does not have.
    |
    */
    'transport' => [
        'profile' => 'driving',

        'label' => 'Driving',
    ],

];