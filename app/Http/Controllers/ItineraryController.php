<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScheduleItineraryRequest;
use App\Http\Requests\UpdateItineraryRequest;
use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\Itinerary;
use App\Services\ItineraryEditor;
use App\Services\ItineraryGenerator;
use App\Services\RecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ItineraryController extends Controller
{
    public function index(): View
    {
        $itineraries = Auth::user()
            ->itineraries()
            ->latest()
            ->paginate(10);

        return view('itineraries.index', compact('itineraries'));
    }

    public function create(
        Request $request,
        RecommendationService $recommendations
    ): View|RedirectResponse {
        $user = Auth::user();

        $profile = $user
            ->load('travelProfile.tags')
            ->travelProfile;

        if (!$profile) {
            return redirect()
                ->route('preferences.edit')
                ->with('status', 'Set your preferences before generating an itinerary.');
        }

        $matches = $recommendations->recommend($user, 1000);

        $areas = $matches
            ->pluck('province')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $area = trim(
            $request->string('area')->toString()
        );

        if ($area === '') {
            $area = trim(
                (string) session()->getOldInput(
                    'area',
                    $profile->preferred_region ?? ''
                )
            );
        }

        $destinations = $matches
            ->filter(function ($destination) use ($area) {
                return $area === ''
                    || $destination->province === $area;
            })
            ->values();

        $selectedIds = array_map(
            'intval',
            session()->getOldInput('destination_ids', [])
        );

        return view('itineraries.create', [
            'profile' => $profile,
            'areas' => $areas,
            'area' => $area,
            'destinations' => $destinations,
            'selectedIds' => $selectedIds,
        ]);
    }

    public function store(
        Request $request,
        ItineraryGenerator $generator,
        RecommendationService $recommendations
    ): RedirectResponse {
        $request->merge([
            'start_date' => $request->input('start_date') ?: null,
        ]);

        $validated = $request->validate([
            'title' => [
                'required',
                'string',
                'max:120',
            ],

            'area' => [
                'required',
                'string',
                'max:100',
            ],

            'start_date' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:today',
            ],

            'destination_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'destination_ids.*' => [
                'integer',
                'distinct',
                'exists:destinations,id',
            ],
        ]);

        $user = Auth::user();

        $selectedIds = collect($validated['destination_ids'])
            ->map(fn ($id) => (int) $id)
            ->values();

        $matches = $recommendations->recommend($user, 1000);

        $allowedIds = $matches
            ->filter(function ($destination) use ($validated) {
                return $destination->province === $validated['area'];
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $invalidIds = $selectedIds
            ->diff($allowedIds)
            ->values();

        if ($invalidIds->isNotEmpty()) {
            return back()
                ->withInput()
                ->withErrors([
                    'destination_ids' => 'One or more selected destinations are not available in the selected area.',
                ]);
        }

        $validated['destination_ids'] = $selectedIds
            ->values()
            ->all();

        try {
            $itinerary = $generator->create($user, $validated);
        } catch (RuntimeException $exception) {
            return back()
                ->withInput()
                ->withErrors([
                    'itinerary' => $exception->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->withErrors([
                    'itinerary' => 'The itinerary could not be generated. Please try again.',
                ]);
        }

        $warnings = $itinerary
            ->getAttribute('unscheduled_destinations')
            ?? [];

        if (!empty($warnings)) {
            session()->flash(
                'itinerary_warnings',
                $warnings
            );
        }

        return redirect()
            ->route('itineraries.show', $itinerary)
            ->with('status', 'Your itinerary was generated.');
    }

    public function show(Itinerary $itinerary): View
    {
        $this->ensureOwner($itinerary);

        $itinerary->load('days.items.destination');

        return view('itineraries.show', [
            'itinerary' => $itinerary,
            'addableDestinations' => $this->addableDestinations($itinerary),
        ]);
    }

    /**
     * Places the traveller liked that this itinerary does not visit yet.
     *
     * This asks the swipes directly rather than going through
     * RecommendationService, because that service only returns places which
     * also clear the travel profile. A place the traveller liked but which does
     * not score would then be addable but invisible, and the empty state would
     * claim they had liked nothing. ItineraryEditor validates additions against
     * the same swipes, so the picker and the rule that accepts a stop agree.
     */
    private function addableDestinations(Itinerary $itinerary): Collection
    {
        $inItinerary = $itinerary->days
            ->pluck('items')
            ->flatten()
            ->pluck('destination_id')
            ->map(fn ($id) => (int) $id);

        $likedIds = DestinationSwipe::query()
            ->where('user_id', $itinerary->user_id)
            ->where('action', 'liked')
            ->pluck('destination_id')
            ->map(fn ($id) => (int) $id);

        return Destination::query()
            ->where('is_active', true)
            ->whereIn('id', $likedIds)
            ->when(
                $inItinerary->isNotEmpty(),
                fn ($query) => $query->whereNotIn('id', $inItinerary)
            )
            ->orderBy('name')
            ->get();
    }

    public function update(
        UpdateItineraryRequest $request,
        Itinerary $itinerary,
        ItineraryEditor $editor
    ): RedirectResponse {
        $this->ensureOwner($itinerary);

        try {
            $editor->apply(
                $itinerary,
                $request->validated()['items'] ?? []
            );
        } catch (RuntimeException $exception) {
            return back()
                ->withInput()
                ->withErrors(['itinerary' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->withErrors([
                    'itinerary' => 'The itinerary could not be saved. Please try again.',
                ]);
        }

        return redirect()
            ->route('itineraries.show', $itinerary)
            ->with('status', 'Itinerary updated.');
    }

    /**
     * Recompute the draft's times and hand them back, without writing anything.
     * The browser is not trusted to reproduce the day window, the lunch block
     * and the travel gap, so it asks the server that owns those rules.
     */
    public function schedule(
        ScheduleItineraryRequest $request,
        Itinerary $itinerary,
        ItineraryEditor $editor
    ): JsonResponse {
        $this->ensureOwner($itinerary);

        return response()->json([
            'days' => $editor->previewReflow(
                $itinerary,
                $request->validated()['items'] ?? []
            ),
        ]);
    }

    public function destroy(Itinerary $itinerary): RedirectResponse
    {
        $this->ensureOwner($itinerary);

        $itinerary->delete();

        return redirect()
            ->route('itineraries.index')
            ->with('status', 'Itinerary deleted.');
    }

    public function complete(Itinerary $itinerary): RedirectResponse
    {
        $this->ensureOwner($itinerary);

        $itinerary->update([
            'is_completed' => !$itinerary->is_completed,
        ]);

        return back()->with('status', 'Itinerary status updated.');
    }

    private function ensureOwner(Itinerary $itinerary): void
    {
        abort_unless(
            $itinerary->user_id === Auth::id(),
            403
        );
    }
}