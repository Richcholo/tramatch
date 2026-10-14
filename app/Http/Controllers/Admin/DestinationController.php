<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesPostedHours;
use App\Http\Controllers\Controller;
use App\Models\Destination;
use App\Models\DestinationImage;
use App\Models\DestinationSwipe;
use App\Models\ItineraryItem;
use App\Models\Review;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DestinationController extends Controller
{
    /**
     * Posted-hours normalisation and the verification fingerprint live in the
     * trait, shared with the upcoming places controller — see
     * `HandlesPostedHours`. Everything else here is destination-specific
     * (gallery, tags, image upload).
     */
    use HandlesPostedHours;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $locations = Destination::query()
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

        $destinations = Destination::with('tags')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('province', 'like', "%{$search}%")
                        ->orWhere('municipality', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.destinations.index', compact('destinations', 'locations', 'search'));
    }

    public function create(): View
    {
        $tags = Tag::orderBy('name')->get();
        [$provinces, $municipalities] = $this->locationOptions();

        return view('admin.destinations.create', [
            'tags' => $tags,
            'provinces' => $provinces,
            'municipalities' => $municipalities,
            'closedDays' => [],
            'daySlugs' => Destination::daySlugs(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $tagIds = $data['tags'] ?? [];

        unset($data['tags']);

        $data['slug'] = Str::slug($data['name']);

        $data['closed_days'] = $this->joinClosedDays($data['closed_days'] ?? null);
        $data['daily_hours'] = $this->cleanDailyHours($data['daily_hours'] ?? null);

        if ($this->hasAnyHours($data)) {
            $data['last_verified_at'] = now();
        }

        if ($imageUrl = $this->storeUploadedImage($request)) {
            $data['image_url'] = $imageUrl;
        }

        $destination = Destination::create($data);

        $destination->tags()->sync($tagIds);

        /*
         * After the row exists, because the gallery hangs off its id. On create
         * the destination can never already be full, so nothing is refused here.
         */
        $this->storeGalleryImages($request, $destination);

        return redirect()
            ->route('admin.destinations.index')
            ->with('status', 'Destination created.');
    }

    public function edit(Destination $destination): View
    {
        $tags = Tag::orderBy('name')->get();
        $selectedTags = $destination->tags->pluck('id')->all();
        [$provinces, $municipalities] = $this->locationOptions();

        return view('admin.destinations.edit', [
            'destination' => $destination,
            'tags' => $tags,
            'selectedTags' => $selectedTags,
            'provinces' => $provinces,
            'municipalities' => $municipalities,
            'closedDays' => $destination->closedDayList(),
            'dailyHours' => $destination->normalisedDailyHours(),
            'daySlugs' => Destination::daySlugs(),
        ]);
    }

    public function update(
        Request $request,
        Destination $destination
    ): RedirectResponse {
        $data = $this->validated($request);
        $tagIds = $data['tags'] ?? [];

        unset($data['tags']);

        $data['slug'] = Str::slug($data['name']);

        $hoursChanged = $this->hoursChanged($destination, $data);

        $data['closed_days'] = $this->joinClosedDays($data['closed_days'] ?? null);
        $data['daily_hours'] = $this->cleanDailyHours($data['daily_hours'] ?? null);

        if ($hoursChanged) {
            $data['last_verified_at'] = now();
        }

        // Both captured before the update, while the row still holds the old
        // values. imagePath() returns null for a third-party URL, which is what
        // stops an external image being resolved to a path on our disk and
        // deleted.
        $previousImageUrl = $destination->image_url;
        $previousImagePath = $destination->imagePath();

        if ($imageUrl = $this->storeUploadedImage($request)) {
            $data['image_url'] = $imageUrl;
        } elseif ($request->boolean('remove_image')) {
            $data['image_url'] = null;
        }

        $destination->update($data);
        $destination->tags()->sync($tagIds);

        /*
         * Deletions before uploads, so that removing one and adding one in a
         * single save is the natural way to swap a photo rather than hitting the
         * cap. Doing it the other way round would refuse the replacement.
         */
        $this->deleteGalleryImages(
            $destination,
            (array) $request->input('delete_gallery_image', [])
        );

        $this->storeGalleryImages($request, $destination);

        /*
         * Compared against $previousImageUrl, not the refreshed attribute:
         * update() has already applied $data by this point, so comparing the
         * two against each other is always false and the old file is never
         * removed. Replaced photos accumulated on the disk forever.
         *
         * Deleted only after the row has committed, so a failed update cannot
         * orphan a file that is still referenced.
         */
        if (
            $previousImagePath
            && array_key_exists('image_url', $data)
            && $data['image_url'] !== $previousImageUrl
        ) {
            Storage::disk('public')->delete($previousImagePath);
        }

        return redirect()
            ->route('admin.destinations.index')
            ->with('status', 'Destination updated.');
    }

    /**
     * Store an uploaded photo and return the URL to put in image_url.
     *
     * Returns null when no file was sent, which is the ordinary case: an admin
     * editing a description must not silently lose the existing image. Callers
     * must therefore only assign to image_url when this is non-null.
     */
    private function storeUploadedImage(Request $request): ?string
    {
        $file = $request->file('image');

        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return null;
        }

        $path = $file->store(Destination::IMAGE_DIRECTORY, 'public');

        if (! $path) {
            return null;
        }

        /*
         * An absolute URL, deliberately.
         *
         * image_url is rendered raw -- src="{{ $destination->image_url }}" -- in
         * six views, and the CSV seeds it with absolute URLs. Writing a
         * storage-relative path would make the browser resolve it against
         * whatever the current path happens to be, so /destinations/boracay
         * would request /destinations/storage/destination-images/x.jpg.
         * Keeping the column meaning "the URL of the image" means no view has
         * to change and no migration is needed.
         *
         * Reachable through public/storage, which deploy.sh creates with `ln -s`
         * rather than artisan storage:link, since symlink() is disabled here.
         */
        return Storage::disk('public')->url($path);
    }


    public function archive(Destination $destination): RedirectResponse
    {
        $destination->update([
            'is_active' => false,
        ]);

        return back()->with(
            'status',
            'Destination archived and removed from the public catalog.'
        );
    }

    public function restore(Destination $destination): RedirectResponse
    {
        $destination->update([
            'is_active' => true,
        ]);

        return back()->with(
            'status',
            'Destination restored to the public catalog.'
        );
    }

    public function destroy(Destination $destination): RedirectResponse
    {
        DB::transaction(function () use ($destination) {
            Review::where(
                'destination_id',
                $destination->id
            )->delete();

            DestinationSwipe::where(
                'destination_id',
                $destination->id
            )->delete();

            ItineraryItem::where(
                'destination_id',
                $destination->id
            )->delete();

            $destination->tags()->detach();
            $destination->delete();
        });

        return redirect()
            ->route('admin.destinations.index')
            ->with('status', 'Destination permanently deleted.');
    }

    /**
     * Store newly uploaded carousel photos, up to the cap.
     *
     * Refuses the overflow with a validation error rather than dropping the
     * extra files: an admin who selects four and gets no warning has no way to
     * know the fourth was discarded. `MAX_PER_DESTINATION` is the cap and the
     * message names it.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile>  $files
     * @return array{stored: int, rejected: int}
     */
    private function storeGalleryImages(
        Request $request,
        Destination $destination
    ): array {
        $files = array_values(array_filter(
            (array) $request->file('gallery', []),
            fn ($file): bool => $file instanceof UploadedFile && $file->isValid()
        ));

        if ($files === []) {
            return ['stored' => 0, 'rejected' => 0];
        }

        $existing = $destination->images()->count();
        $room = max(0, DestinationImage::MAX_PER_DESTINATION - $existing);
        $accepted = array_slice($files, 0, $room);
        $rejected = count($files) - count($accepted);

        /*
         * Raised directly rather than through a `max:` rule, because `max` counts
         * the files in THIS request and not the room left on the destination. A
         * destination already holding two photos, given two more, passes
         * `max:3` -- and the third file would then be dropped by the slice above
         * with no message at all, which is the exact silent loss this is meant to
         * prevent.
         */
        if ($rejected > 0) {
            throw ValidationException::withMessages([
                'gallery' => 'This destination already has '.$existing.' of its '
                    .DestinationImage::MAX_PER_DESTINATION.' extra photos, so '
                    .$rejected.' of the '.count($files)
                    .' you selected could not be added. Remove one first.',
            ]);
        }

        foreach ($accepted as $file) {
            $path = $file->store(Destination::IMAGE_DIRECTORY, 'public');

            if (! $path) {
                continue;
            }

            $destination->images()->create([
                // An absolute URL, like image_url and DestinationImage::$path.
                // Every view renders it straight into src="...", so a relative
                // path would resolve against the current route.
                'path' => Storage::disk('public')->url($path),
                'sort_order' => DestinationImage::nextSortOrder($destination->id),
            ]);
        }

        return ['stored' => count($accepted), 'rejected' => $rejected];
    }

    /**
     * Delete carousel photos the admin unticked.
     *
     * Scoped to `$destination->images()` rather than looked up by id alone,
     * because an id posted in `delete_gallery_image[]` is otherwise an id an
     * admin could delete from any destination, including someone else's.
     *
     * The row is removed only after the file is gone, so a failed delete leaves a
     * row pointing at a file rather than a file nothing references.
     *
     * @param  array<int, mixed>  $ids
     */
    private function deleteGalleryImages(Destination $destination, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $deleted = 0;

        foreach ($destination->images()->whereIn('id', $ids)->get() as $image) {
            $path = $image->storagePath();

            if ($path) {
                Storage::disk('public')->delete($path);
            }

            $image->delete();
            $deleted++;
        }

        return $deleted;
    }

    private function validated(Request $request): array
    {
        $request->merge($this->withNormalisedTimes($request));

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'description' => [
                'required',
                'string',
            ],

            'province' => [
                'required',
                'string',
                'max:100',
            ],

            'municipality' => [
                'nullable',
                'string',
                'max:100',
            ],

            'latitude' => [
                'required',
                'numeric',
                'between:-90,90',
            ],

            'longitude' => [
                'required',
                'numeric',
                'between:-180,180',
            ],

            'budget_level' => [
                'required',
                'in:economy,mid-range,premium',
            ],

            'entrance_fee' => [
                'required',
                'numeric',
                'min:0',
            ],

            'estimated_cost' => [
                'required',
                'numeric',
                'min:0',
            ],

            'recommended_minutes' => [
                'required',
                'integer',
                'min:15',
                'max:1440',
            ],

            'opening_time' => [
                'nullable',
                'date_format:H:i',
            ],

            'closing_time' => [
                'nullable',
                'date_format:H:i',
            ],

            /*
             * The carousel photos. `image` above is the thumbnail and stays a
             * single file; this is the separate multi-file field, because the two
             * are used in different places -- the thumbnail on listing cards and
             * the deck, the gallery only on the destination's own page.
             *
             * The count is capped here as a first line of defence against a huge
             * multi-select, but the real limit is the room left on the
             * destination, which only storeGalleryImages() knows and which it
             * reports by name.
             */
            'gallery' => [
                'nullable',
                'array',
            ],

            'gallery.*' => [
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:4096',
            ],

            'delete_gallery_image' => [
                'nullable',
                'array',
            ],

            'delete_gallery_image.*' => [
                'integer',
            ],

            'closed_days' => [
                'nullable',
                'array',
            ],

            'closed_days.*' => [
                'string',
                'distinct',
                Rule::in(Destination::daySlugs()),
            ],

            'daily_hours' => [
                'nullable',
                'array',
            ],

            'daily_hours.*' => [
                'nullable',
                'array',
            ],

            'daily_hours.*.open' => [
                'nullable',
                'date_format:H:i',
            ],

            'daily_hours.*.close' => [
                'nullable',
                'date_format:H:i',
            ],

            'daily_hours.*.closed' => [
                'nullable',
                'boolean',
            ],

            'hours_source_url' => [
                'nullable',
                'url',
                'max:500',
            ],

            'hours_source_label' => [
                'nullable',
                'string',
                'max:150',
            ],

            'hours_note' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'operating_status' => [
                'nullable',
                Rule::in(['open', 'temporarily_closed', 'permanently_closed', 'unknown']),
            ],

            'hours_kind' => [
                'nullable',
                Rule::in(Destination::HOURS_KINDS),
            ],

            'image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:4096',
            ],

            /*
             * There is deliberately no image_url rule any more. Nothing posts
             * that field -- the admin form uploads a file -- so a rule for it
             * only invites the belief that the column is still editable here.
             * image_url is now written by this controller from the uploaded
             * file, and by the CSV seeder otherwise.
             */

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'tags' => [
                'required',
                'array',
                'min:1',
            ],

            'tags.*' => [
                'integer',
                'exists:tags,id',
            ],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function locationOptions(): array
    {
        $locations = Destination::query()
            ->get(['province', 'municipality']);

        return [
            $locations->pluck('province')->filter()->unique()->sort()->values(),
            $locations->pluck('municipality')->filter()->unique()->sort()->values(),
        ];
    }
}
