<?php

namespace App\Http\Controllers;

use App\Domain\Destinations\DestinationCarousel;
use App\Models\Destination;
use App\Models\User;
use App\Services\RecommendationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DestinationController extends Controller
{
    /**
     * How long a slide holds before autoplay advances, in milliseconds.
     *
     * Not the four seconds a carousel is usually given. The stage's own motion is
     * 620ms of panel travel and a 700-900ms backdrop cross-fade, and a dwell
     * shorter than the sum of those reads as an interruption rather than as
     * rhythm -- the visitor spends longer watching the transition than reading
     * the caption. Seven seconds is long enough to read the caption and the
     * standfirst under it.
     *
     * Rendered into the markup as `data-stage-interval`, so the script and the
     * view have one number to agree with.
     */
    private const STAGE_INTERVAL_MS = 7000;
    public function index(Request $request): View
    {
        $locations = Destination::query()
            ->where('is_active', true)
            ->get(['name', 'province', 'municipality'])
            ->flatMap(fn (Destination $destination) => [
                $destination->name,
                $destination->municipality,
                $destination->province,
            ])
            ->map(fn ($location) => trim($location))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $destinations = Destination::query()
            ->with('tags')
            ->where('is_active', true)
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('province', 'like', "%{$search}%")
                        ->orWhere('municipality', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('budget_level'), function ($query) use ($request) {
                $query->where('budget_level', $request->string('budget_level')->toString());
            })
            ->latest()
            ->paginate(9)
            ->onEachSide(1)
            ->withQueryString();

        /*
         * `?slide=<slug>` centres a specific destination, so a link can point at
         * "the stage, showing X" and stay bookmarkable. Without it the stage opens
         * on the first photograph in canonical order.
         */
        $stage = $this->stage($request->filled('slide')
            ? $request->string('slide')->toString()
            : null);

        return view('destinations.index', [
            'destinations' => $destinations,
            'locations' => $locations,
            'stageSlides' => $stage['slides'],
            'stageActiveIndex' => $stage['activeIndex'],
            'stageNeighbours' => $stage['neighbours'],
            'stageMatchScores' => $stage['matchScores'],
            'stageInterval' => self::STAGE_INTERVAL_MS,
        ]);
    }

    public function show(Destination $destination): View
    {
        abort_unless($destination->is_active, 404);

        $destination->load([
            'tags',
            'reviews' => fn ($query) => $query
                ->where('status', 'published')
                ->with('user')
                ->latest(),
        ]);

        /*
         * The shared stage, with THIS destination centred.
         *
         * `activeIndex` is derived from the route, which is what makes a hard page
         * load land in exactly the same visual state as an in-page advance -- so
         * deep links, Back/Forward and a shared link all replay correctly. The
         * order comes from the same presenter the index uses, so the stage here is
         * the index stage with the index shifted, and the two cannot drift.
         */
        $stage = $this->stage($destination->slug);

        return view('destinations.show', [
            'stageSlides' => $stage['slides'],
            'stageActiveIndex' => $stage['activeIndex'],
            'stageNeighbours' => $stage['neighbours'],
            'stageMatchScores' => $stage['matchScores'],
            'stageInterval' => self::STAGE_INTERVAL_MS,
            'destination' => $destination,
            'openState' => $this->openState($destination),
            'hoursLabel' => $this->hoursLabel($destination),
            'closedDaysLabel' => $destination->closedDaysLabel(),
            'todayName' => now()->format('l'),
            'todayWindow' => $destination->hoursForDay(now()),
            'perDayHours' => $destination->normalisedDailyHours(),
            'daySlugs' => Destination::daySlugs(),
            'updatedOn' => $this->updatedOn($destination),
        ]);
    }

    /**
     * Everything the destinations stage needs, for either page.
     *
     * ONE method, because the two routes must not be able to disagree. The fan on
     * a destination page being the index fan with the index shifted is the whole
     * continuity feature, and it is only true if both routes resolve through the
     * same call.
     *
     * @return array{slides: \Illuminate\Support\Collection, activeIndex: int, neighbours: array{prev: ?array, next: ?array}, matchScores: array<int, float>}
     */
    private function stage(?string $slug = null): array
    {
        $carousel = app(DestinationCarousel::class);

        return [
            'slides' => $carousel->slides($slug),
            'activeIndex' => $carousel->activeIndex($slug),
            'neighbours' => $carousel->neighbours($slug),
            'matchScores' => $this->stageMatchScores(),
        ];
    }

    /**
     * Match scores for the stage caption, keyed by destination id.
     *
     * EMPTY FOR A GUEST, which is the honest answer rather than a missing feature:
     * a match score in this app means "how well this fits YOUR travel profile",
     * and there is no profile behind an anonymous request. The caption's score
     * micro-label is conditional on this map having an entry, so a guest sees the
     * cost and the budget tier and nothing invented.
     *
     * Read from `RecommendationService` rather than reimplemented, so the number on
     * the stage is the same number the recommendations page and the itinerary
     * generator use. That service's own rule applies unchanged: only destinations
     * this user LIKED and whose budget matches exactly, so a destination the user
     * has never swiped simply has no score rather than a low one.
     *
     * The limit is generous because the stage can reach any destination and the
     * default twenty would blank the label on most slides. It is still one query:
     * the service fetches the whole candidate set and takes afterwards.
     *
     * @return array<int, float>
     */
    private function stageMatchScores(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return app(RecommendationService::class)
            ->recommend($user, 500)
            ->mapWithKeys(fn (Destination $destination) => [
                $destination->id => (float) $destination->match_score,
            ])
            ->all();
    }

    private function hoursLabel(Destination $destination): ?string
    {
        if ($destination->hasPerDayHours()) {
            return $destination->perDayHoursLabel();
        }

        return $destination->formatHours();
    }

    private function openState(Destination $destination): string
    {
        $now = now();
        $today = $destination->hoursForDay($now);

        if ($today === null) {
            return $destination->hasAnyHours() ? 'closed_today' : 'unknown';
        }

        $opens = $this->toMinutes($today['open']);
        $closes = $this->toMinutes($today['close']);

        if ($opens === null || $closes === null || $opens >= $closes) {
            return 'unknown';
        }

        $minutes = $now->hour * 60 + $now->minute;

        if ($opens < $closes) {
            return $minutes >= $opens && $minutes < $closes ? 'open' : 'closed';
        }

        $evening = $closes <= $opens
            && ($minutes >= $opens || $minutes < $closes);

        return $evening ? 'open' : 'closed';
    }

    private function toMinutes(?string $time): ?int
    {
        if ($time === null) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $hour * 60 + $minute;
    }

    private function updatedOn(Destination $destination): ?string
    {
        $latest = $destination->last_verified_at
            ?? $destination->price_verified_at;

        return $latest?->copy()->format('j M Y');
    }
}
