<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Itinerary planning
    |--------------------------------------------------------------------------
    |
    | Limits for the calls the planner and the router make. Both numbers are
    | load-bearing and neither can be picked later without measuring them,
    | and one of them has already bitten this project once.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | HTTP timeouts
    |--------------------------------------------------------------------------
    |
    | EXPLICIT, AND THE ROUTING TIMEOUT IS DELIBERATELY SHORTER THAN THE
    | PLANNING ONE. Neither call may inherit PHP's default_socket_timeout,
    | which on shared hosting is often 60 seconds and on some hosts is 0,
    | meaning no limit at all: one unresponsive upstream then holds the
    | request for as long as the host tolerates.
    |
    | Routing resolves a single pair of coordinates and has to answer well
    | under three seconds or the traveller is waiting on a number. Legs are
    | fetched concurrently, so a day of five stops is not five times this.
    |
    */
    'timeouts' => [
        'routing_seconds' => (int) env('PLANNING_ROUTING_TIMEOUT', 8),

        // THE ITINERARY SUGGEST RUNS INSIDE A WEB REQUEST, not a queue job,
        // so its HTTP timeout is the traveller's patience and not a worker's
        // budget. A hang of 90s in a POST the traveller actively clicked is
        // a frozen page; 20s is already the outer edge of "still there".
        'assist_seconds' => (int) env('PLANNING_ASSIST_TIMEOUT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Free-tier ceilings
    |--------------------------------------------------------------------------
    |
    | The guard against a demo that works on Tuesday and 429s in front of the
    | panel. ONE request per itinerary suggest, no repair loop and no retry --
    | a reply that fails validation is reported, not re-asked.
    |
    | Read these as caps, not targets. A trip the traveller opens, edits,
    | reorders and exports costs nothing in calls beyond this: routes are
    | cached and the generated plan is saved.
    |
    */
    'limits' => [
        // THE OUTPUT BUDGET, AND WHY IT IS NOT A SMALL NUMBER.
        //
        // These models are THINKING models: they emit reasoning tokens before
        // the answer, and `thoughtsTokenCount` is billed against this SAME
        // budget. Measured on a live 3-pick reply: 577 thoughts against 112
        // answer tokens. A budget sized to the answer alone therefore truncates
        // the JSON mid-string, and the reply is thrown away as malformed when
        // it was in the right shape and simply ran out of room.
        //
        // 8192 covers a full candidate list with room for the thoughts. It is
        // a ceiling, not a spend -- a model that answers in 700 tokens is
        // billed for 700. The client reads `finishReason` and names a
        // truncation separately from a bad shape, so this number being wrong
        // is now diagnosable rather than invisible.
        'assist_output_tokens' => (int) env('PLANNING_ASSIST_OUTPUT_TOKENS', 8192),
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
    | THE MODEL CHOOSES AND EXPLAINS. Its whole job in this build is picking
    | restaurants and hotels: given the food and lodging rows in the trip's own
    | province, it selects the ones most worth adding and says why, in one
    | sentence each.
    |
    | LARAVEL DECIDES AND CHECKS. Membership of the catalogue and the trip's
    | province are decided BEFORE the prompt, and every id the model returns is
    | verified against that list again after the reply -- an invented id is
    | dropped by the server, never saved or shown. Prices, coordinates, hours,
    | distances and times are read back out of the database when anything is
    | finally displayed. "Only use our sources" in a prompt is not enforcement
    | -- the id check and the server-side readback are.
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