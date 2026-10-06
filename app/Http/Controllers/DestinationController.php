<?php

namespace App\Http\Controllers;

use App\Domain\Destinations\DestinationCarousel;
use App\Models\Destination;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DestinationController extends Controller
{
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
         * The shared carousel, centred on the first destination in canonical order.
         *
         * Autoplay IS on here. This is the browse surface: someone has not arrived
         * anywhere yet, so a slow advance is a suggestion to keep looking. It comes
         * with the mandatory pause control -- a self-starting loop longer than five
         * seconds without one fails WCAG 2.2.2.
         *
         * `?slide=<slug>` centres a specific destination so a link can point at
         * "the fan, showing X" and be bookmarkable.
         */
        $carousel = app(DestinationCarousel::class);
        $activeSlug = $request->filled('slide')
            ? $request->string('slide')->toString()
            : null;

        return view('destinations.index', [
            'destinations' => $destinations,
            'locations' => $locations,
            'carouselSlides' => $carousel->slides($activeSlug),
            'carouselActiveIndex' => $carousel->activeIndex($activeSlug),
            'carouselNeighbours' => $carousel->neighbours($activeSlug),
            'carouselAutoplay' => true,
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
         * The shared carousel, with THIS destination centred.
         *
         * `activeIndex` is derived from the route, which is what makes a hard page
         * load land in exactly the same visual state as an in-page advance -- so
         * deep links, Back/Forward and a shared link all replay correctly. The
         * order comes from the same presenter the index uses, so the fan here is
         * the index fan with the index shifted and the two cannot drift.
         *
         * Autoplay is off here. This is a destination someone has arrived at, not
         * a surface they are browsing, and a timer that keeps swapping the
         * backdrop out from under someone reading the description is worse than no
         * timer.
         */
        $carousel = app(DestinationCarousel::class);

        return view('destinations.show', [
            'carouselSlides' => $carousel->slides($destination->slug),
            'carouselActiveIndex' => $carousel->activeIndex($destination->slug),
            'carouselNeighbours' => $carousel->neighbours($destination->slug),
            'carouselAutoplay' => false,
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