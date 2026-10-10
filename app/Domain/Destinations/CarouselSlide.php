<?php

namespace App\Domain\Destinations;

use App\Models\Destination;

/**
 * One stage slide: a PHOTOGRAPH, and the destination it was taken at.
 *
 * THE SLIDE IS A PHOTOGRAPH, NOT A DESTINATION. That is the decision this class
 * exists to record, and it is worth being explicit about why, because the
 * obvious design -- one slide per destination, five destinations in the fan --
 * leaves an admin's uploaded photos with nowhere to live.
 *
 * A destination has a hero photograph (`destinations.image_url`) and up to three
 * uploaded extras (`destination_images`). Both are photographs OF that
 * destination, so both belong in the stage. Flattening the destinations into
 * consecutive slides means:
 *
 *   - the fan really does carry every uploaded photo, instead of the admin's
 *     work quietly vanishing from the public page when the stage arrived;
 *   - the hero photograph stops being a separate full-bleed block competing with
 *     the stage, and becomes the active slide instead;
 *   - the caption has something genuinely to be careful about. Because slides
 *     from DIFFERENT destinations sit next to each other, a caption built from
 *     two slides would show one destination's name over another's money. Hence
 *     every caption field is read off ONE slide, never off two.
 *
 * A DTO rather than the Eloquent model, so the component can be rendered in a
 * test with no database, and so a view cannot accidentally reach for a column
 * the stage does not use.
 *
 * `offset` is signed and relative to the ACTIVE slide: 0 is the active panel, -1
 * is the one to its left, +1 to its right, and anything beyond the reach is
 * off-stage.
 *
 * NOT a table. The stage is a view over `destinations` plus `destination_images`;
 * this is the shape that view takes.
 */
