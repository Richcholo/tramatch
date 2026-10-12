<?php

namespace App\Http\Controllers;

use App\Domain\Destinations\DestinationCarousel;
use App\Models\Destination;
use App\Models\User;
use App\Services\RecommendationService;
use Illuminate\Http\RedirectResponse;
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
         * NO STAGE HERE, and that is a decision rather than an omission.
         *
         * The stage is a fanned carousel of photographs that opens on the
         * destination you have ARRIVED at, with that destination's own photographs
         * in the fan. Putting it on the listing would mean 65 destinations'
         * photographs marching past above a grid of 9 of the same destinations, and
         * the chevrons would be navigating away from a page whose whole purpose is
         * to let you choose between them.
         *
         * `?slide=` therefore does not exist on this route: there is no stage to
         * centre. It is on the stage's own route, where the URL already names the
         * active photograph's destination.
         */
        return view('destinations.index', compact('destinations', 'locations'));
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
         * The stage, with THIS destination centred.
         *
         * `activeIndex` is derived from the route, which is what makes a hard page
         * load land in exactly the same visual state as an in-page advance -- so
         * deep links, Back/Forward and a shared link all replay correctly.
         */
        $stage = $this->stage($destination->slug);

return view('destinations.show', [
            'stageSlides' => $stage['slides'],
            'stageActiveIndex' => $stage['activeIndex'],
            'stageMatchScores' => $stage['matchScores'],
            'stageInterval' => self::STAGE_INTERVAL_MS,
            'destination' => $destination,
            'neighbours' => $this->neighbours($destination),
            'quickhopPlaces' => $this->quickhopPlaces(),
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
     * QUICKHOP: type a place, land on it.
     *
     * A redirect rather than a partial-update list, and that is the whole
     * design. It works with JavaScript disabled, it is one route, it cannot
     * disagree with the listing's own search, and the URL it lands on is the
     * destination's own address -- so Back/Forward and a shared link replay the
     * place rather than re-running a query.
     *
     * A term that matches nothing goes to the listing with the search
     * preserved, which is the honest outcome: a visitor who typed something
     * that is not a place gets the filtered list rather than a dead end.
     */
    public function quickhop(Request $request): RedirectResponse
    {
        $term = trim($request->string('q')->toString());

        if ($term === '') {
            return redirect()->route('destinations.index');
        }

        $destination = $this->resolveQuickhop($term);

        if ($destination) {
            return redirect()->route('destinations.show', $destination);
        }

        return redirect()->route('destinations.index', ['search' => $term]);
    }

    /**
     * The best destination for a typed place, or null.
     *
     * ONE QUERY, and the ordering is match quality rather than a chain of four
     * round trips: an exact name beats an exact province or municipality, which
     * beats a name beginning with the term, which beats anything else
     * containing it. A visitor typing "Baguio" gets Baguio, not whichever
     * province happened to contain those six letters first.
     *
     * `%` AND `_` ARE ESCAPED. A visitor who types `%` is not asking for every
     * destination in Luzon, and unescaped it would match all 65 of them and
     * send them somewhere arbitrary -- which is a worse answer than none.
     */
    private function resolveQuickhop(string $term): ?Destination
    {
        $escaped = addcslashes($term, '%_\\');
        $contains = '%'.$escaped.'%';
        $startsWith = $escaped.'%';

        return Destination::query()
            ->where('is_active', true)
            ->where(function ($query) use ($contains) {
                $query->where('name', 'like', $contains)
                    ->orWhere('province', 'like', $contains)
                    ->orWhere('municipality', 'like', $contains);
            })
            ->orderByRaw(
                'CASE WHEN name = ? THEN 0 WHEN province = ? OR municipality = ? THEN 1 WHEN name LIKE ? THEN 2 ELSE 3 END',
                [$term, $term, $term, $startsWith]
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->first();
    }

    /**
     * What the quickhop box offers as suggestions.
     *
     * A native `<datalist>`, so the suggestions cost no JavaScript and no
     * request. Names come first because a visitor types a place far more often
     * than a province, and the list is deduplicated across all three columns so
     * a name and a province that share a word are offered once.
     *
     * @return array<int, string>
     */
    private function quickhopPlaces(): array
    {
        return Destination::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['name', 'province', 'municipality'])
            ->flatMap(fn (Destination $destination) => [
                $destination->name,
                $destination->province,
                $destination->municipality,
            ])
            ->map(fn ($place) => trim((string) $place))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The place before and the place after, in the catalogue's own order.
     *
     * THE ORDER IS `sort_order, name` -- NOT THE LISTING'S `latest()`. The
     * second reason is the load-bearing one: `latest()` sorts on `created_at`,
     * and a bulk CSV import writes many rows carrying the same timestamp, so
     * "the next place" would be effectively arbitrary and two requests in the
     * same page load could disagree about it. This is the same total order the
     * stage's payload is built on, so the strip and the carousel describe one
     * catalogue.
     *
     * WRAPS NOWHERE. The last place has no next, the first has no previous. A
     * Next that quietly returns you to the first place is a carousel's trick,
     * and here it would be a lie about there being somewhere after this.
     *
     * @return array{previous: ?Destination, next: ?Destination, position: int, total: int}
     */
    private function neighbours(Destination $destination): array
    {
        $ids = Destination::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('id')
            ->all();

        $total = count($ids);
        $key = array_search($destination->id, $ids, true);

        if ($key === false) {
            return ['previous' => null, 'next' => null, 'position' => 0, 'total' => $total];
        }

        return [
            'previous' => $key > 0 ? Destination::find($ids[$key - 1]) : null,
            'next' => $key < $total - 1 ? Destination::find($ids[$key + 1]) : null,
            'position' => $key + 1,
            'total' => $total,
        ];
    }

/**
     * Everything the destinations stage needs, for its one route.
     *
     * The stage lives on `/destinations/{slug}` ONLY, so this is a single call
     * rather than a shared seam. It is still one method because the three things it
     * resolves are three things the component cannot work without, and a view that
     * reaches for any of them directly is a second source of truth.
     *
     * `activeIndex` is always 0 and is passed as a literal rather than asked of
     * the carousel. There used to be an `activeIndex($slug)` method that searched
     * the whole catalogue for a slug's first photograph, and it was genuinely
     * non-trivial while the stage fanned across every destination. Now the slides
     * ARE one destination's own photographs in order, so the first one is the
     * active one by definition and a method that searched for it would be
     * re-deriving an index it cannot get wrong.
     *
     * There is no `neighbours` key any more either. The chevrons walked to the
     * neighbouring DESTINATION, which only meant anything when the fan did too;
     * they now move through this destination's photographs, like the dots, the
     * drag and the keyboard.
     *
     * @return array{slides: \Illuminate\Support\Collection, activeIndex: int, matchScores: array<int, float>}
     */
    private function stage(string $slug): array
    {
        return [
            'slides' => app(DestinationCarousel::class)->slides($slug),
            'activeIndex' => 0,
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
