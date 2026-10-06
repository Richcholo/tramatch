<?php

namespace App\Domain\Destinations;

use App\Models\Destination;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;

/**
 * The one place that decides what the destinations stage contains and in what
 * order.
 *
 * Read by `/destinations/{slug}`, which is the ONLY route that renders the
 * stage. The stage is a fan of photographs that opens on the destination you
 * have arrived at; putting it on `/destinations` would march 65 destinations'
 * photographs past above a grid of 9 of the same destinations. So this is not a
 * two-page seam to keep in step -- it is the stage's only presenter, and there is
 * no second copy of the order anywhere.
 *
 * THE ORDER IS DESTINATIONS, THE SLIDES ARE PHOTOGRAPHS. Canonical order is
 * `sort_order`, then `name`; each destination then contributes its photographs in
 * a fixed sequence -- its hero photograph first, then its uploads in `sort_order`.
 * See `CarouselSlide` for why the slide is a photograph.
 *
 * `sort_order` ties across every existing row until somebody curates it, which is
 * why the secondary sort is `name` and not nothing: without it two requests in
 * the same page load could disagree about the order, and the caption would then
 * name one destination over another's photograph.
 *
 * THE ACTIVE INDEX IS DERIVED FROM THE URL, never from component state. That is
 * what makes a hard page load land in the same visual state as an in-page advance,
 * and it is why deep links, Back/Forward and a shared link all replay correctly.
 * It resolves to the destination's FIRST photograph, so arriving on a destination
 * with three uploads lands on its hero rather than in the middle of its own set.
 *
 * THE CACHE HOLDS ARRAYS OF SCALARS. See `CarouselSlide::toArray()`. The cache
 * driver is the database, entries are serialised, and they survive a deploy -- an
 * object graph would come back as `__PHP_INcomplete_Class` and, behind this
 * method's return type, take the page down instead of costing one rebuild.
 */
class DestinationCarousel
{
    /**
     * How far the fan reaches either side of the active panel.
     *
     * Two, not more: the stage is a five-slot formation and a wider arc stops
     * reading as one. Anything beyond this is rendered off-stage so it can slide
     * in, which is what makes an advance a continuous cascade rather than five
     * panels teleporting.
     */
    public const REACH = 2;

