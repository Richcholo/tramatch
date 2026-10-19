<?php

namespace App\Services;

use App\Domain\Matches\MatchScore;
use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\User;
use Illuminate\Support\Collection;

class RecommendationService
{
    /**
     * Why a score is the number it is.
     *
     * Injected rather than resolved per row so the sentence has one
     * implementation: `recommend()` and `candidates()` both set
     * `recommendation_reason` from it, and a second copy of "30 is the floor"
     * on one page and not the other is the drift this repo keeps paying for.
     */
    public function __construct(private readonly MatchScore $matchScore)
    {
    }
    /**
     * The traveller's shortlist: the destinations they actually liked.
     *
     * Deliberately liked-only and staying that way. "Your liked places" is an
     * honest promise — it is literally the set they tapped — and widening it
     * would make the page disagree with its own heading. This is NOT the pool
     * generation draws from; see `candidates()`.
     */
    public function recommend(User $user, int $limit = 20): Collection
    {
        $profile = $user
            ->load('travelProfile.tags')
            ->travelProfile;

        if (!$profile) {
            return collect();
        }

        $weights = $profile->tags
            ->pluck('pivot.weight', 'id')
            ->map(fn ($weight) => (int) $weight);

        $totalWeight = $weights->sum();

        $swipes = DestinationSwipe::where(
            'user_id',
            $user->id
        )
            ->get()
            ->keyBy('destination_id');

        $likedIds = $swipes
            ->where('action', 'liked')
            ->keys()
            ->all();

        if (
            $totalWeight === 0 ||
            count($likedIds) === 0
        ) {
            return collect();
        }

        return Destination::query()
            ->with('tags')
            ->where('is_active', true)
            ->where('budget_level', $profile->budget_level)
            ->whereIn('id', $likedIds)
            ->get()
            ->map(function (
                Destination $destination
            ) use (
                $weights,
                $totalWeight,
                $profile
            ) {
                $this->score($destination, $weights, $totalWeight);

                // Was a fixed sentence that never mentioned the score or the
                // interests behind it — a "Why it matches" block with nothing
                // in it. The number it now explains is the number on the card.
                $destination->recommendation_reason = $this->matchScore
                    ->explain($destination, $profile)['reason'];

                return $destination;
            })
            ->filter(function (Destination $destination) {
                return $destination->matched_tags->isNotEmpty();
            })
            ->sortByDesc('match_score')
            ->values()
            ->take($limit);
    }

    /**
     * What a trip may be built from: every active destination in the area, at
     * the traveller's budget, whether or not they ever swiped it.
     *
     * THIS IS THE FIX IN ADR 0004, and it exists because the alternative was a
     * ceiling nobody could design around. Generation used to draw from
     * `recommend()`, which is liked-only, which made the reachable trip length
     * "liked destinations in one province divided by three". Benguet — the
     * densest province in a 100-row catalogue — has thirteen rows, so a
     * traveller who liked all thirteen and asked for seven days got five of
     * them. Verified, not inferred.
     *
     * Three deliberate rules, none of them "show everything":
     *
     * - **Liked first, then score.** A swipe ranks a destination; it does not
     *   decide whether the trip can exist. The traveller's own picks surface
     *   at the top, so the swipes they made are visibly rewarded rather than
     *   quietly discarded.
     * - **A passed destination is excluded.** This is the one hard exclusion,
     *   and it is the right one: "not this one" is an answer, and proposing it
     *   back in a trip would be ignoring something the traveller said
     *   deliberately. It cannot recreate the old ceiling, because a traveller
     *   has to decline a whole province to empty it — and if they have, the
     *   form says so rather than pretending otherwise.
     * - **No tag filter.** A destination sharing none of the traveller's
     *   weighted interests still belongs in a province they chose; it scores
     *   the formula's floor of 30 and sits below everything that matches. The
     *   deck may stay strict — a deck is a discovery surface, and an empty one
     *   there is a copy problem — but a trip is a decision about a place.
     */
    public function candidates(User $user, ?string $area = null, int $limit = 500): Collection
    {
        $profile = $user
            ->load('travelProfile.tags')
            ->travelProfile;

        if (!$profile) {
            return collect();
        }

        $weights = $profile->tags
            ->pluck('pivot.weight', 'id')
            ->map(fn ($weight) => (int) $weight);

        $totalWeight = $weights->sum();

        $swipes = DestinationSwipe::where(
            'user_id',
            $user->id
        );

        $passedIds = (clone $swipes)
            ->where('action', 'passed')
            ->pluck('destination_id');

        $likedIds = (clone $swipes)
            ->where('action', 'liked')
            ->pluck('destination_id')
            ->all();

        return Destination::query()
            ->with('tags')
            ->where('is_active', true)
            ->where('budget_level', $profile->budget_level)
            ->whereNotIn('id', $passedIds)
            ->when(
                $area !== null && $area !== '',
                fn ($query) => $query->where('province', $area)
            )
            ->get()
            ->map(function (Destination $destination) use ($weights, $totalWeight, $likedIds, $profile) {
                $this->score($destination, $weights, $totalWeight);

                $destination->is_liked = in_array(
                    $destination->id,
                    $likedIds,
                    true
                );

                // The generation form prints a bare "% match" beside every
                // candidate, so the floor sentence has to travel with the row:
                // a 30% with no tag chips beside it is the number with the
                // least explanation on the page.
                $destination->match_explanation = $this->matchScore
                    ->explain($destination, $profile);

                return $destination;
            })
            // Two stable passes, not one compound key. Liked first, then by
            // score inside each group.
            ->sortByDesc(fn (Destination $destination) => $destination->is_liked)
            ->sortByDesc('match_score')
            ->values()
            ->take($limit);
    }

    /**
     * Score one destination against the traveller's weighted interests.
     *
     * Shared by both public methods so the two cannot drift: `recommend()` and
     * `candidates()` reporting different numbers for the same row would make
     * the stage caption and the generation form disagree about the same place.
     *
     * `preference * 0.7 + 30` means a destination matching nothing lands on 30,
     * not on 0 — a floor rather than a null, so every candidate carries a real
     * number the generator's validity check can range over.
     */
    private function score(Destination $destination, Collection $weights, int $totalWeight): void
    {
        $matchedTags = $destination->tags->filter(
            fn ($tag) => $weights->has($tag->id)
        );

        $matchedWeight = $matchedTags->sum(
            fn ($tag) => $weights->get($tag->id, 0)
        );

        $preferenceScore = $totalWeight > 0
            ? ($matchedWeight / $totalWeight) * 100
            : 0;

        $destination->match_score = round(
            min(100, ($preferenceScore * 0.7) + 30),
            2
        );

        $destination->matched_tags = $matchedTags->values();
    }
}