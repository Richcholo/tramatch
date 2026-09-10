<?php

namespace App\Services;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ItineraryGenerator
{
    private const DAY_START = 8 * 60;

    private const DAY_END = 18 * 60;

    private const LUNCH_START = 12 * 60;

    private const LUNCH_END = 13 * 60;

    private const TRAVEL_MINUTES = 45;

    private const MAX_STOPS_PER_DAY = 3;

    private const MAX_DESTINATION_COST = 99999999.99;

    private const MAX_MATCH_SCORE = 100.0;

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

        $duration = filter_var(
            $profile->trip_duration_days,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($duration === false) {
            throw new RuntimeException(
                'Choose a valid trip duration first.'
            );
        }

        $area = trim((string) ($data['area'] ?? ''));

        if ($area === '') {
            throw new RuntimeException(
                'Choose an area before generating an itinerary.'
            );
        }

        $selectedIds = collect($data['destination_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($selectedIds->isEmpty()) {
            throw new RuntimeException(
                'Select at least one destination.'
            );
        }

        $startDate = $this->parseStartDate(
            $data['start_date'] ?? null
        );

        $destinations = Destination::query()
            ->with('tags')
            ->where('is_active', true)
            ->where('province', $area)
            ->where('budget_level', $profile->budget_level)
            ->whereIn('id', $selectedIds)
            ->get()
            ->keyBy('id');

        if ($destinations->count() !== $selectedIds->count()) {
            throw new RuntimeException(
                'One or more selected destinations are no longer available in the selected area.'
            );
        }

        $scoreMap = $this->recommendations
            ->recommend($user, 1000)
            ->keyBy('id');

        $orderedDestinations = $selectedIds
            ->map(function ($id) use ($destinations, $scoreMap) {
                $destination = $destinations->get($id);
                $score = $scoreMap->get($id);

                if (!$destination || !$score) {
                    return null;
                }

                $destination->match_score = (float) $score->match_score;

                return $destination;
            })
            ->filter()
            ->values();

        if ($orderedDestinations->count() !== $selectedIds->count()) {
            throw new RuntimeException(
                'One or more selected destinations are no longer valid recommendations.'
            );
        }

        $plan = [];
        $unscheduled = [];
        $destinationIndex = 0;
        $totalCostCents = 0;
        $totalScore = 0.0;
        $scheduledCount = 0;

        for ($dayNumber = 1; $dayNumber <= $duration; $dayNumber++) {
            $stops = [];
            $cursor = self::DAY_START;

            while (
                count($stops) < self::MAX_STOPS_PER_DAY
                && $destinationIndex < $orderedDestinations->count()
            ) {
                $destination = $orderedDestinations->get(
                    $destinationIndex
                );

                $visitMinutes = (int) ceil(
                    (float) $destination->recommended_minutes
                );

                $longestVisitWindow = max(
                    self::LUNCH_START - self::DAY_START,
                    self::DAY_END - self::LUNCH_END
                );

                if (
                    $visitMinutes <= 0
                    || $visitMinutes > $longestVisitWindow
                    || !is_numeric($destination->estimated_cost)
                    || !is_finite((float) $destination->estimated_cost)
                    || (float) $destination->estimated_cost < 0
                    || (float) $destination->estimated_cost > self::MAX_DESTINATION_COST
                    || !is_numeric($destination->match_score)
                    || !is_finite((float) $destination->match_score)
                    || (float) $destination->match_score < 0
                    || (float) $destination->match_score > self::MAX_MATCH_SCORE
                ) {
                    $unscheduled[] = [
                        'name' => $destination->name,
                        'reason' => 'This destination cannot fit the itinerary rules.',
                    ];

                    $destinationIndex++;

                    continue;
                }

                $travelMinutes = empty($stops)
                    ? 0
                    : self::TRAVEL_MINUTES;

                $start = $this->nextActivityStart(
                    $cursor + $travelMinutes,
                    $visitMinutes
                );

                $end = $start + $visitMinutes;

                if ($end > self::DAY_END) {
                    if (!empty($stops)) {
                        break;
                    }

                    $unscheduled[] = [
                        'name' => $destination->name,
                        'reason' => 'This destination does not fit within the daily schedule.',
                    ];

                    $destinationIndex++;

                    continue;
                }

                $costCents = (int) round(
                    (float) $destination->estimated_cost * 100
                );

                $stops[] = [
                    'destination_id' => $destination->id,
                    'sort_order' => count($stops) + 1,
                    'start_time' => $this->formatTime($start),
                    'end_time' => $this->formatTime($end),
                    'travel_minutes_from_previous' => $travelMinutes,
                    'estimated_cost' => $costCents / 100,
                ];

                $cursor = $end;
                $totalCostCents += $costCents;
                $totalScore += (float) $destination->match_score;
                $scheduledCount++;
                $destinationIndex++;
            }

            $plan[] = [
                'day_number' => $dayNumber,
                'date' => $startDate
                    ? $startDate
                        ->copy()
                        ->addDays($dayNumber - 1)
                        ->toDateString()
                    : null,
                'stops' => $stops,
            ];
        }

        while ($destinationIndex < $orderedDestinations->count()) {
            $destination = $orderedDestinations->get($destinationIndex);

            $unscheduled[] = [
                'name' => $destination->name,
                'reason' => 'No remaining schedule capacity was available.',
            ];

            $destinationIndex++;
        }

        if ($scheduledCount === 0) {
            throw new RuntimeException(
                'No selected destinations fit the daily schedule.'
            );
        }

        return DB::transaction(function () use (
            $user,
            $profile,
            $data,
            $area,
            $duration,
            $startDate,
            $plan,
            $unscheduled,
            $totalCostCents,
            $totalScore,
            $scheduledCount
        ) {
            $title = trim((string) ($data['title'] ?? ''));

            $itinerary = $user->itineraries()->create([
                'title' => $title !== ''
                    ? $title
                    : 'My TraMatch trip',
                'area' => $area,
                'start_date' => $startDate?->toDateString(),
                'budget_level' => $profile->budget_level,
                'trip_duration_days' => $duration,
                'total_estimated_cost' => $totalCostCents / 100,
                'match_score' => round(
                    $totalScore / $scheduledCount,
                    2
                ),
            ]);

            foreach ($plan as $plannedDay) {
                $day = $itinerary->days()->create([
                    'day_number' => $plannedDay['day_number'],
                    'date' => $plannedDay['date'],
                ]);

                foreach ($plannedDay['stops'] as $stop) {
                    $day->items()->create($stop);
                }
            }

            $itinerary->setAttribute(
                'unscheduled_destinations',
                $unscheduled
            );

            return $itinerary->load('days.items.destination');
        });
    }

    private function nextActivityStart(
        int $earliestStart,
        int $duration
    ): int {
        if (
            $earliestStart < self::LUNCH_END
            && $earliestStart + $duration > self::LUNCH_START
        ) {
            return self::LUNCH_END;
        }

        return $earliestStart;
    }

    private function formatTime(int $minutes): string
    {
        return sprintf(
            '%02d:%02d',
            intdiv($minutes, 60),
            $minutes % 60
        );
    }

    private function parseStartDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (
            !is_string($value)
            || !preg_match(
                '/\A(\d{4})-(\d{2})-(\d{2})\z/',
                $value,
                $parts
            )
            || !checkdate(
                (int) $parts[2],
                (int) $parts[3],
                (int) $parts[1]
            )
        ) {
            throw new RuntimeException(
                'Start date must be a valid date in YYYY-MM-DD format.'
            );
        }

        return Carbon::createFromFormat('!Y-m-d', $value);
    }
}