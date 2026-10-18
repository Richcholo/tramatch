<?php

namespace App\Services;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Place;
use App\Models\TravelProfile;
use App\Models\User;
use App\Services\Routing\RouteService;
use App\Services\Routing\WritesLegs;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ItineraryGenerator
{
    use WritesLegs;

    private const MAX_DESTINATION_COST = 99999999.99;

    private const MAX_MATCH_SCORE = 100.0;

    /**
     * How many legs one generation will ask the road about.
     *
     * Generation now routes, and routing costs an HTTP call per leg until the
     * 30-day cache answers instead — measured at roughly 300-500 ms each. A
     * 30-day trip is 87 legs, which is over PHP's default execution limit on a
     * cold cache, and a generation that dies half way leaves the traveller with
     * nothing at all.
     *
     * 24 covers an eight-day trip at three stops a day in full. Beyond it the
     * remaining legs are simply left `unresolved`, which is the truth: we do
     * not have a measured drive for that pair. The traveller who cares about one
     * of them opens the editor and saves, which routes the whole day.
     */
    private const MAX_ROUTED_LEGS = 24;

    /**
     * Legs still available to this run, spent as days are written.
     */
    private int $routingBudget = self::MAX_ROUTED_LEGS;

    public function __construct(
        private RecommendationService $recommendations,
        private RouteService $routes,
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
     * **THE LODGING ROW IS COUNTED, AND COUNTING IT IS CORRECT.** The hotel is
     * seeded before the attraction loop, so it sits at index 0 with one instant
     * at the start of the day, and it must take part in this scan.
     *
     * An earlier version of this method skipped it, on the reasoning that "the
     * lunch gap is a gap between things you DO". Two things were wrong with
     * that. It was not load-bearing at all — `nextStart()` already nudges a
     * visit that would cross noon to 13:00, so the guard changed nothing in any
     * day the generator can currently build. And in the one case where it *did*
     * change the outcome it was actively harmful: when the day's only attraction
     * does not open until 13:00, the traveller stands at their hotel from 08:00
     * with nothing planned, which is precisely when they want lunch, and the
     * guard threw the meal away.
     *
     * So the hotel counts, and the scan is unchanged from before the hotel was
     * anchored at all. Both claims above are kept because a guard that looks
     * principled and silently deletes a meal is the kind of thing that gets
     * re-added on the next tidy-up.
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
        $meal = $context['meals'][$context['day'] - 1] ?? null;

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
     * The trip's hotel, as the FIRST thing on day one.
     *
     * It used to be appended to the END of the first day, which was the more
     * defensible of two wrong answers: "you go to your hotel at the end of the
     * day" is true of the drive, but the plan it produced opened day one with
     * no idea where the traveller was standing, and the first leg of the whole
     * trip had no leg in it at all — there was no previous pin, because the
     * day's first stop was an attraction rather than the place the traveller
     * slept.
     *
     * A real trip starts at the hotel. Anchoring it there means the first drive
     * of the trip is measured like every other drive, which is the entire point
     * of the routing work.
     *
     * Duration is the place's own visit length, stored as zero for lodging on
     * purpose: you do not spend ninety minutes at your own hotel, and a
     * fabricated hour would displace a real visit to claim the space. So the row
     * carries one instant, and the read view prints one instant.
     *
     * ONCE per trip, because a hotel is charged once — the second night's bill
     * is the first night's bill.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function lodgingRow(array $context): array
    {
        /** @var Place|null $lodging */
        $lodging = $context['lodging'];

        if ($lodging === null) {
            return [];
        }

        return [[
            'place_id' => $lodging->id,
            'item_type' => 'lodging',
            'sort_order' => 0,
            'start_time' => ItinerarySchedule::format(ItinerarySchedule::DAY_START_MINUTES),
            'end_time' => ItinerarySchedule::format(ItinerarySchedule::DAY_START_MINUTES),
            // Nothing precedes the hotel. The traveller woke up there.
            'travel_minutes_from_previous' => 0,
            'estimated_cost' => $lodging->costFor($context['people'], 1, 1) ?? 0,
        ]];
    }

    /**
     * Great-circle distance between two pins, in kilometres.
     *
     * Used to decide WHICH destination a day visits next, never to tell the
     * traveller how far the drive is — that is `RouteService`'s job and it is
     * measured. This only decides the order.
     *
     * **STRAIGHT LINES, NOT THE ROAD, AND THAT IS THE POINT.** The plan called for
     * nearest-neighbour "over the OSRM legs already cached", which is wrong in a
     * way that only shows up in production: the cached legs are the legs the
     * *previous* run happened to walk. Ordering by them would make the same
     * request produce a different trip depending on whether the cache was warm,
     * and a generator that is not deterministic cannot be reasoned about or
     * tested. A haversine is free, always available, and identical every time.
     *
     * Within a province it is also a good proxy for road distance, which is the
     * premise the three pilot clusters were chosen on.
     */
    private static function distanceFromPin(
        ?float $fromLat,
        ?float $fromLng,
        $toLat,
        $toLng
    ): float {
        // A missing pin is not "infinitely far", it is "no preference". Treating
        // it as far would put every unpinned place at the bottom of the ranking
        // for a reason that has nothing to do with where it is, and treating it
        // as near would promote them all. Zero keeps the catalogue's own order
        // for those rows, which is the old behaviour and a defensible one.
        if ($fromLat === null || $fromLng === null || $toLat === null || $toLng === null) {
            return 0.0;
        }

        $earthRadiusKm = 6371.0;

        $lat1 = deg2rad((float) $fromLat);
        $lat2 = deg2rad((float) $toLat);
        $deltaLat = $lat2 - $lat1;
        $deltaLon = deg2rad((float) $toLng - (float) $fromLng);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * The coordinates of a pin, for the distance comparison above.
     *
     * Stops already placed on a day are plain arrays; the hotel is a `Place`.
     * Both expose a latitude and a longitude in different ways, so this is the
     * one place that knows it.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private static function pinFromPlace(?Place $place): array
    {
        return [$place?->latitude, $place?->longitude];
    }

    /**
     * Why a destination cannot be scheduled, if it cannot.
     *
     * `permanent` reasons are properties of the row and will never change on any
     * day, so a caller may drop the destination for good. `day` reasons are about
     * one weekday and are worth retrying on another.
     *
     * **THE TWO ARE SEPARATED BECAUSE THE OLD CODE CONFLATED THEM.** Every
     * rejection advanced one global cursor, so a destination closed on Monday was
     * removed from the trip entirely and never given a chance on Tuesday — the
     * traveller was told "Closed on Monday" and never learned it was open the
     * rest of the week. That is the same class of error as reading a registration
     * window as opening hours: a fact about one day presented as a fact about the
     * place.
     *
     * @return array{kind: string, reason: string}|null null when it can be scheduled
     */
    private function eligibility(Destination $destination, ?Carbon $dayDate): ?array
    {
        $visitMinutes = (int) ceil((float) $destination->recommended_minutes);

        if (
            $visitMinutes <= 0
            || $visitMinutes > ItinerarySchedule::longestVisitMinutes()
            || !is_numeric($destination->estimated_cost)
            || !is_finite((float) $destination->estimated_cost)
            || (float) $destination->estimated_cost < 0
            || (float) $destination->estimated_cost > self::MAX_DESTINATION_COST
            || !is_numeric($destination->match_score)
            || !is_finite((float) $destination->match_score)
            || (float) $destination->match_score < 0
            || (float) $destination->match_score > self::MAX_MATCH_SCORE
        ) {
            return [
                'kind' => 'permanent',
                'reason' => 'This destination cannot fit the itinerary rules.',
            ];
        }

        $window = $this->visitWindow($destination, $dayDate);

        // The window is too small for the visit. A permanent property of the row,
        // not of the day.
        if ($window !== null && $window['close'] - $window['open'] < $visitMinutes) {
            return [
                'kind' => 'permanent',
                'reason' => sprintf(
                    'Open %s, so there is not room for a %d-minute visit.',
                    $window['label'],
                    $visitMinutes
                ),
            ];
        }

        // Closed that weekday. About THIS day only.
        //
        // `isClosedOnWeekday()`, NOT `hasAnyHours() && isClosedOn()`. The old
        // guard was added to stop blank-hours rows reading as shut all week, and
        // it worked — until a row that publishes `closed_days` and no clock time,
        // which `hasAnyHours()` counts as having hours and `isClosedOn()` then
        // called shut on every day of the week. A place that says "closed Mondays"
        // was unavailable to the traveller for the whole trip.
        //
        // It shipped because the generator dropped a destination the first time it
        // was refused and never asked again, so "closed today" and "closed
        // forever" looked identical from outside. Retrying on another day is what
        // made it visible, and this question is what it needed all along.
        if ($dayDate !== null && $destination->isClosedOnWeekday($dayDate)) {
            return [
                'kind' => 'day',
                'reason' => 'Closed on '.ucfirst(strtolower($dayDate->format('l'))).'.',
            ];
        }

        return null;
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
        // Consumed in place. NOT a cursor: a destination rejected because it
        // was closed today must still be available tomorrow.
        $remaining = $orderedDestinations;
        $totalCostCents = 0;
        $totalScore = 0.0;
        $scheduledCount = 0;

        // Once for the whole trip, not twice a day. See the note in the loop.
        $tripPlaces = $this->tripPlaces($area, $profile->budget_level);

        for ($dayNumber = 1; $dayNumber <= $duration; $dayNumber++) {
            // THE HOTEL IS SEEDED BEFORE THE LOOP, NOT APPENDED AFTER IT, and
            // that ordering is the whole feature. Seeding it here means `$stops`
            // is already non-empty when the first attraction is placed, so the
            // first drive of the trip is laid out like every other drive instead
            // of starting the day and the first stop at the same minute.
            //
            // `tripPlaces()` is resolved ONCE here, not per day. It was being
            // called from two methods per day, so a 7-day trip ran 14 identical
            // queries, while its own docblock claimed it was "resolved once per
            // generation".
            $context = [
                'area' => $area,
                'tier' => $profile->budget_level,
                'day' => $dayNumber,
                'people' => max(1, (int) ($data['group_size'] ?? $profile->group_size)),
                'lodging' => $tripPlaces['lodging'],
                'meals' => $tripPlaces['meals'],
            ];

            $stops = $dayNumber === 1
                ? $this->lodgingRow($context)
                : [];

            // The hotel is NOT an attraction and must not consume one of the
            // three slots: a day that opens at your own hotel still has room for
            // three things to see.
            $attractionCount = 0;

            $cursor = ItinerarySchedule::DAY_START_MINUTES;

            // The actual calendar date of this day, when the trip has one. Null
            // for an undated trip, and every weekday rule below keys off that
            // null rather than guessing a weekday.
            $dayDate = $startDate?->copy()->addDays($dayNumber - 1);

// NEAREST FIRST, NOT HIGHEST-SCORING FIRST.
            //
            // The loop used to walk one global cursor through the match-score order
            // and take whatever it pointed at. That produces geographically
            // scattered days: three places at the top of the ranking can be an
            // hour apart, and the traveller is driven between them for no reason
            // anyone chose.
            //
            // So each slot takes the NEAREST destination that can actually be
            // scheduled there — and "can actually be scheduled" still means every
            // check below, so ordering cannot talk the scheduler into breaking a
            // cited opening window. Geography chooses between candidates that
            // were always going to be legal.
            //
            // EVERY DAY DEPARTS FROM THE HOTEL, because that is where the traveller
            // slept the night before. A province with no researched lodging has no
            // origin, and then every distance is zero and the match-score order
            // stands — the same plan as before, for the provinces with no hotel to
            // leave from.
            [$originLat, $originLng] = self::pinFromPlace($context['lodging']);

            while ($attractionCount < ItinerarySchedule::MAX_STOPS_PER_DAY) {
                $chosenIndex = null;
                $chosenDistance = null;
                $chosenStart = null;

                foreach ($remaining as $index => $candidate) {
                    // A permanent rejection is settled once and never retried.
                    // A weekday closure means "not TODAY" and says nothing else,
                    // so the candidate simply stays in the pool for another day
                    // and is not reported here at all.
                    //
                    // It must be skipped rather than chosen-then-refused: choosing
                    // first would consume it from the pool before the refusal,
                    // which is precisely the bug this whole split exists to fix.
                    $block = $this->eligibility($candidate, $dayDate);

                    if ($block !== null) {
                        if ($block['kind'] === 'permanent') {
                            $unscheduled[] = [
                                'name' => $candidate->name,
                                'reason' => $block['reason'],
                            ];

                            $remaining->forget($index);
                        }

                        continue;
                    }

                    $visitMinutes = (int) ceil((float) $candidate->recommended_minutes);
                    $window = $this->visitWindow($candidate, $dayDate);

                    $start = ItinerarySchedule::nextStart(
                        $cursor + (empty($stops) ? 0 : ItinerarySchedule::TRAVEL_MINUTES),
                        $visitMinutes,
                        $window['open'] ?? null
                    );

                    // Would run past the end of the day. Not this candidate's
                    // problem to report: another one may well fit, which is the
                    // whole point of choosing rather than taking the first refusal.
                    if ($start + $visitMinutes > ItinerarySchedule::DAY_END_MINUTES) {
                        continue;
                    }

                    $distance = self::distanceFromPin(
                        $originLat,
                        $originLng,
                        $candidate->latitude,
                        $candidate->longitude
                    );

                    // Strictly less than, so a tie keeps the earlier candidate and
                    // the result depends only on the catalogue — never on the order
                    // a collection happens to iterate in.
                    if ($chosenDistance === null || $distance < $chosenDistance) {
                        $chosenIndex = $index;
                        $chosenDistance = $distance;
                        $chosenStart = $start;
                    }
                }

                if ($chosenIndex === null) {
                    break;
                }

                $destination = $remaining->get($chosenIndex);
                $remaining->forget($chosenIndex);

                $visitMinutes = (int) ceil((float) $destination->recommended_minutes);
                $travelMinutes = empty($stops) ? 0 : ItinerarySchedule::TRAVEL_MINUTES;
                $start = $chosenStart;
                $end = $start + $visitMinutes;

                $costCents = (int) round((float) $destination->estimated_cost * 100);

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
                $attractionCount++;
            }
            // Strictly additive: the meal neither consumes a destination nor a
            // slot in the three-stop cap, and a province with nothing researched
            // simply keeps its attractions-only day.
            //
            // The hotel is NOT added here. It was already seeded before the loop,
            // which is why `insertLunch()` has to know to ignore it.
            $stops = $this->insertLunch($stops, $context);

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

// Whatever is left never fitted anywhere. Prefer the most specific reason still
        // available, because "no remaining capacity" on a place that was closed on
        // Tuesday tells the traveller nothing they can act on.
        foreach ($remaining as $destination) {
            $block = $this->eligibility($destination, $dayDate);

            $unscheduled[] = [
                'name' => $destination->name,
                'reason' => $block['reason']
                    ?? 'No remaining schedule capacity was available.',
            ];
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

            // Every leg the plan implies, resolved against the road as the rows
            // are written.
            //
            // THIS IS THE CHANGE. Generation used to leave every leg
            // `unresolved` and every gap at the flat 45, on the recorded
            // reasoning that routing inside a generation request is the wrong
            // latency trade -- so a trip the traveller never edited carried a
            // guessed drive time in the same slot a measured one uses. The trip
            // is already being built stop by stop here, the road is one more
            // read per gap, and it is cached for a month and bounded below.
            //
            // It happens INSIDE the existing transaction, so a leg that cannot
            // be resolved costs nothing: `RouteService::leg()` answers null for
            // every failure and the row is written `unresolved`.
            $this->routingBudget = self::MAX_ROUTED_LEGS;

            foreach ($plan as $plannedDay) {
                $day = $itinerary->days()->create([
                    'day_number' => $plannedDay['day_number'],
                    'date' => $plannedDay['date'],
                ]);

                $items = [];

                foreach ($plannedDay['stops'] as $stop) {
                    $items[] = $day->items()->create($stop);
                }

                $this->routingBudget -= $this->writeLegs($items, $this->routingBudget);
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
