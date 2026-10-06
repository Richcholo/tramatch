<?php

namespace App\Domain\Destinations;

/**
 * One carousel slide: a destination, reduced to what the stage renders.
 *
 * A DTO rather than the Eloquent model, so the component can be rendered in a
 * test with no database and no seeding, and so a view can never accidentally
 * reach for a column the stage does not use.
 *
 * `offset` is signed and relative to the ACTIVE slide: 0 is the active panel, -1
 * is the one to its left, +1 to its right, and anything beyond +/-2 is off-stage.
 *
 * NOT a table. The carousel is a view over `destinations`; this is the shape that
 * view takes.
 */
final readonly class CarouselSlide implements \Illuminate\Contracts\Support\Arrayable
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $province,
        public string $municipality,
        public string $imageUrl,
        public string $url,
        public int $offset = 0,
        public bool $isActive = false,
    ) {}

    public static function fromDestination(\App\Models\Destination $destination): self
    {
        return new self(
            slug: $destination->slug,
            name: $destination->name,
            province: (string) $destination->province,
            municipality: (string) $destination->municipality,
            // No photo is a normal row, not an error: the panel renders its
            // gradient stand-in and stays a real link.
            imageUrl: (string) ($destination->image_url ?? ''),
            url: route('destinations.show', $destination),
        );
    }

    /**
     * Rebuild from the cached array form.
     *
     * The pair of this and `toArray()`. `DestinationCarousel` caches ARRAYS
     * rather than objects, because the cache driver is the database and a
     * serialised object payload outlives the class that produced it: renaming or
     * reshaping `CarouselSlide` would leave entries that unserialize to
     * `__PHP_Incomplete_Class` and 500 every destinations page. Scalars cannot rot
     * that way.
     *
     * `url` is deliberately NOT rebuilt from the cached row -- it is derived from
     * the slug by `route()`, so a cached absolute URL would go stale the moment
     * APP_URL changed, which is exactly the class of bug the AGENTS.md deploy
     * notes warn about.
     */
    public static function fromArray(array $row): self
    {
        $slug = (string) ($row['slug'] ?? '');

        return new self(
            slug: $slug,
            name: (string) ($row['name'] ?? ''),
            province: (string) ($row['province'] ?? ''),
            municipality: (string) ($row['municipality'] ?? ''),
            imageUrl: (string) ($row['imageUrl'] ?? ''),
            url: $slug === '' ? '#' : route('destinations.show', $slug),
        );
    }

    /**
     * The chip label: the finest place name that is actually populated.
     */
    public function regionLabel(): string
    {
        if ($this->municipality !== '') {
            return $this->municipality.', '.$this->province;
        }

        return $this->province;
    }

    public function hasImage(): bool
    {
        return $this->imageUrl !== '';
    }

    public function withOffset(int $offset): self
    {
        return new self(
            slug: $this->slug,
            name: $this->name,
            province: $this->province,
            municipality: $this->municipality,
            imageUrl: $this->imageUrl,
            url: $this->url,
            offset: $offset,
            isActive: $offset === 0,
        );
    }

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'province' => $this->province,
            'municipality' => $this->municipality,
            'imageUrl' => $this->imageUrl,
            'url' => $this->url,
            'offset' => $this->offset,
            'isActive' => $this->isActive,
        ];
    }
}