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
 * have arrived at; putting it on `/destinations` would put a fan of one
 * destination's photographs above a grid of all of them, which is a caption
 * for the wrong thing. So this is not a two-page seam to keep in step -- it is
 * the stage's only presenter, and there is no second copy of the order anywhere.
 *
 * THE STAGE HOLDS ONE DESTINATION. Its slides are that destination's own
 * photographs and nothing else. It was built to fan across the whole catalogue
 * first, which sounds like the richer idea and is the wrong one on this route:
 * arriving somewhere and being shown a photograph of somewhere else, under this
 * place's caption, is the failure the caption's one-slide rule exists to prevent
 * and it cannot prevent it from across a destination boundary.
 *
 * THE ORDER IS THE MODEL'S. The hero photograph first, then the uploads in
 * `sort_order`. See `CarouselSlide` for why a slide is a photograph and not a
 * destination.
 *
 * `sort_order` ties across every existing row until somebody curates it, which is
 * why the secondary sort is `name` and not nothing: without it two requests in the
 * same page load could disagree about the order, and the caption would then
 * name one destination over another's photograph.
 *
 * THE ACTIVE INDEX IS DERIVED FROM THE URL, never from component state -- and it
 * is always 0, because the hero photograph is always first. That is what makes a
 * hard page load land in the same visual state as an in-page advance, and it is
 * why deep links, Back/Forward and a shared link all replay correctly. It also
 * means arriving on a destination with three uploads lands on its hero rather
 * than in the middle of its own set.
 *
 * THE CACHE STILL HOLDS THE WHOLE CATALOGUE, AS ARRAYS OF SCALARS. Filtering it
 * to one slug in memory is free and a query per page render would not be. See
 * `CarouselSlide::toArray()` for why scalars: the cache driver is the database,
 * entries are serialised, and they survive a deploy -- an object graph would come
 * back as `__PHP_Incomplete_Class` and, behind this method's return type, take the
 * page down instead of costing one rebuild.
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
     * One cache key for the whole catalogue's photographs.
     *
     * Versioned, because the payload SHAPE is part of the contract: a payload
     * written by an older version is rejected as a miss rather than
     * reconstructed from the wrong fields. Bumping the constant is therefore part
     * of changing this class -- and it was bumped to `v2` when the stage stopped
     * fanning across every destination and the `destinations` order list went with
     * it, so a live `v1` entry is a miss rather than a silently wrong page.
     *
     * Still the WHOLE CATALOGUE even though a page only needs one destination's
     * photographs. Filtering an in-memory array of scalars to one slug is free; a
     * query per page render is not, and this is the read behind every destination
     * page.
     *
     * Scoped by the is_active/is_featured pair, because the archived and
     * un-featured filters are part of what defines the collection -- a key that
     * omitted them would serve an archived destination to a visitor for up to ten
     * minutes after an admin archived one.
     */
    private const CACHE_KEY = 'destinations.stage.v2';


    public function __construct(private readonly CacheRepository $cache) {}

