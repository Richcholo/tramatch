<?php

namespace App\Services;

use App\Models\Itinerary;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ItineraryGenerator
{
    public function __construct(
        private RecommendationService $recommendations
    ) {
    }

    public function create(User $user, array $data): Itinerary
    {
        $profile = $user
            ->load('travelProfile.tags')
            ->travelProfile;

        if (!$profile) {
            throw new RuntimeException(
                'Complete your travel preferences first.'
            );
        }

        $startDate = $data['start_date'] ?? null;
        $startDate = $startDate !== '' ? $startDate : null;

        $destinations = $this->recommendations
            ->recommend($user, $profile->trip_duration_days * 3)
            ->values();

        if ($destinations->isEmpty()) {
            throw new RuntimeException(
                'Like at least one destination before generating an itinerary.'
            );
        }

        return DB::transaction(function () use (
            $user,
            $profile,
            $destinations,
            $data,
            $startDate
        ) {
            $totalCost = $destinations->sum('estimated_cost');
            $averageScore = round($destinations->avg('match_score'), 2);

            $itinerary = $user->itineraries()->create([
                'title' => $data['title'] ?? 'My TraMatch trip',
                'start_date' => $startDate,
                'budget_level' => $profile->budget_level,
                'trip_duration_days' => $profile->trip_duration_days,
                'total_estimated_cost' => $totalCost,
                'match_score' => $averageScore,
            ]);

            $destinationIndex = 0;

            for (
                $dayNumber = 1;
                $dayNumber <= $profile->trip_duration_days;
                $dayNumber++
            ) {
                $date = $startDate
                    ? Carbon::createFromFormat('Y-m-d', $startDate)
                        ->addDays($dayNumber - 1)
                        ->toDateString()
                    : null;

                $day = $itinerary->days()->create([
                    'day_number' => $dayNumber,
                    'date' => $date,
                ]);

                $minutes = 8 * 60;

                for ($slot = 1; $slot <= 3; $slot++) {
                    $destination = $destinations->get($destinationIndex);

                    if (!$destination) {
                        break;
                    }

                    $start = Carbon::createFromTime(0)
                        ->addMinutes($minutes);

                    $end = $start
                        ->copy()
                        ->addMinutes($destination->recommended_minutes);

                    $day->items()->create([
                        'destination_id' => $destination->id,
                        'sort_order' => $slot,
                        'start_time' => $start->format('H:i'),
                        'end_time' => $end->format('H:i'),
                        'travel_minutes_from_previous' => $slot === 1 ? 0 : 45,
                        'estimated_cost' => $destination->estimated_cost,
                    ]);

                    $minutes = $end->hour * 60 + $end->minute + 45;
                    $destinationIndex++;
                }
            }

            return $itinerary->load('days.items.destination');
        });
    }
}