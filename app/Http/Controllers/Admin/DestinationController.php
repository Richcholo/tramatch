<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Destination;
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
use Illuminate\View\View;

class DestinationController extends Controller
{
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

    private function joinClosedDays(mixed $days): ?string
    {
        if (!is_array($days)) {
            return $days === null ? null : (string) $days;
        }

        $kept = array_values(array_intersect(Destination::daySlugs(), $days));

        return $kept === [] ? null : implode(',', $kept);
    }

    private function cleanDailyHours(mixed $hours): ?array
    {
        if (!is_array($hours)) {
            return null;
        }

        $clean = [];

        foreach (Destination::daySlugs() as $day) {
            $window = $hours[$day] ?? null;

            if (!is_array($window)) {
                continue;
            }

            if (($window['closed'] ?? false) === true) {
                $clean[$day] = ['closed' => true];

                continue;
            }

            $open = $this->normaliseTimeValue($window['open'] ?? null);
            $close = $this->normaliseTimeValue($window['close'] ?? null);

            if ($open === null || $close === null || $open === $close) {
                continue;
            }

            $clean[$day] = ['open' => $open, 'close' => $close];
        }

        return $clean === [] ? null : $clean;
    }

    private function normaliseTimeValue(mixed $value): ?string
    {
        $value = trim((string) $value);

        if (preg_match('/\A(\d{1,2}):(\d{2})(?::\d{2})?\z/', $value, $match) !== 1) {
            return null;
        }

        $hour = (int) $match[1];
        $minute = (int) $match[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function hasAnyHours(array $data): bool
    {
        if (($data['opening_time'] ?? null) || ($data['closing_time'] ?? null)) {
            return true;
        }

        if (!empty($data['closed_days'])) {
            return true;
        }

        foreach ((array) ($data['daily_hours'] ?? []) as $window) {
            if (is_array($window)) {
                return true;
            }
        }

        return false;
    }

    private function hoursChanged(Destination $destination, array $data): bool
    {
        return $this->hoursFingerprint($destination) !== $this->hoursFingerprint(
            $destination,
            $data
        );
    }

    private function hoursFingerprint(
        Destination $destination,
        array $data = []
    ): string {
        $daily = $data['daily_hours'] ?? $destination->daily_hours;

        if (is_string($daily)) {
            $daily = json_decode($daily, true);
        }

        $dailyHours = collect(Destination::daySlugs())
            ->mapWithKeys(function (string $day) use ($daily) {
                $window = is_array($daily) ? ($daily[$day] ?? null) : null;

                if ($window === null) {
                    return [$day => '-'];
                }

                if (($window['closed'] ?? false) === true) {
                    return [$day => 'closed'];
                }

                return [$day => ($window['open'] ?? '').'-'.($window['close'] ?? '')];
            })
            ->implode(',');

        $closedDays = $data['closed_days'] ?? $destination->closedDayList();

        return implode('|', [
            (string) ($data['opening_time'] ?? $destination->opening_time),
            (string) ($data['closing_time'] ?? $destination->closing_time),
            is_array($closedDays)
                ? implode(',', array_values(array_intersect(
                    Destination::daySlugs(),
                    $closedDays
                )))
                : implode(',', $destination->closedDayList()),
            $dailyHours,
            (string) ($data['operating_status'] ?? $destination->operating_status),
            (string) ($data['hours_kind'] ?? $destination->hours_kind),
            (string) ($data['hours_source_url'] ?? $destination->hours_source_url),
            (string) ($data['hours_note'] ?? $destination->hours_note),
        ]);
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

    private function validated(Request $request): array
    {
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
