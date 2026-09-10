<?php

namespace App\Services;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\User;
use Illuminate\Support\Collection;

class RecommendationService
{
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
                $totalWeight
            ) {
                $matchedTags = $destination->tags->filter(
                    fn ($tag) => $weights->has($tag->id)
                );

                $matchedWeight = $matchedTags->sum(
                    fn ($tag) => $weights->get($tag->id, 0)
                );

                $preferenceScore = (
                    $matchedWeight / $totalWeight
                ) * 100;

                $destination->match_score = round(
                    min(100, ($preferenceScore * 0.7) + 30),
                    2
                );

                $destination->matched_tags = $matchedTags->values();

                $destination->recommendation_reason =
                    'You liked this destination during discovery and it matches your travel profile.';

                return $destination;
            })
            ->filter(function (Destination $destination) {
                return $destination->matched_tags->isNotEmpty();
            })
            ->sortByDesc('match_score')
            ->values()
            ->take($limit);
    }
}