/**
     * The cached payload, rebuilt on a miss or on an unexpected shape.
     *
     * ONE KEY, `slides`, and the check is that it is an array. A payload carrying
     * anything else is a MISS rather than something to trust with a shape guard
     * bolted on afterwards, which is why the key is versioned: `v2` dropped the
     * `destinations` list, and a `v1` entry would otherwise have been accepted
     * while quietly carrying a key nothing reads.
     *
     * @return array{slides: array<int, array<string, mixed>>}
     */
    private function payload(): array
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached) && isset($cached['slides']) && is_array($cached['slides'])) {
            return $cached;
        }

        $built = $this->build();

        $this->cache->put(self::CACHE_KEY, $built, now()->addMinutes(10));

        return $built;
    }

    /**
     * @return array{slides: array<int, array<string, mixed>>}
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

        foreach ($destinations as $destination) {
            $photographs = $destination->stagePhotographs();

            /*
             * EVERY DESTINATION CONTRIBUTES AT LEAST ONE SLIDE, and this is the
             * second time this decision has been got wrong in opposite directions.
             *
             * The first version skipped destinations with no photograph at all --
             * no panel, no placeholder. That was defensible when the stage was one
             * block on a page that still had a hero, fees and hours below it. It is
             * indefensible NOW, because the stage IS the page's opening: a
             * destination with no photograph rendered a screen with nothing in it.
             *
             * And the skip had a second, quieter cost. `is_featured` is meant to be
             * CURATION -- an owner choosing what appears in the stage -- and a
             * photograph-less destination was excluded by a photograph, not by a
             * decision. Curating 65 rows and watching some of them vanish because
             * nobody had uploaded a file yet is the opposite of what the flag says
             * it does.
             *
             * So a destination with no photograph gets ONE slide with an empty
             * `imageUrl`, and `stage-panel-inner` renders that as a TYPOGRAPHIC
             * PLATE -- name, place and standfirst set in type on a teal ground.
             * It is deliberately not dressed as a photograph: it never pretends to
             * be one, so it cannot read as a broken image, and the fan's rule that
             * every panel is a real photograph is not quietly broken either.
             *
             * THERE IS NO `destinations` KEY IN THE PAYLOAD ANY MORE. It held the
             * canonical destination order for `neighbours()`, which existed only
             * for the chevrons, which walked to other destinations. Nothing reads
             * it now, and 65 rows of it in the cache is a cost with no return.
             *
             * Its removal is why the cache key is v2. A v1 entry would otherwise be
             * accepted by the shape check, quietly, and this class would go on
             * carrying a key nothing reads for as long as the entry lived.
             */
            $slides[] = CarouselSlide::fromDestination($destination, $photographs[0] ?? '')->toArray();

            foreach (array_slice($photographs, 1) as $photograph) {
                $slides[] = CarouselSlide::fromDestination($destination, $photograph)->toArray();
            }
        }

        return ['slides' => $slides];
    }

    /**
     * This destination's photographs, in order, each stamped with its offset from
     * the active index.
     *
     * ONE DESTINATION, NOT THE CATALOGUE. This is the second time this has been
     * decided, and the first time was wrong in a way that was not obvious from the
     * code.
     *
     * The stage was built to fan across every featured destination, with the one
     * you arrived at in the middle and the chevrons walking to the others. That
     * reads as a feature in a spec and as a bug on the page: you arrive at Fort
     * Santiago and the fan is holding a photograph of Aguinaldo Shrine, with the
     * caption naming Fort Santiago and the backdrop showing Fort Santiago. Two
     * different destinations on screen at once, both of them correct, which is the
     * worst kind of wrong -- the kind nobody can report precisely.
     *
     * The fan is the page's opening. Its job is to show you THIS place: its hero
     * photograph, then its uploads, in the order the model already fixes. The
     * catalogue is one click away and it is a grid, which is the right shape for
     * choosing between places.
     *
     * THE PAYLOAD IS STILL THE WHOLE CATALOGUE, cached once. Filtering an
     * in-memory array of scalars down to one slug is free; a query per page render
     * would not be. The build is unchanged.
     *
     * OFFSETS RESTART AT ZERO. With one destination's own photographs, the hero is
     * the first and is therefore the active one -- which is what `data-stage-active`
     * has always said, and what the URL already implies. A three-photograph
     * destination is a fan of three: the hero centred, one upload either side.
     *
     * @return \Illuminate\Support\Collection<int, CarouselSlide>
     */
    public function slides(string $slug): Collection
    {
        return collect($this->payload()['slides'])
            ->filter(fn (array $row) => ($row['slug'] ?? null) === $slug)
            ->values()
            ->map(fn (array $row, int $index) => CarouselSlide::fromCache($row)?->withOffset($index))
            ->filter()
            ->values();
    }

/**
     * Forget the cached payload. Called whenever a destination or a photograph
     * changes.
     *
     * The whole catalogue is one entry, so this is the only invalidation there is:
     * there is no per-slug key to expire and no second model to remember.
     */
    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
