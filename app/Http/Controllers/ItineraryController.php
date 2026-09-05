<?php

namespace App\Http\Controllers;

use App\Models\Itinerary;
use App\Services\ItineraryGenerator;
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

    public function create(): View|RedirectResponse
    {
        $profile = Auth::user()
            ->load('travelProfile.tags')
            ->travelProfile;

        if (!$profile) {
            return redirect()
                ->route('preferences.edit')
                ->with('status', 'Set your preferences before generating an itinerary.');
        }

        return view('itineraries.create', compact('profile'));
    }

    public function store(
        Request $request,
        ItineraryGenerator $generator
    ): RedirectResponse {
        $request->merge([
            'start_date' => $request->input('start_date') ?: null,
        ]);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'start_date' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:today',
            ],
        ]);

        try {
            $itinerary = $generator->create(
                Auth::user(),
                $validated
            );
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
        abort_unless($itinerary->user_id === Auth::id(), 403);
    }
}