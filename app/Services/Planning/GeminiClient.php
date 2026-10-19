<?php

namespace App\Services\Planning;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * THE GEMINI TRANSPORT, AND ONLY THE TRANSPORT.
 *
 * It sends one prompt and parses one reply. It knows nothing about
 * destinations, travellers or honesty: the caller (DiscoveryAssist) builds the
 * prompt and validates everything that comes back.
 *
 * - **KEY AND MODEL ARE CONFIGURED, NEVER CONSTANTS.** They arrive from
 *   `services.gemini.*` env, so a model retirement is an `.env` change, not a
 *   deploy of the class.
 * - **NO RETRIES, BY DECISION.** A free tier is a budget, not a suggestion:
 *   one request per assist, and every failure becomes one named kind on the
 *   way out. A retry loop against a 429 is a bot hammering its own quota.
 * - **SERVER-SIDE ONLY.** The key travels in a request header and never
 *   reaches a view, a Vite entry or a data attribute,
 *   `PlacesCatalogueTest::test_the_gemini_key_never_reaches_a_rendered_page`
 *   holds the half of this that belongs to the rendered page.
 * - The error kinds and their meanings are on GeminiError.
 */
class GeminiClient
{
    public function ask(string $prompt): array
    {
        $key = trim((string) config('services.gemini.key'));

        if ($key === '') {
            throw GeminiError::of('unconfigured');
        }

        try {
            $response = Http::baseUrl((string) config('services.gemini.base_url'))
                ->timeout((int) config('planning.timeouts.assist_seconds'))
                ->withHeaders([
                    'x-goog-api-key' => $key,
                    'User-Agent' => 'TraMatch/1.0 (+mailto:'.env('CRAWLER_CONTACT', 'admin@example.com').')',
                ])
                ->post((string) config('services.gemini.model').':generateContent', [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [['text' => $prompt]],
                        ],
                    ],
                    'generationConfig' => [
                        // Structured output is the contract: the reply text must
                        // BE the JSON the prompt asked for, not prose around it.
                        'responseMimeType' => 'application/json',
                        'temperature' => 0.4,
                        'maxOutputTokens' => 2048,
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw GeminiError::of('unreachable', $e);
        }

        if ($response->status() === 429) {
            throw GeminiError::of('throttled');
        }

        if ($response->failed()) {
            throw GeminiError::of('upstream');
        }

        $decoded = json_decode((string) $response->json('candidates.0.content.parts.0.text'), true);

        if (! is_array($decoded)) {
            throw GeminiError::of('unparseable');
        }

        return $decoded;
    }
}
