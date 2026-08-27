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
        $profile = $user->load('travelProfile.tags')->travelProfile;

        if (!$profile) {
            return collect();
        }

        $weights = $profile->tags
            ->pluck('pivot.weight', 'id')
            ->map(fn ($weight) => (int) $weight);

        $totalWeight = $weights->sum();
        $swipedIds = DestinationSwipe::where('user_id', $user->id)->pluck('destination_id');

        $destinations = Destination::query()
            ->with('tags')
            ->where('is_active', true)
            ->where('budget_level', $profile->budget_level)
            ->whereNotIn('id', $swipedIds)
            ->when($profile->preferred_region, function ($query) use ($profile) {
                $region = $profile->preferred_region;
                $query->where(function ($query) use ($region) {
                    $query->where('province', 'like', "%{$region}%")
                        ->orWhere('municipality', 'like', "%{$region}%");
                });
            })
            ->get();

        return $destinations
            ->map(function (Destination $destination) use ($weights, $totalWeight) {
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
            ->sortByDesc('preference_score')
            ->values()
            ->take($limit);
    }
}