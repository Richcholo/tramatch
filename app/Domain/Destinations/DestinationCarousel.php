<?php

namespace App\Domain\Destinations;

use App\Models\Destination;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * The one place that decides what the destinations carousel contains and in what
 * order.
 *
 * Both `/destinations` and `/destinations/{slug}` read this. That is the entire
 * continuity feature: the fan on a destination page IS the index fan with the
 * active index shifted, because it is the same query, the same order and the same
 * markup. A second presenter, or any sorting done in a Blade view, is how the two
 * pages start disagreeing.
 *
 * THE ACTIVE INDEX IS DERIVED FROM THE URL, never from component state. That is
 * what makes a hard page load land in the same visual state as an in-page advance,
 * and it is why deep links, Back/Forward and a shared link all replay correctly.
 */
class DestinationCarousel
{
    /**
     * How far the fan reaches either side of the active panel.
     *
     * Two, not more: the reference is a five-slot formation and a wider arc stops
     * reading as one. Anything beyond this is rendered off-stage so it can slide
     * in, which is what makes the advance a continuous cascade instead of five
     * cards teleporting.
     */
    public const REACH = 2;

    /**
     * One cache key for both pages.
     *
     * Scoped by the is_active/is_featured pair, because the archived filter is
     * part of what defines the collection -- a key that omitted it would serve
     * archived destinations to a visitor for up to ten minutes after an admin
     * archived one.
     */
    private const CACHE_KEY = 'destinations.carousel.v2';

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Every slide in canonical order. The single source of truth.
     *
     * @return \Illuminate\Support\Collection<int, CarouselSlide>
     */
    public function items(): \Illuminate\Support\Collection
    {
        return $this->cache->remember(
            self::CACHE_KEY,
            now()->addMinutes(10),
            function (): \Illuminate\Support\Collection {
                $destinations = Destination::query()
                    // sort_order first, then name. The name is what makes this
                    // deterministic: sort_order defaults to 0 for every existing
                    // row, so without a secondary key two requests could disagree
                    // on the order and the two fans would not match.
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->where('is_active', true)
                    ->where('is_featured', true)
                    ->get(['name', 'slug', 'province', 'municipality', 'image_url']);

                return $destinations
                    ->map(fn (Destination $d) => CarouselSlide::fromDestination($d))
                    ->values();
            }
        );
    }

    /**
     * Index of a slug in the canonical order, or 0 when it is not in the carousel.
     */
    public function activeIndex(?string $slug): int
    {
        if ($slug === null || $slug === '') {
            return 0;
        }

        $found = $this->items()->search(fn (CarouselSlide $slide) => $slide->slug === $slug);

        return $found === false ? 0 : (int) $found;
    }

    public function count(): int
    {
        return $this->items()->count();
    }

    /**
     * The slides, each stamped with its offset from the active index.
     *
     * Every slide is returned, not a five-element window. A window would have to
     * be refetched as the fan advances, and the whole point is that advancing is
     * a re-index rather than a re-layout -- the panels are already in the DOM and
     * only their offset changes. Offsets beyond +/-REACH render off-stage.
     *
     * Clamped, not wrapped. Wrapping sends a panel from far-left to near-right in
     * one step, which is a visible pop; clamping means the fan thins out at each
     * end of the collection the way a carousel should.
     *
     * @return \Illuminate\Support\Collection<int, CarouselSlide>
     */
    public function slides(?string $slug = null): \Illuminate\Support\Collection
    {
        $items = $this->items();
        $active = $this->activeIndex($slug);

        return $items->values()->map(
            fn (CarouselSlide $slide, int $index) => $slide->withOffset($index - $active)
        );
    }

    /**
     * The neighbours of a slide, for the chevrons and rel=prev / rel=next.
     *
     * @return array{prev: ?CarouselSlide, next: ?CarouselSlide}
     */
    public function neighbours(?string $slug = null): array
    {
        $items = $this->items();
        $count = $items->count();

        if ($count === 0) {
            return ['prev' => null, 'next' => null];
        }

        $index = $this->activeIndex($slug);

        return [
            // Null at each end rather than wrapping, so the chevron can be
            // disabled or omitted instead of silently cycling round.
            'prev' => $index > 0 ? $items[$index - 1] : null,
            'next' => $index < $count - 1 ? $items[$index + 1] : null,
        ];
    }

    /**
     * Forget the cached order. Called whenever a destination changes.
     */
    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}