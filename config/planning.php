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

        // THE ASSIST RUNS INSIDE A WEB REQUEST, not a queue job, so its HTTP
        // timeout is the traveller's patience and not a worker's budget. A
        // hang of 90s in a POST the traveller actively clicked is a frozen
        // page; 20s is already the outer edge of "still there".
        'assist_seconds' => (int) env('PLANNING_ASSIST_TIMEOUT', 20),

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
    | panel. ONE request per assist, no repair loop and no retry -- a reply
    | that fails validation is reported, not re-asked.
    |
    | Read these as caps, not targets. A trip the traveller opens, edits,
    | reorders and exports costs NOTHING in calls: routes are cached and the
    | generated plan is saved. Only the discovery assist's nudge costs one.
    |
    */
    'limits' => [
        // The candidate list sent to the model in one request. Candidates are
        // drawn from the deck the traveller is already being dealt, so this
        // cap bounds the prompt, not the pool.
        'max_candidates_per_request' => (int) env('PLANNING_MAX_CANDIDATES', 40),

        // How many picks the model is ASKED for. The reply is capped to this
        // again after parsing -- a model that returns nine is trusted for
        // nothing.
        'assist_choices' => (int) env('PLANNING_ASSIST_CHOICES', 3),

        // ONE request per assist, written as data so the claim the demo makes
        // is the same claim the HTTP-level test enforces: it reads this value
        // as the expected request count and fails if the client ever makes
        // more than it. There is no repair machinery left to cap -- a reply
        // that fails validation is reported, not re-asked.
        'assist_attempts' => (int) env('PLANNING_ASSIST_ATTEMPTS', 1),

        // Seconds after one assist before the next is accepted. The free tier
        // is a handful of requests a MINUTE; a double click must not become a
        // second call, and this is the honest "you just asked" state rather
        // than a silent queue-twice.
        'assist_cooldown' => (int) env('PLANNING_ASSIST_COOLDOWN', 45),
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
    | THE MODEL CHOOSES AND EXPLAINS. Its whole job in this build is discovery:
    | given a candidate list that has already been filtered to the traveller's
    | tier, it selects the places most worth looking at next and says why.
    |
    | LARAVEL DECIDES AND CHECKS. Membership of the catalogue, the tier, the
    | region and the already-swiped set are all decided BEFORE the prompt, and
    | every id the model returns is verified against that list again after the
    | reply -- an invented id is dropped by the server, never saved or shown.
    | Prices, coordinates, hours, distances and times are read back out of the
    | database when anything is finally displayed. "Only use our sources" in a
    | prompt is not enforcement -- the id check and the server-side readback
    | are.
    |
    */
    'authority' => [
        'model_decides' => ['selection', 'explanation'],

        'server_decides' => [
            'membership',
            'coordinates',
            'price',
            'opening_hours',
            'distance',
            'travel_time',
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