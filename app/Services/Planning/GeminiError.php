<?php

namespace App\Services\Planning;

use RuntimeException;

/**
 * THE ONE WAY A GEMINI CALL FAILS, NAMED.
 *
 * Every failure below is a different truth about the world, and the honest
 * states on the deck depend on telling them apart: a 429 is the free tier
 * being a free tier and the traveller has to wait; a 5xx or a dead wire is the
 * provider having a bad day; a reply that is not the JSON shape we asked for
 * is its own fact and must not burn a retry; and a missing key is a
 * deployment issue, not something a traveller can fix by clicking again.
 *
 * NOTHING HERE IS A RETRY. One request per assist, by decision — see
 * DiscoveryAssist — so an exception reaching it ends the attempt.
 */
class GeminiError extends RuntimeException
{
    public function __construct(private readonly string $kind, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function of(string $kind, ?\Throwable $previous = null): self
    {
        return new self($kind, self::messageFor($kind), $previous);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    private static function messageFor(string $kind): string
    {
        return match ($kind) {
            'unconfigured' => 'GEMINI_API_KEY is not set, so no model call is possible.',
            'unreachable' => 'The Gemini endpoint could not be reached at all.',
            'throttled' => 'The Gemini free tier refused the request with 429.',
            'upstream' => 'The Gemini endpoint answered with a 4xx/5xx failure.',
            'unparseable' => 'The reply did not carry the JSON shape the prompt asked for.',
            default => 'An unnamed Gemini failure.',
        };
    }
}
