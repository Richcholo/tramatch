<?php

namespace App\Services;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\User;
use Illuminate\Support\Collection;

class SwipeDeckService
{
    public function cards(User $user, int $limit = 20): Collection
    {
        $resolved = $this->penalisedWeights($user);

        if ($resolved === null) {
            return collect();
        }

        $profile = $resolved['profile'];
        $weights = $resolved['weights'];

        $totalWeight = $weights->sum();

        $swipedIds = DestinationSwipe::where(
            'user_id',
            $user->id
        )->pluck('destination_id');

        $destinations = Destination::query()
            ->with('tags')
            ->where('is_active', true)
            ->where('budget_level', $profile->budget_level)
            ->whereNotIn('id', $swipedIds)
            ->when($profile->preferred_region, function ($query) use ($profile) {
                $region = $profile->preferred_region;

                $query->where(function ($query) use ($region) {
                    $query
                        ->where('province', 'like', "%{$region}%")
                        ->orWhere('municipality', 'like', "%{$region}%");
                });
            })
            ->get();

        return $destinations
            ->map(function (Destination $destination) use (
                $weights,
                $totalWeight
            ) {
                $matchedTags = $destination->tags->filter(
                    fn ($tag) => $weights->get($tag->id, 0) > 0
                );

                $matchedWeight = $matchedTags->sum(
                    fn ($tag) => $weights->get($tag->id, 0)
                );

                $destination->preference_score = $totalWeight > 0
                    ? round(($matchedWeight / $totalWeight) * 100, 2)
                    : 0;

                $destination->matched_tags = $matchedTags->values();

                return $destination;
            })
            ->filter(function (Destination $destination) {
                return $destination->matched_tags->isNotEmpty()
                    && $destination->preference_score > 0;
            })
            ->sortByDesc('preference_score')
            ->values()
            ->take($limit);
    }

