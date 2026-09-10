<?php

namespace App\Http\Controllers;

use App\Models\Itinerary;
use App\Services\ItineraryGenerator;
use App\Services\RecommendationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return view('itineraries.show', compact('itinerary'));
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