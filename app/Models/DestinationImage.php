<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One extra photo on a destination's page.
 *
 * The thumbnail is on `destinations.image_url` and is deliberately not modelled
 * here: it is what the index, the deck and the map popup all read, and moving it
 * would mean a data migration and six view changes to achieve nothing.
 *
 * Like the thumbnail, `path` holds an absolute URL rather than a storage-relative
 * path, because every view renders it straight into `src="..."`. A relative path
 * would resolve against the current route, so /destinations/boracay would
 * request /destinations/storage/destination-images/x.jpg. See
 * Destination::imagePath() for the ownership rule that decides whether a file is
 * ours to delete.
 */
class DestinationImage extends Model
{
    /**
     * How many extra photos a destination can carry.
     *
     * Enforced here rather than by a unique index on (destination_id, sort_order)
     * because reordering would then collide, and because the cap is really about
     * the upload handler: a fourth file has to be refused with a message, not
     * dropped by a constraint violation that surfaces as a 500.
     */
    public const MAX_PER_DESTINATION = 3;

    protected $fillable = [
        'destination_id',
        'path',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    /**
     * The next free slot, so a new upload lands after the existing ones.
     *
     * Starts at 0 for an empty destination. Note `max()` returns null rather
     * than 0 when there are no rows, and casting that null to int gives 0 too --
     * which would collide with the first upload's slot, so the null case is
     * handled explicitly instead of left to the cast.
     */
    public static function nextSortOrder(int $destinationId): int
    {
        $highest = static::query()
            ->where('destination_id', $destinationId)
            ->max('sort_order');

        return $highest === null ? 0 : (int) $highest + 1;
    }

    /**
     * Where the file lives, relative to the `public` disk, or null when this row
     * points somewhere that is not ours.
     *
     * A third-party URL must never resolve to a path on our own disk and be
     * deleted, which is the same rule Destination::imagePath() exists to express.
     */
    public function storagePath(): ?string
    {
        $base = rtrim(Storage::disk('public')->url(''), '/');

        if (! str_starts_with($this->path, $base.'/'.Destination::IMAGE_DIRECTORY.'/')) {
            return null;
        }

        return substr($this->path, strlen($base) + 1);
    }
}
