<?php

namespace App\Domain\Matches;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\TravelProfile;

/**
 * WHY A MATCH SCORE IS THE NUMBER IT IS.
 *
 * The score is `preference * 0.7 + 30`, and for a long time it was printed as
 * a bare number: "62.3% match". Every input for the sentence already existed —
 * `RecommendationService::score()` puts `matched_tags` on the row and the
 * profile carries the weights — so the black box was the presentation, not a
 * missing calculation.
 *
 * **THE FLOOR IS THE POINT OF THIS CLASS.** Thirty of every hundred points is
 * a fixed grant, not an achievement: a place sharing none of your interests
 * scores 30, and 30/100 reads as "bad" to anybody who has not been told the
 * scale starts there. Saying "30% is the floor every place starts on" makes
 * the same number mean what it means.
 *
 * **THE SENTENCES RECONCILE WITH THE SCORE, NOT WITH AN IDEAL OF IT.** The
 * points attributed to interests here are `score - FLOOR` — read back off the
 * number the service stored — so the two can never drift into disagreeing.
 * (The service keeps computing the score; this class never recomputes it.)
 *
 * **A GUEST MUST NOT READ THE TRAVELLER'S INTERESTS.** `sentenceForAverage()`
 * names no tags, but the hero is a SHARED partial, so the average sentence is
 * rendered `@auth`-guarded: a stranger on the public share link learns that a
 * match score exists and what its scale is, and nothing about the person
 * who owns the trip.
 */
class MatchScore
{
    /**
     * The points every place starts on, whatever it shares.
     *
     * Mirrors `RecommendationService::score()`'s `+ 30`. If that formula is
     * ever retuned, this constant and that one change together — and
     * `the_floor_the_explanation_quotes_is_the_floor_the_score_uses` fails
     * until they do.
     */
    public const FLOOR = 30.0;

    /** What shared interests can move: 100 less the floor. */
    public const EARNABLE = 70.0;

    /**
     * Why one destination scored what it scored.
     *
     * @return array{
     *     score: float,
     *     earned: float,
     *     matched_weight: int,
     *     total_weight: int,
     *     tags: array<int, string>,
     *     shares_nothing: bool,
     *     reason: string
     * }
     */
    public function explain(Destination $destination, ?TravelProfile $profile): array
    {
        $score = (float) $destination->match_score;
        $weights = $this->weights($profile);
        $tags = $destination->matched_tags ?? collect();

        $tagNames = $tags
            ->map(fn ($tag) => (string) $tag->name)
            ->values()
            ->all();

        $matchedWeight = (int) $tags->sum(
            fn ($tag) => (int) ($weights[(int) $tag->id] ?? 0)
        );

        $totalWeight = (int) array_sum($weights);

        $sharesNothing = $tagNames === [];

        return [
            'score' => $score,
            // Read back off the stored score rather than recomputed from the
            // weights, so rounding can never make the sentence contradict the
            // number printed beside it.
            'earned' => round($score - self::FLOOR, 2),
            'matched_weight' => $matchedWeight,
            'total_weight' => $totalWeight,
            'tags' => $tagNames,
            'shares_nothing' => $sharesNothing,
            'reason' => $this->reasonFor($score, $tagNames, $matchedWeight, $totalWeight),
        ];
    }

    /**
     * The sentence, as one or two readable parts.
     *
     * The no-shared-interests case gets its own wording rather than a
     * sentence with an empty subject: "plus 0 of the 70, because it shares
     * none of your listed interests" explains the floor, while silence about
     * the tags would leave the lowest score on the page unexplained.
     */
    private function reasonFor(
        float $score,
        array $tagNames,
        int $matchedWeight,
        int $totalWeight
    ): string {
        if ($tagNames === []) {
            return sprintf(
                '%s%% is the floor every place starts on — this one shares none of your listed interests, so nothing was added on top of it.',
                $this->figure($score)
            );
        }

        return sprintf(
            '%s%% is the %s starting points plus %s of the %s that shared interests can move. %d of your %d interest points come from %s.',
            $this->figure($score),
            $this->figure(self::FLOOR),
            $this->figure($score - self::FLOOR),
            $this->figure(self::EARNABLE),
            $matchedWeight,
            $totalWeight,
            $this->listOf($tagNames)
        );
    }

    /**
     * What a trip's AVERAGE match means, in words.
     *
     * An average over its stops, and the stops' individual scores are NOT
     * stored — they depend on the traveller's interests as they stand now —
     * so this explains the SHAPE of the number and refuses to invent a
     * breakdown of it. "Average of 7 stops" plus the scale is the whole truth
     * available, and a fabricated per-stop breakdown is precisely the kind of
     * decoration this app has been removing elsewhere.
     */
    public function sentenceForAverage(Itinerary $itinerary): string
    {
        $stops = (int) $itinerary->trip_duration_days;

        if ($stops === 0) {
            return sprintf(
                'No stops yet, so there is no average to read. Every place starts on %s and earns up to %s more for how much of your interest weight it carries.',
                $this->figure(self::FLOOR),
                $this->figure(self::EARNABLE)
            );
        }

        return sprintf(
            'The average across %d stop(s) — every place starts on %s and earns up to %s more for how much of your interest weight it carries, and this trip averages %s.',
            $stops,
            $this->figure(self::FLOOR),
            $this->figure(self::EARNABLE),
            $this->figure((float) $itinerary->match_score)
        );
    }

    /**
     * @return array<int, int> tag id => weight
     */
    private function weights(?TravelProfile $profile): array
    {
        if (! $profile) {
            return [];
        }

        $profile->loadMissing('tags');

        return $profile->tags
            ->mapWithKeys(fn ($tag) => [(int) $tag->id => (int) $tag->pivot->weight])
            ->all();
    }

    /**
     * "Beaches", "Beaches and Hiking", "Beaches, Hiking and Culture".
     */
    private function listOf(array $names): string
    {
        if (count($names) === 1) {
            return $names[0];
        }

        $last = array_pop($names);

        return implode(', ', $names) . ' and ' . $last;
    }

    /**
     * Two decimals at most, and no trailing zeros: the score is stored
     * rounded to 2, so "32.30" beside "32.3" is noise the reader has to
     * reconcile.
     */
    private function figure(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }
}