final readonly class CarouselSlide implements \Illuminate\Contracts\Support\Arrayable
{
    public function __construct(
        public int $destinationId,
        public string $slug,
        public string $name,
        public string $province,
        public string $municipality,
        public string $imageUrl,
        public string $url,
        public string $kicker,
        public string $standfirst,
        public ?float $estimatedCost,
        public string $estimatedCostDisplay,
        public string $budgetLevel,
        public int $budgetStep,
        public int $offset = 0,
        public bool $isActive = false,
    ) {}

    public static function fromDestination(
        Destination $destination,
        string $imageUrl,
        int $offset = 0
    ): self {
        return new self(
            destinationId: $destination->id,
            slug: $destination->slug,
            name: $destination->name,
            province: (string) $destination->province,
            municipality: (string) $destination->municipality,
            imageUrl: $imageUrl,
            url: route('destinations.show', $destination),
            // See `kicker()` for why this is a tag and not a region.
            kicker: $destination->kicker(),
            standfirst: $destination->standfirst(),
            estimatedCost: is_numeric($destination->estimated_cost)
                ? (float) $destination->estimated_cost
                : null,
            estimatedCostDisplay: self::peso($destination->estimated_cost),
            budgetLevel: self::budgetWord($destination->budget_level),
            budgetStep: self::budgetStep($destination->budget_level),
            offset: $offset,
            isActive: $offset === 0,
        );
    }

    /**
     * A destination has no `region` column, and this DTO is not where one should
     * grow: a region is derivable from the province (PSGC groups the 65 provinces
     * into the seven Luzon regions), but that mapping belongs in a seeder with a
     * source behind it, not inferred in a view.
     *
     * Meanwhile the province ALREADY appears twice in this caption -- as the
     * second line of the heading and again in the place line -- so using it as
     * the gold kicker too would print it three times.
     *
     * The first interest tag is the remaining editorial label that is real data
     * rather than invented copy, and it reads the way a kicker should.
     */
    public function hasKicker(): bool
    {
        return $this->kicker !== '';
    }

    public function hasImage(): bool
    {
        return $this->imageUrl !== '';
    }

    public function withOffset(int $offset): self
    {
        return new self(
            destinationId: $this->destinationId,
            slug: $this->slug,
            name: $this->name,
            province: $this->province,
            municipality: $this->municipality,
            imageUrl: $this->imageUrl,
            url: $this->url,
            kicker: $this->kicker,
            standfirst: $this->standfirst,
            estimatedCost: $this->estimatedCost,
            estimatedCostDisplay: $this->estimatedCostDisplay,
            budgetLevel: $this->budgetLevel,
            budgetStep: $this->budgetStep,
            offset: $offset,
            isActive: $offset === 0,
        );
    }

    /**
     * `₱1,250` rather than `₱1,250.00`.
     *
     * The stage shows a peso figure in a micro-label, not an invoice, and the
     * body page already prints the two-decimal form twice. Trimming the cents
     * also stops the number from shifting width while the count-up animation
     * runs -- the label has tabular numerals precisely so it does not reflow, and
     * a variable number of decimals would undo that.
     */
    public static function peso(mixed $amount): string
    {
        if (! is_numeric($amount)) {
            return '₱—';
        }

        return '₱'.number_format((float) $amount, 0);
    }

    /**
     * The budget tier as a word, so the tier is never communicated by colour or
     * by position in an indicator alone.
     */
    public static function budgetWord(?string $level): string
    {
        return match ($level) {
            'economy' => 'Economy',
            'mid-range' => 'Mid-range',
            'premium' => 'Premium',
            default => 'Unrated',
        };
    }

    /**
     * Which of the three indicator steps this destination's tier fills.
     *
     * `budget_level` is an EXACT match in this project, not a ranking of three
     * levels of spend between travellers -- see `Destination::BUDGET_TIERS`. The
     * indicator is therefore a position in the tier list, which is what it can
     * honestly say, and the word beside it is what actually carries the meaning.
     */
    public static function budgetStep(?string $level): int
    {
        return match ($level) {
            'economy' => 1,
            'mid-range' => 2,
            'premium' => 3,
            default => 0,
        };
    }

    /**
     * Flattened to scalars only, because this is what goes in the cache.
     *
     * NOT the object. The cache driver here is the database, so entries are
     * serialised and SURVIVE A DEPLOY: an object graph outlives the class that
     * built it, unserialises to `__PHP_INcomplete_Class`, and behind a strict
     * return type takes the page down with an exception rather than a cache miss.
     * Arrays of scalars survive the same round trip intact.
     */
    public function toArray(): array
    {
        return [
            'destinationId' => $this->destinationId,
            'slug' => $this->slug,
            'name' => $this->name,
            'province' => $this->province,
            'municipality' => $this->municipality,
            'imageUrl' => $this->imageUrl,
            'url' => $this->url,
            'kicker' => $this->kicker,
            'standfirst' => $this->standfirst,
            'estimatedCost' => $this->estimatedCost,
            'estimatedCostDisplay' => $this->estimatedCostDisplay,
            'budgetLevel' => $this->budgetLevel,
            'budgetStep' => $this->budgetStep,
        ];
    }

    /**
     * Rebuild from a cached row.
     *
     * Returns null for anything that is not the exact shape written above, which
     * the caller treats as a cache MISS rather than an error. That is the
     * difference between a stale entry costing one rebuild and a stale entry
     * producing a 500.
     *
     * @param  mixed  $row
     */
    public static function fromCache($row): ?self
    {
        if (! is_array($row)) {
            return null;
        }

        foreach ([
            'destinationId', 'slug', 'name', 'province', 'municipality',
            'imageUrl', 'url', 'kicker', 'standfirst', 'estimatedCost', 'estimatedCostDisplay',
            'budgetLevel', 'budgetStep',
        ] as $key) {
            if (! array_key_exists($key, $row)) {
                return null;
            }
        }

        if (! is_int($row['destinationId']) || ! is_int($row['budgetStep'])) {
            return null;
        }

        if ($row['estimatedCost'] !== null && ! is_float($row['estimatedCost']) && ! is_int($row['estimatedCost'])) {
            return null;
        }

        return new self(
            destinationId: $row['destinationId'],
            slug: (string) $row['slug'],
            name: (string) $row['name'],
            province: (string) $row['province'],
            municipality: (string) $row['municipality'],
            imageUrl: (string) $row['imageUrl'],
            url: (string) $row['url'],
            kicker: (string) $row['kicker'],
            standfirst: (string) $row['standfirst'],
            estimatedCost: $row['estimatedCost'] === null ? null : (float) $row['estimatedCost'],
            estimatedCostDisplay: (string) $row['estimatedCostDisplay'],
            budgetLevel: (string) $row['budgetLevel'],
            budgetStep: $row['budgetStep'],
        );
    }
}