    /**
     * THE PROFILE'S INTEREST WEIGHTS, LESS WHAT THE TRAVELLER HAS SAID NO TO.
     *
     * Shape: `[profile, weights]` or null when the traveller has no profile —
     * null, NOT an empty collection, because "no profile" and "profile with
     * no weights" are different empty decks with different messages, and
     * both `cards()` and `emptyState()` have to tell them apart.
     *
     * A PASS IS READ BACK HERE, FOR THE FIRST TIME. `destination_swipes`
     * rows were written and never read anywhere: a pass cost the traveller
     * the card and nothing else, so "no" and "decide later" could sit in the
     * table indistinguishable. The penalty reads the passed rows back —
     * every pass on a place carrying a tag subtracts ONE step from that
     * tag's weight, floored at zero.
     *
     * Why one step: weights are 1..3, so a single pass against a
     * weight-1 interest erases it. That is the rule doing its job — the
     * weakest interest dying after one "no" — not a bug to soften while
     * "making it friendlier".
     *
     * A SKIP IS NEVER READ HERE. `skipped` rows carry no opinion; this
     * method deliberately does not touch them, because the whole point of
     * the action is that "decide later" leaves no trace in scoring while
     * still keeping the card out of the deck, and the moment it starts
     * penalising, "no opinion" and "no" are the same again.
     */
    private function penalisedWeights(User $user): ?array
    {
        $profile = $user
            ->load('travelProfile.tags')
            ->travelProfile;

        if (!$profile) {
            return null;
        }

        $penalties = DestinationSwipe::query()
            ->where('destination_swipes.user_id', $user->id)
            ->where('destination_swipes.action', 'passed')
            ->join('destination_tag', 'destination_tag.destination_id', '=', 'destination_swipes.destination_id')
            ->selectRaw('destination_tag.tag_id as tag_id, count(*) as passes')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->tag_id => (int) $row->passes]);

        $weights = $profile->tags
            ->pluck('pivot.weight', 'id')
            ->map(fn ($weight) => (int) $weight)
            ->mapWithKeys(fn ($weight, $tagId) => [$tagId => max(0, $weight - ($penalties[$tagId] ?? 0))]);

        return ['profile' => $profile, 'weights' => $weights];
    }

    /**
     * WHY THE DECK IS EMPTY, ATTRIBUTED TO THE LAYER THAT EMPTIED IT.
     *
     * The deck filters four times — budget tier, region, shared interests,
     * already swiped — and "You are all caught up" was the one sentence for
     * every outcome, including for a traveller who had swiped NOTHING. That
     * was a lie with accompaniment: *nothing matched your interests* tells a
     * curator where the gaps are, *your region matched nothing* is a resolvable
     * mistake, and *you swiped everything* is a working state.
     *
     * The layers are attributed in the order `cards()` applies them, so the
     * message names the FIRST filter that emptied the pool: a region that
     * excluded everything reports the region, not "no interests", because the
     * interests never got the chance to speak. Counts are returned with the
     * reason so the view can print its own numbers, and `cards` is returned
     * when the deck is not actually empty — an honest fallback the view never
     * uses, because this method is only consulted after `cards()` came back
     * empty.
     *
     * `no_profile` never reaches the view: the controller redirects a
     * profile-less traveller to the preferences form before this service is
     * asked anything.
     *
     * Every interest test below goes through `penalisedWeights()`, so a tag
     * the traveller has passed down to zero weight is NOT an interest here
     * either: "shares your interests" would otherwise invite a traveller back
     * for cards the deck will never deal.
     */
    public function emptyState(User $user): array
    {
        $resolved = $this->penalisedWeights($user);

        if ($resolved === null) {
            return ['reason' => 'no_profile'];
        }

        $profile = $resolved['profile'];
        $weights = $resolved['weights'];

        if ($weights->isEmpty() || $weights->sum() === 0) {
            return [
                'reason' => 'no_interests',
                'tier' => $profile->budget_level,
            ];
        }

        $swipedIds = DestinationSwipe::where('user_id', $user->id)
            ->pluck('destination_id');

        $inTier = Destination::query()
            ->with('tags')
            ->where('is_active', true)
            ->where('budget_level', $profile->budget_level)
            ->get()
            ->values();

        if ($inTier->isEmpty()) {
            return [
                'reason' => 'tier_nothing',
                'tier' => $profile->budget_level,
            ];
        }

        $region = $profile->preferred_region;
        $inRegion = $inTier->unless($region === null || $region === '', function ($collection) use ($region) {
            return $collection->filter(function (Destination $destination) use ($region) {
                $needle = mb_strtolower($region);

                return str_contains(mb_strtolower($destination->province ?? ''), $needle)
                    || str_contains(mb_strtolower($destination->municipality ?? ''), $needle);
            });
        });

        if ($inRegion->isEmpty()) {
            return [
                'reason' => 'region_nothing',
                'region' => $region,
                'tier' => $profile->budget_level,
                'tier_count' => $inTier->count(),
            ];
        }

        $sharesAnInterest = fn (Destination $destination) => $destination->tags->contains(
            fn ($tag) => $weights->get($tag->id, 0) > 0
        );

        $tagMatches = $inRegion->filter($sharesAnInterest);

        if ($tagMatches->isEmpty()) {
            $elsewhere = Destination::query()
                ->with('tags')
                ->where('is_active', true)
                ->where('budget_level', '!=', $profile->budget_level)
                ->get()
                ->filter($sharesAnInterest)
                ->count();

            return [
                'reason' => 'no_interests',
                'tier' => $profile->budget_level,
                'elsewhere' => $elsewhere,
            ];
        }

        $unswiped = $tagMatches->filter(
            fn (Destination $destination) => ! $swipedIds->contains($destination->id)
        );

        if ($unswiped->isNotEmpty()) {
            return ['reason' => 'cards'];
        }

        return [
            'reason' => 'all_swiped',
            'matched' => $tagMatches->count(),
        ];
    }
}