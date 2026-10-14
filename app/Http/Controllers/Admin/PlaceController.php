<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesPostedHours;
use App\Http\Controllers\Controller;
use App\Models\Destination;
use App\Models\Place;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Food and lodging curation.
 *
 * The sibling of `Admin\DestinationController`, deliberately shaped like it:
 * index with search, create/store/edit/update, archive/restore, permanent
 * delete. An admin who learned one screen already knows this one.
 *
 * WHAT IS DIFFERENT, AND WHY:
 *
 *   - No tags, no gallery, no photo upload. A place has no `tags()` relation
 *     and no `image_url` column; copying those fields would write to columns
 *     that do not exist.
 *   - `source_url` is REQUIRED. A restaurant with no traceable page behind it
 *     does not get an entry, because nothing else here can vouch for the
 *     hours or the price band. The seeder refuses unsourced rows for the same
 *     reason; the form must not admit what the seeder would reject.
 *   - Prices are NULLABLE and stay null. A blank band means "not listed", and
 *     `price_verified_at` is only stamped when a figure is actually present.
 *     Inventing a tier-derived figure here would be the placeholder-cost
 *     failure the seeder was written to prevent.
 *   - Permanent delete is a single row delete. `place_id` nulls on delete at
 *     the schema level, so retiring a restaurant leaves the traveller's stop
 *     in place saying the place is gone — the exact loss
 *     `ItineraryEditor::removeDay()` refuses to make. No transaction, no
 *     cascade, nothing to keep in step.
 *
 * Posted-hours normalisation and the verification fingerprint come from
 * `HandlesPostedHours`, shared with destinations. Price verification has its
 * own small fingerprint below, because hours and prices are verified
 * separately — a re-checked menu must not restamp the hours, and vice versa.
 */
class PlaceController extends Controller
{
    use HandlesPostedHours;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $type = trim((string) $request->query('type'));

        if (! in_array($type, Place::TYPES, true)) {
            $type = '';
        }

