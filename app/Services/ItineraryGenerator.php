<?php

namespace App\Services;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Place;
use App\Models\TravelProfile;
use App\Models\User;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ItineraryGenerator
{
    private const MAX_DESTINATION_COST = 99999999.99;

    private const MAX_MATCH_SCORE = 100.0;

    public function __construct(
        private RecommendationService $recommendations
    ) {
    }

    /**
     * Why nothing could be scheduled, named.
     *
     * Names the first few and counts the rest, because a list of twenty is not
     * an error message. "and 17 more" is honest in a way that silently
     * truncating is not.
     *
     * @param  array<int, array{name: string, reason: string}>  $unscheduled
     */
    private function nothingScheduledMessage(array $unscheduled): string
    {
        if ($unscheduled === []) {
            return 'No selected destinations fit the daily schedule.';
        }

        $headline = 'No selected destinations fit the daily schedule:';

        $named = collect(array_slice($unscheduled, 0, 3))
            ->map(fn (array $row) => sprintf(
                '%s (%s)',
                $row['name'],
                $row['reason']
            ))
            ->implode('; ');

        $remaining = count($unscheduled) - 3;

        if ($remaining > 0) {
            $named .= sprintf('; and %d more', $remaining);
        }

        return $headline.' '.$named;
    }

    /**
     * The researched food and lodging available for a trip.
     *
     * **SILENTLY EMPTY IS CORRECT AND IS THE POINT.** The catalogue has 36
     * researched places across three provinces and none anywhere else, so most
     * trips have no meal to place. That must not block generation and must not
     * be reported as a failure: a province with nothing researched produces an
     * attractions-only trip, which is what the app did before and is a true
     * statement. The coverage report is where that absence is owned.
     *
     * `servesMeal('lunch')`, not "is food": a place serving only merienda and
     * dinner cannot be put in the lunch block, and claiming otherwise is the
     * same error as reading a registration window as opening hours.
     *
     * @return array{meals: array<int, Place>, lodging: Place|null}
     */
    private function tripPlaces(string $area, string $tier): array
    {
        $places = Place::query()
            ->where('province', $area)
            ->where('budget_level', $tier)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        return [
            'meals' => $places
                ->filter(fn (Place $place) => $place->isFood() && $place->servesMeal('lunch'))
                ->values()
                ->all(),
            'lodging' => $places->first(fn (Place $place) => $place->isLodging()),
        ];
    }

    /**
     * Put one meal into the day's lunch block.
     *
     * The block at 12:00–13:00 was already reserved and already pushed visits
     * out of its way, so this fills a gap the scheduler had been leaving empty
     * on purpose rather than inventing a new concept.
     *
     * INSERTED IN ORDER, NOT APPENDED — after every stop finished by 12:00 and
     * before any starting at or after 13:00, then renumbered. Appending would
     * produce a plan that eats lunch at 17:00. A day with no such gap gets no
     * meal: refusing to place it somewhere wrong beats inventing a time.
     *
     * @param  array<int, array<string, mixed>>  $stops
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function insertLunch(array $stops, array $context): array
    {
        if ($stops === []) {
            return $stops;
        }

        /** @var Place|null $meal */
        $meal = $this->tripPlaces($context['area'], $context['tier'])['meals'][$context['day'] - 1] ?? null;

        if ($meal === null) {
            return $stops;
        }

        $lunchStart = ItinerarySchedule::LUNCH_START_MINUTES;
        $lunchEnd = ItinerarySchedule::LUNCH_END_MINUTES;

        $index = 0;

        foreach ($stops as $position => $stop) {
            $end = ItinerarySchedule::parse((string) $stop['end_time']);

            if ($end !== null && $end <= $lunchStart) {
                $index = $position + 1;
            }
        }

        $next = $stops[$index] ?? null;

        if ($next !== null) {
            $nextStart = ItinerarySchedule::parse((string) $next['start_time']);

            if ($nextStart !== null && $nextStart < $lunchEnd) {
                return $stops;
            }
        }

        $duration = min(
            max(1, (int) ceil((float) $meal->recommended_minutes)),
            $lunchEnd - $lunchStart
        );

        array_splice($stops, $index, 0, [[
            'place_id' => $meal->id,
            'item_type' => 'meal',
            'sort_order' => 0,
            'start_time' => ItinerarySchedule::format($lunchStart),
            'end_time' => ItinerarySchedule::format($lunchStart + $duration),
            'travel_minutes_from_previous' => 0,
            // null when the price is unknown, and 0 in the column is this
            // project's standing convention for "we have no figure". The item's
            // snapshotCost() is what reads it back, so the editor and the
            // generator cannot disagree about an unknown.
            'estimated_cost' => $meal->costFor($context['people']) ?? 0,
        ]]);

        return $this->renumber($stops);
    }

    /**
     * Add the trip's lodging, once, at the end of the first day.
     *
     * A hotel is where you sleep, so it goes after the last thing you do rather
     * than at 08:00 pretending to be a morning errand. Anchoring day one to the
     * hotel is separate work that decides the arrival side; the same row cannot
     * sit in two places at once.
     *
     * Duration is the place's own visit length, stored as zero for lodging on
     * purpose. You do not spend ninety minutes at your own hotel, and a
     * fabricated hour would displace a real visit to claim the space.
     *
     * Once per trip because a hotel is charged once: the second night's bill is
     * the first night's bill, and the editor's `$chargedLodging` exists to stop
     * exactly that double-charge on its side.
     *
     * @param  array<int, array<string, mixed>>  $stops
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function appendLodging(array $stops, array $context): array
    {
        if ($stops === [] || $context['day'] !== 1) {
            return $stops;
        }

        /** @var Place|null $lodging */
        $lodging = $this->tripPlaces($context['area'], $context['tier'])['lodging'];

        if ($lodging === null) {
            return $stops;
        }

        $start = ItinerarySchedule::parse((string) end($stops)['end_time']) ?? ItinerarySchedule::DAY_END_MINUTES;

        $stops[] = [
            'place_id' => $lodging->id,
            'item_type' => 'lodging',
            'sort_order' => 0,
            'start_time' => ItinerarySchedule::format($start),
            'end_time' => ItinerarySchedule::format($start),
            'travel_minutes_from_previous' => ItinerarySchedule::TRAVEL_MINUTES,
            'estimated_cost' => $lodging->costFor($context['people'], 1, 1) ?? 0,
        ];

        return $this->renumber($stops);
    }

    /**
     * @param  array<int, array<string, mixed>>  $stops
     * @return array<int, array<string, mixed>>
     */
    private function renumber(array $stops): array
    {
        foreach ($stops as $position => $stop) {
            $stops[$position]['sort_order'] = $position + 1;
        }

        return $stops;
    }

    /**
     * When a destination can actually be visited on a given day, in
     * minutes-since-midnight — or why not.
     *
     * THE POINT IS TO STOP SCHEDULING VISITS THE CATALOGUE SAYS ARE IMPOSSIBLE.
     * The project curates, cites and verifies opening hours for every row, and
     * until this existed the generator read none of them: a plan would put an
     * 11:00 visit to somewhere whose own page says 05:00–07:00, which is a
     * registration cut-off. That is a factual error produced by the feature
     * that was built to be trustworthy about facts.
     *
     * Four rules, each of which is a decision rather than a detail:
     *
     * - **An undated trip only gets the base window.** Without a date there is
     *   no weekday, so a "closed Mondays" row cannot be placed on a Monday and
     *   pretending otherwise would be inventing a date. The general hours still
     *   constrain the time of day.
     * - **An ADVISORY window is not an opening window.** `registration_window`,
     *   `reservation_required` and `alert_dependent` describe a cut-off, a
     *   booking rule or a hazard level — never when the gate is open. Enforcing
     *   them as hours would print "open 05:00–07:00" as a fact, which is the
     *   precise error this work exists to stop. So they constrain nothing here.
     * - **An overnight window constrains nothing.** `open > close` means it is
     *   reachable at any hour of the day, so a plan inside 08:00–18:00 is
     *   always inside it.
     * - **A window that cannot contain the visit skips the stop**, with the
     *   hours in the reason. Silently placing it anyway would be the same lie
     *   with extra steps.
     *
     * @return array{open: int, close: int, label: string}|null null when the
     *         destination may be visited at any time of day
     */
    private function visitWindow(Destination $destination, ?DateTimeInterface $date): ?array
    {
        // Advisory kinds are a rule about access, not a clock.
        if ($destination->hours_kind !== null && $destination->hoursKindIsAdvisory()) {
            return null;
        }

        $window = $date !== null
            ? $destination->hoursForDay($date)
            : $this->baseWindow($destination);

        if ($window === null) {
            return null;
        }

        $open = ItinerarySchedule::parse($window['open']);
        $close = ItinerarySchedule::parse($window['close']);

        if ($open === null || $close === null) {
            return null;
        }

        // Ungated overnight: reachable whenever the traveller happens to be
        // awake, which a day inside 08:00–18:00 always is.
        if ($open >= $close) {
            return null;
        }

        return [
            'open' => $open,
            'close' => $close,
            'label' => ItinerarySchedule::format($open).'–'.ItinerarySchedule::format($close),
        ];
    }

    /**
     * The general window, for a trip with no start date.
     *
     * Separate from `hoursForDay()` because that one needs a date to resolve
     * per-day windows and closed days, and inventing one here would let the
     * generator assert "open on a Tuesday" about a trip that does not know what
     * day it is.
     */
    private function baseWindow(Destination $destination): ?array
    {
        $open = Destination::formatTime($destination->opening_time);
        $close = Destination::formatTime($destination->closing_time);

        if ($open === null || $close === null) {
            return null;
        }

        return ['open' => $open, 'close' => $close];
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

        $duration = $this->durationFor($data, $profile);

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

        // Candidates, not recommendations (ADR 0004). This used to be
        // `recommend()->keyBy('id')`, which meant a destination the traveller
        // had never swiped had no score here and was dropped by the check
        // below — the ceiling, enforced in a fourth place nobody was looking
        // at. Candidates covers every active destination in the area, so this
        // is now a guard against a row that stopped being available mid-request
        // rather than a gate.
        $scoreMap = $this->recommendations
            ->candidates($user, $area)
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
                'One or more selected destinations are no longer available.'
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
            $cursor = ItinerarySchedule::DAY_START_MINUTES;

            // The actual calendar date of this day, when the trip has one. Null
            // for an undated trip, and every weekday rule below keys off that
            // null rather than guessing a weekday.
            $dayDate = $startDate?->copy()->addDays($dayNumber - 1);

            while (
                count($stops) < ItinerarySchedule::MAX_STOPS_PER_DAY
                && $destinationIndex < $orderedDestinations->count()
            ) {
                $destination = $orderedDestinations->get(
                    $destinationIndex
                );

                $visitMinutes = (int) ceil(
                    (float) $destination->recommended_minutes
                );

                $longestVisitWindow = ItinerarySchedule::longestVisitMinutes();

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
                    : ItinerarySchedule::TRAVEL_MINUTES;

                // Closed that weekday: skip it, and say which day closed it.
                // Without a date we cannot know the weekday at all, so this
                // only applies to a dated trip.
                //
                // `hasAnyHours()` IS NOT REDUNDANT HERE, and omitting it is a
                // trap worth stating plainly. `hoursForDay()` returns null for
                // two different things — "closed that weekday" and "we have
                // never published hours for this row" — and `isClosedOn()`
                // cannot tell them apart. So without this guard every
                // destination with blank hours reads as closed on every day of
                // every dated trip, and generation silently produces nothing.
                // It did, on the first run of this change.
                if (
                    $dayDate !== null
                    && $destination->hasAnyHours()
                    && $destination->isClosedOn($dayDate)
                ) {
                    $unscheduled[] = [
                        'name' => $destination->name,
                        'reason' => 'Closed on '.ucfirst(strtolower($dayDate->format('l'))).'.',
                    ];

                    $destinationIndex++;

                    continue;
                }

                $window = $this->visitWindow($destination, $dayDate);

                if (
                    $window !== null
                    && $window['close'] - $window['open'] < $visitMinutes
                ) {
                    $unscheduled[] = [
                        'name' => $destination->name,
                        'reason' => sprintf(
                            'Open %s, so there is not room for a %d-minute visit.',
                            $window['label'],
                            $visitMinutes
                        ),
                    ];

                    $destinationIndex++;

                    continue;
                }

                $start = ItinerarySchedule::nextStart(
                    $cursor + $travelMinutes,
                    $visitMinutes,
                    $window['open'] ?? null
                );

                $end = $start + $visitMinutes;

                if ($end > ItinerarySchedule::DAY_END_MINUTES) {
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
                    'start_time' => ItinerarySchedule::format($start),
                    'end_time' => ItinerarySchedule::format($end),
                    'travel_minutes_from_previous' => $travelMinutes,
                    'estimated_cost' => $costCents / 100,
                ];

                $cursor = $end;
                $totalCostCents += $costCents;
                $totalScore += (float) $destination->match_score;
                $scheduledCount++;
                $destinationIndex++;
            }

            $context = [
                'area' => $area,
                'tier' => $profile->budget_level,
                'day' => $dayNumber,
                'people' => max(1, (int) ($data['group_size'] ?? $profile->group_size)),
            ];

            // Attractions first, then the two rows a complete trip needs. Both
            // are strictly additive: neither consumes a destination, and a
            // province with nothing researched simply keeps its attractions-only
            // day.
            $stops = $this->insertLunch($stops, $context);
            $stops = $this->appendLodging($stops, $context);

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
            // CARRY THE REASONS INTO THE MESSAGE, because they are discarded
            // here. The controller only flashes `unscheduled_destinations` when
            // something WAS scheduled, so on this path the traveller gets
            // whatever this string says and nothing else.
            //
            // A generic "no selected destinations fit the daily schedule" made
            // hours-awareness pointless: the whole value of skipping a stop is
            // telling the traveller it is closed on the day they picked, or that
            // its window is too small for the visit — and that explanation was
            // being thrown away at exactly the moment it was needed most.
            throw new RuntimeException(
                $this->nothingScheduledMessage($unscheduled)
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

    /**
     * How many days the trip runs.
     *
     * The saved preference is the default, not the answer: a traveller whose
     * profile says three days may be planning a long weekend and choose four on
     * the generation form. A value is only taken when it is a whole number
     * inside 1..Itinerary::MAX_DAYS, so a crafted request cannot ask for zero
     * days or five hundred, and anything unusable falls back to the profile
     * rather than failing the whole generation over a field the traveller may
     * never have seen.
     *
     * @param  array<string, mixed>  $data
     */
    private function durationFor(array $data, TravelProfile $profile): int
    {
        $requested = $data['days'] ?? null;

        $duration = $requested === null || $requested === ''
            ? false
            : filter_var(
                $requested,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => Itinerary::MAX_DAYS]]
            );

        if ($duration !== false) {
            return $duration;
        }

        $fallback = filter_var(
            $profile->trip_duration_days,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => Itinerary::MAX_DAYS]]
        );

        if ($fallback === false) {
            throw new RuntimeException(
                'Choose a valid trip duration first.'
            );
        }

        return $fallback;
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