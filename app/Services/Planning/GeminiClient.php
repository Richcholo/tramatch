<?php

namespace App\Services\Planning;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
 *
 * - **EVERY FAILURE LOGS THE STATUS AND THE PROVIDER'S OWN WORDS**, and that
 *   is not tidiness. `upstream` is one kind covering three unrelated causes —
 *   a retired model (404), a rejected key (403) and the provider having a bad
 *   day (5xx) — and the traveller's message deliberately names none of them.
 *   The first live assist failure was a retired model whose 404 the code threw
 *   away, so the only way to tell it from a dead host was to hand-run a cURL
 *   probe over SSH. The log line is what makes the next one a log read.
 *   It carries the status, the provider's `message`, the model that was asked
 *   for and the attempt's own elapsed time, and **never the key**.
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
            Log::warning('planning.gemini_unreachable', [
                'model' => (string) config('services.gemini.model'),
                'reason' => $e->getMessage(),
            ]);

            throw GeminiError::of('unreachable', $e);
        }

        if ($response->status() === 429) {
            $this->logRefusal('throttled', $response);

            throw GeminiError::of('throttled');
        }

        if ($response->failed()) {
            $this->logRefusal('upstream', $response);

            throw GeminiError::of('upstream');
        }

        $decoded = json_decode((string) $response->json('candidates.0.content.parts.0.text'), true);

        if (! is_array($decoded)) {
            Log::warning('planning.gemini_unparseable', [
                'model' => (string) config('services.gemini.model'),
                'status' => $response->status(),
                'body_start' => mb_substr((string) $response->body(), 0, 200),
            ]);

            throw GeminiError::of('unparseable');
        }

        return $decoded;
    }

    /**
     * A NON-2XX ANSWER, RECORDED WITH THE ONE FIELD THAT NAMES THE CAUSE.
     *
     * The provider's `error.message` is the whole value of this line: it is
     * what said "This model models/gemini-2.5-flash is no longer available to
     * new users. Please update your code to use models/gemini-3.8-flash" while
     * the app was telling the traveller the service was not answering. The
     * status alone would have said 404 and left the guess intact.
     *
     * The key is never passed here — it is not in the response, and it must
     * not be added to the context for "completeness".
     */
    private function logRefusal(string $kind, $response): void
    {
        $message = $response->json('error.message');

        Log::warning('planning.gemini_'.$kind, [
            'status' => $response->status(),
            'model' => (string) config('services.gemini.model'),
            // The provider's own words, trimmed to a line: enough to name a
            // retired model or a refused key, without dumping a whole body.
            'provider_message' => is_string($message) ? mb_substr($message, 0, 300) : null,
        ]);
    }
}