    /**
     * One cache key for both pages.
     *
     * Versioned, because the payload SHAPE is part of the contract: a payload
     * written by an older version is rejected as a miss rather than
     * reconstructed from the wrong fields. Bumping the constant is therefore part
     * of changing this class.
     *
     * Scoped by the is_active/is_featured pair, because the archived and
     * un-featured filters are part of what defines the collection -- a key that
     * omitted them would serve an archived destination to a visitor for up to ten
     * minutes after an admin archived one.
     */
    private const CACHE_KEY = 'destinations.stage.v1';

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * The cached payload, rebuilt on a miss or on an unexpected shape.
     *
     * @return array{slides: array<int, array<string, mixed>>, destinations: array<int, array{slug: string, name: string, url: string}>}
     */
    private function payload(): array
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached)
            && isset($cached['slides'], $cached['destinations'])
            && is_array($cached['slides'])
            && is_array($cached['destinations'])
        ) {
            return $cached;
        }

        $built = $this->build();

        $this->cache->put(self::CACHE_KEY, $built, now()->addMinutes(10));

        return $built;
    }

    /**
     * @return array{slides: array<int, array<string, mixed>>, destinations: array<int, array{slug: string, name: string, url: string}>}
     */
    private function build(): array
    {
        $destinations = Destination::query()
            ->with(['tags:id,name', 'images:id,destination_id,path,sort_order'])
            ->where('is_active', true)
            ->where('is_featured', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get([
                'id', 'slug', 'name', 'province', 'municipality', 'image_url',
                'description', 'estimated_cost', 'budget_level',
            ]);

        $slides = [];
        $order = [];

        foreach ($destinations as $destination) {
            /*
             * A destination with no photograph at all contributes nothing -- not
             * even a placeholder card. The stage promises every panel is a real
             * photograph, and a flat colour block is not one. Leaving it out also
             * removes it from the neighbour order, so a chevron can never lead to a
             * page whose stage would have to describe some other destination.
             */
            $photographs = $this->photographsFor($destination);

            if ($photographs === []) {
                continue;
            }

            $order[] = [
                'slug' => $destination->slug,
                'name' => $destination->name,
                'url' => route('destinations.show', $destination),
            ];

            foreach ($photographs as $photograph) {
                $slides[] = CarouselSlide::fromDestination($destination, $photograph)->toArray();
            }
        }

        return ['slides' => $slides, 'destinations' => $order];
    }

    /**
     * A destination's photographs, hero first, then uploads in their own order.
     *
     * Hero first because that is the photograph a traveller arrives on, so it is
     * the one the stage must open on. Uploads after it, in the `sort_order` an
     * admin gave them.
     *
     * @return array<int, string>
     */
    private function photographsFor(Destination $destination): array
    {
        $photographs = [];

        $hero = trim((string) ($destination->image_url ?? ''));

        if ($hero !== '') {
            $photographs[] = $hero;
        }

        foreach ($destination->images as $image) {
            $path = trim((string) $image->path);

            if ($path !== '') {
                $photographs[] = $path;
            }
        }

        return array_values(array_unique($photographs));
    }

    /**
     * Every slide in canonical order, each stamped with its offset from the
     * active index.
     *
     * Every slide is returned, not a window. A window would have to be
     * re-rendered as the fan advances, and the whole point is that advancing is a
     * re-index rather than a re-layout -- the panels are already in the DOM and
     * only their offset changes. Offsets beyond the reach render off-stage.
     *
     * Clamped, not wrapped. Wrapping sends a panel from far-left to near-right in
     * one step, which is a visible pop; clamping means the fan thins out at each
     * end the way a carousel should.
     *
     * @return \Illuminate\Support\Collection<int, CarouselSlide>
     */
    public function slides(string $slug): Collection
    {
        $active = $this->activeIndex($slug);

        return collect($this->payload()['slides'])
            ->map(fn (array $row, int $index) => CarouselSlide::fromCache($row)?->withOffset($index - $active))
            ->filter()
            ->values();
    }

    /**
     * Index of a slug's first photograph in the canonical order, or 0 when the
     * destination is not in the stage.
     */
    public function activeIndex(string $slug): int
    {
        if ($slug === null || $slug === '') {
            return 0;
        }

        /*
         * Read straight off the cached rows rather than through `CarouselSlide`.
         * Rebuilding every slide to find one slug would be 260 objects built to
         * answer a question the array can answer, and this runs before every
         * render of both pages.
         */
        foreach ($this->payload()['slides'] as $index => $row) {
            if (($row['slug'] ?? null) === $slug) {
                return (int) $index;
            }
        }

        return 0;
    }

    public function count(): int
    {
        return count($this->payload()['slides']);
    }

    /**
     * The neighbouring DESTINATIONS, for the chevrons and rel=prev / rel=next.
     *
     * Neighbours rather than neighbouring slides, deliberately. A chevron is a
     * "go there" affordance for the journey, so from a destination with three
     * uploaded photographs it skips its own remaining two and offers the next
     * destination. The dots are the control for moving through photographs in
     * place, and having both is the point: one control walks the collection, the
     * other walks one destination.
     *
     * Null at each end rather than wrapping, so a chevron can be omitted instead
     * of silently cycling round.
     *
     * @return array{prev: ?array{slug: string, name: string, url: string}, next: ?array{slug: string, name: string, url: string}}
     */
    public function neighbours(string $slug): array
    {
        $order = $this->payload()['destinations'];

        if ($order === [] || $slug === null || $slug === '') {
            return ['prev' => null, 'next' => null];
        }

        $index = null;

        foreach ($order as $position => $entry) {
            if ($entry['slug'] === $slug) {
                $index = $position;

                break;
            }
        }

        if ($index === null) {
            return ['prev' => null, 'next' => null];
        }

        return [
            'prev' => $index > 0 ? $order[$index - 1] : null,
            'next' => $index < count($order) - 1 ? $order[$index + 1] : null,
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