        $places = Place::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('province', 'like', "%{$search}%")
                        ->orWhere('municipality', 'like', "%{$search}%")
                        ->orWhere('cuisine', 'like', "%{$search}%");
                });
            })
            ->when($type !== '', function ($query) use ($type) {
                $query->where('type', $type);
            })
            ->orderBy('province')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        // The coverage report IS the curation tool: per cluster, per tier, how
        // many food places and hotels exist and what is missing. It is computed
        // here rather than cached because an admin reads it right after saving,
        // and a stale report after a save is the exact lie that sends someone
        // sourcing a tier that is already covered.
        $coverage = collect(Place::CLUSTERS)
            ->mapWithKeys(function (array $definition, string $cluster) {
                $tiers = collect(array_keys(Destination::BUDGET_TIERS))
                    ->mapWithKeys(function (string $tier) use ($definition) {
                        $counts = Place::clusterCoverage($definition['province'])[$tier];

                        return [$tier => [
                            'counts' => $counts,
                            'readiness' => Place::clusterReadiness(
                                $definition['province'],
                                $tier
                            ),
                        ]];
                    });

                return [$cluster => [
                    'label' => $definition['label'],
                    'province' => $definition['province'],
                    'tiers' => $tiers,
                ]];
            });

        return view('admin.places.index', compact(
            'places',
            'search',
            'type',
            'coverage'
        ));
    }

    public function create(): View
    {
        [$provinces, $municipalities] = $this->locationOptions();

        return view('admin.places.create', [
            'provinces' => $provinces,
            'municipalities' => $municipalities,
            'closedDays' => [],
            'daySlugs' => Destination::daySlugs(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $data['slug'] = Str::slug($data['name']);

        $data['closed_days'] = $this->joinClosedDays($data['closed_days'] ?? null);
        $data['daily_hours'] = $this->cleanDailyHours($data['daily_hours'] ?? null);
        $data['meal_slots'] = $this->cleanMealSlots($data['meal_slots'] ?? null);

        if ($this->hasAnyHours($data)) {
            $data['last_verified_at'] = now();
        }

        if ($data['price_min'] !== null) {
            $data['price_verified_at'] = now();
        }

        $place = Place::create($data);

        return redirect()
            ->route('admin.places.index')
            ->with('status', $place->name.' added to the catalogue.');
    }

    public function edit(Place $place): View
    {
        [$provinces, $municipalities] = $this->locationOptions();

        return view('admin.places.edit', [
            'place' => $place,
            'provinces' => $provinces,
            'municipalities' => $municipalities,
            'closedDays' => $place->closedDayList(),
            'dailyHours' => $place->normalisedDailyHours(),
            'daySlugs' => Destination::daySlugs(),
        ]);
    }

    public function update(Request $request, Place $place): RedirectResponse
    {
        $data = $this->validated($request);

        $data['slug'] = Str::slug($data['name']);

        $hoursChanged = $this->hoursChanged($place, $data);
        $priceChanged = $this->priceChanged($place, $data);

        $data['closed_days'] = $this->joinClosedDays($data['closed_days'] ?? null);
        $data['daily_hours'] = $this->cleanDailyHours($data['daily_hours'] ?? null);
        $data['meal_slots'] = $this->cleanMealSlots($data['meal_slots'] ?? null);

        if ($hoursChanged) {
            $data['last_verified_at'] = now();
        }

        if ($priceChanged && $data['price_min'] !== null) {
            $data['price_verified_at'] = now();
        }

        $place->update($data);

        return redirect()
            ->route('admin.places.index')
            ->with('status', $place->name.' updated.');
    }

    public function archive(Place $place): RedirectResponse
    {
        $place->update([
            'is_active' => false,
        ]);

        return back()->with(
            'status',
            'Place archived and removed from planning.'
        );
    }

    public function restore(Place $place): RedirectResponse
    {
        $place->update([
            'is_active' => true,
        ]);

        return back()->with(
            'status',
            'Place restored to planning.'
        );
    }

    public function destroy(Place $place): RedirectResponse
    {
        // No transaction and no cascade. `place_id` nulls on delete, so every
        // itinerary stop that pointed here survives with its type and note
        // intact — see the class docblock. There are no tag pivots, swipes or
        // reviews on places to clean up.
        $name = $place->name;

        $place->delete();

        return redirect()
            ->route('admin.places.index')
            ->with('status', $name.' permanently deleted.');
    }

    /**
     * Whether the price a traveller would be quoted changed.
     *
     * Compared as normalised figures, not raw input: "250" and "250.00" are the
     * same price, and restamping the verification over a formatting difference
     * would make the date meaningless. A price that was cleared (figure to
     * null) counts as changed but stamps nothing — there is no figure to
     * vouch for.
     */
    private function priceChanged(Place $place, array $data): bool
    {
        $figure = fn ($value): string => $value === null
            ? '-'
            : number_format((float) $value, 2);

        return $figure($place->price_min) !== $figure($data['price_min'] ?? null)
            || $figure($place->price_max) !== $figure($data['price_max'] ?? null)
            || (string) ($place->price_basis ?? '') !== (string) ($data['price_basis'] ?? '');
    }

    /**
     * A meal-slot list that only contains real meals.
     *
     * Same contract as the model's `normalisedMealSlots()`: an unrecognised
     * slug is dropped rather than stored, because a bad checkbox value must
     * not invent a meal. Kept here rather than only on the model so the stored
     * value is already clean and `old()` flashes back something renderable.
     */
    private function cleanMealSlots(mixed $slots): ?array
    {
        if (! is_array($slots)) {
            return null;
        }

        $kept = array_values(array_unique(array_filter(array_map(
            fn ($slot) => strtolower(trim((string) $slot)),
            $slots
        ), fn (string $slot): bool => in_array($slot, Place::MEAL_SLOTS, true))));

        return $kept === [] ? null : $kept;
    }

    private function validated(Request $request): array
    {
        $request->merge($this->withNormalisedTimes($request));

        // A blank price cell arrives as an empty string, which `numeric` would
        // reject. Null it first so `nullable` can do its job — and so a cleared
        // figure round-trips back to "Price not listed" instead of failing the
        // save that was trying to remove it.
        foreach (['price_min', 'price_max'] as $field) {
            if (trim((string) $request->input($field, '')) === '') {
                $request->merge([$field => null]);
            }
        }

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'type' => [
                'required',
                Rule::in(Place::TYPES),
            ],

            'cluster' => [
                'nullable',
                Rule::in(array_keys(Place::CLUSTERS)),
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

            'address' => [
                'nullable',
                'string',
                'max:500',
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

            'cuisine' => [
                'nullable',
                'string',
                'max:100',
            ],

            'meal_slots' => [
                'nullable',
                'array',
            ],

            'meal_slots.*' => [
                'string',
                'distinct',
                Rule::in(Place::MEAL_SLOTS),
            ],

            'price_min' => [
                'nullable',
                'numeric',
                'min:0',
                // A maximum without a minimum is a typo, not a price — the
                // seeder rejects the same shape rather than storing half a
                // band. `gte` alone cannot catch it: comparing against null
                // is not a comparison.
                'required_with:price_max',
            ],

            'price_max' => [
                'nullable',
                'numeric',
                'min:0',
                'gte:price_min',
            ],

            'price_basis' => [
                'nullable',
                Rule::in(Place::PRICE_BASES),
            ],

            'recommended_minutes' => [
                'required',
                'integer',
                // Zero is a real answer for lodging: a check-in is not a visit.
                // The seeder defaults meals to 60 for the same reason the form
                // floor is 0 and not 15 — see Place::costFor().
                'min:0',
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

            'source_url' => [
                'required',
                'url',
                'max:255',
            ],

            'source_label' => [
                'nullable',
                'string',
                'max:150',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function locationOptions(): array
    {
        $locations = Place::query()
            ->get(['province', 'municipality']);

        return [
            $locations->pluck('province')->filter()->unique()->sort()->values(),
            $locations->pluck('municipality')->filter()->unique()->sort()->values(),
        ];
    }
}
