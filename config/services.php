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
    | BOTH CLIENTS ARE LIVE. `GeminiClient` reads `gemini.*` and asks the model
    | for structured output; `OsrmClient` reads `routing.*` and measures real
    | road legs. `config/planning.php` holds the timeouts and the free-tier
    | ceilings they share.
    |
    | `gemini.model` is a DEFAULT, not a pin. Google renames models and retires
    | them, so a hard-coded model id in a PHP array is one more thing to rot. The
    | value here is structurally safe for any current model, because the client
    | only asks for structured output, which every current model supports -- it
    | is not a claim that this is the best model.
    |
    | BOTH KEYS ARE SERVER-SIDE ONLY AND MUST NEVER REACH BLADE. A Gemini key in
    | shipped JavaScript is a public key: it can be lifted, spent against the
    | quota, and billed. Neither appears in a `@vite` entry, a `<meta>` tag or a
    | data attribute, and no endpoint in this app returns config.
    |
    */
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),

        // NOT OPTIONAL, AND FOR THE SAME REASON AS THE ROUTING USER-AGENT
        // BELOW. Guzzle 7 sets no User-Agent, and an anonymous request is the
        // behaviour that gets a service rate-limiting or refusing you. It
        // names a real mailbox for the same reason: someone who wants the
        // traffic stopped has to be able to write to somebody.
        //
        // It MUST be read through config and never with `env()` in the client.
        // `env()` outside a config file returns the default under
        // `config:cache`, which the deployment runs, so a direct call ships
        // the placeholder address on the one environment where it matters.
        'user_agent' => env(
            'GEMINI_USER_AGENT',
            'TraMatch/1.0 (+mailto:support@tramatch.site)'
        ),

        // A DEFAULT THAT IS ACTUALLY CALLABLE, which is not a detail.
        //
        // This read 'gemini-2.5-flash' and shipped, and the first live assist
        // failure was a 404: "This model models/gemini-2.5-flash is no longer
        // available to new users." Google retires models; a default pointing
        // at a retired one fails for every deployment that never set
        // GEMINI_MODEL, with a provider message the traveller never sees.
        //
        // It is still a DEFAULT and not a pin — a retirement is an `.env`
        // change, never a code change — but a default has to name a model that
        // answers today, or "you left it blank" becomes indistinguishable from
        // "the service is down". `gemini-3.8-flash` is the model Google's own
        // 404 named as the replacement, measured callable with a current key.
        'model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
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
        // It names a real mailbox for the reason every identifiable client
        // does: an anonymous bot hitting somebody's free demo server is the
        // behaviour that gets demo servers switched off for everyone.
        'user_agent' => env('ROUTING_USER_AGENT', 'TraMatch/1.0 (+mailto:support@tramatch.site)'),
    ],

];
