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
                    fn ($tag) => $weights->has($tag->id)
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
     */
    public function emptyState(User $user): array
    {
        $profile = $user
            ->load('travelProfile.tags')
            ->travelProfile;

        if (! $profile) {
            return ['reason' => 'no_profile'];
        }

        $weights = $profile->tags
            ->pluck('pivot.weight', 'id')
            ->map(fn ($weight) => (int) $weight);

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
            fn ($tag) => $weights->has($tag->id)
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