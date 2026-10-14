<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Planning: Gemini and the routing provider
    |--------------------------------------------------------------------------
    |
    | NOTHING IS CALLED YET. Day 1 added the schema these keys will feed and no
    | HTTP client, no job and no route reads them. They are here so the shape of
    | the credential is settled before the code that needs it is written, and so
    | nobody has to add a key to three env files later.
    |
    | `gemini.model` is a DEFAULT, not a pin. Google renames models and retires
    | them, so a hard-coded model id in a PHP array is one more thing to rot. The
    | value here is structurally safe for any current model, because the client
    | (Day 8-9) will only ask for structured output, which every current model
    | supports -- it is not a claim that this is the best model.
    |
    | BOTH KEYS ARE SERVER-SIDE ONLY AND MUST NEVER REACH BLADE. A Gemini key in
    | shipped JavaScript is a public key: it can be lifted, spent against the
    | quota, and billed. Neither appears in a `@vite` entry, a `<meta>` tag or a
    | data attribute, and no endpoint in this app returns config.
    |
    | SEE config/planning.php for the timeouts and the free-tier ceilings.
    |
    */
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'base_url' => env(
            'GEMINI_BASE_URL',
            'https://generativelanguage.googleapis.com/v1beta/models'
        ),
    ],

    'routing' => [
        // OpenRouteService needs a key nobody has issued yet; OSRM answers
        // the whole country with none. The default is the endpoint that works
        // today, and ROUTING_PROVIDER + ROUTING_BASE_URL switch it without a
        // deploy when a key (or a self-hosted OSRM) exists.
        'key' => env('ROUTING_API_KEY'),
        'base_url' => env('ROUTING_BASE_URL', 'https://router.project-osrm.org'),
        'provider' => env('ROUTING_PROVIDER', 'osrm'),

        // NOT OPTIONAL, DESPITE BEING INVISIBLE WHEN IT WORKS. The public
        // demo server answers 403 to a request carrying no User-Agent, and
        // Guzzle 7 — which is what Laravel's Http client is — sets none by
        // default. So this one header is the difference between real distances
        // and a silent fallback to the flat estimate on every host, measured
        // on 2026-10-15: no UA at all is 403, and any UA at all is 200.
        //
        // It names a real mailbox for the same reason the crawler's token
        // does: an anonymous bot hitting somebody's free demo server is the
        // behaviour that gets demo servers switched off for everyone.
        'user_agent' => env('ROUTING_USER_AGENT', 'TraMatch/1.0 (+mailto:support@tramatch.site)'),
    ],

];
