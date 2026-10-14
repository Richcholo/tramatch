<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScheduleItineraryRequest;
use App\Http\Requests\UpdateItineraryRequest;
use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\Place;
use App\Services\ItineraryEditor;
use App\Services\ItineraryGenerator;
use App\Services\Pdf\ItineraryPdf;
use App\Services\RecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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
            // Handed over rather than referenced as \App\Models\Itinerary::MAX_DAYS
            // in the template. Every other view takes its values from the
            // controller, and this was the only fully-qualified model reference
            // anywhere under resources/views.
            'maxDays' => Itinerary::MAX_DAYS,
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

            // Nullable because the form always submits it, but a crafted
            // request may not: the generator then falls back to the saved
            // preference. Bounded here so the error names the field the
            // traveller actually filled in.
            'days' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.Itinerary::MAX_DAYS,
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

    public function show(Itinerary $itinerary, ItineraryEditor $editor): View
    {
        $this->ensureOwner($itinerary);

        $itinerary->load('days.items.destination', 'days.items.place');

        return view('itineraries.show', [
            'itinerary' => $itinerary,
            'addableDestinations' => $this->addableDestinations($itinerary),
            'addablePlaces' => $this->addablePlaces($itinerary),
            'unpricedStops' => $editor->countUnpricedStops($itinerary),
        ]);
    }

    /**
     * THE ITINERARY AS A PRINTABLE PDF.
     *
     * `ensureOwner()` runs first and unconditionally. The PDF carries the whole
     * trip — every stop, every fee, the running total — so ownership is not a
     * nicety on this route but the whole access control. 403, not 404: the
     * route-model binding has already resolved the itinerary by id from the
     * whole table, so answering 404 would claim a row nobody asked for does not
     * exist.
     *
     * Generated per request rather than cached. A stale PDF handed to a
     * traveller who has just reordered their day is worse than a slow one, and
     * there is exactly one owner to invalidate it for.
     */
    public function download(Itinerary $itinerary, ItineraryPdf $pdf): Response
    {
        $this->ensureOwner($itinerary);

        // Built ONCE. Rendering the document twice to get a length out of it
        // doubles the work and stamps `now()` twice, so the cover and the
        // colophon can disagree about the date on a trip that straddles
        // midnight — for a `Content-Length` header that was already in the body.
        $document = $pdf->build($itinerary);

        return response($document, 200, [
            'Content-Type' => 'application/pdf',
            /*
             * `attachment`, not `inline`: this is a file to keep, and naming it
             * in the header is what stops Safari from rendering it in a tab and
             * the traveller wondering why they cannot find their place.
             */
            'Content-Disposition' => 'attachment; filename="'.$this->pdfFilename($itinerary).'"',
            'Content-Length' => (string) strlen($document),
        ]);
    }

    /**
     * A filename that is safe as a header value AND recognisable in a folder.
     *
     * The title is free text, so it is slugged rather than quoted: a title
     * carrying a quote or a newline would let the traveller's own itinerary name
     * inject a second header. A route id is in the name too, because two trips
     * can easily share a title and a downloads folder cannot tell them apart.
     */
    private function pdfFilename(Itinerary $itinerary): string
    {
        $slug = Str::slug((string) $itinerary->title) ?: 'itinerary';

        return Str::limit($slug, 60, '').'-'.$itinerary->id.'.pdf';
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

    /**
     * Food and lodging in this trip's province that it does not visit yet.
     *
     * No liked rule: places have no swipes, so the picker and the editor rule
     * agree on active plus in-province instead. `ItineraryEditor` validates
     * additions against the same scope, for the same reason the destinations
     * picker reads the swipes the editor checks.
     */
    private function addablePlaces(Itinerary $itinerary): Collection
    {
        $inItinerary = $itinerary->days
            ->pluck('items')
            ->flatten()
            ->pluck('place_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id);

        return Place::query()
            ->where('is_active', true)
            ->where('province', $itinerary->area)
            ->when(
                $inItinerary->isNotEmpty(),
                fn ($query) => $query->whereNotIn('id', $inItinerary)
            )
            ->orderBy('type')
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

    /**
     * Append a day to a trip.
     *
     * A separate action rather than a field on the editor form, because a new
     * day needs a row that exists before any stop can be filed against it, and
     * the editor's payload is keyed by day id the browser can only know after
     * the row exists. One click, one day: adding days through a single form
     * would have to invent ids client-side and hand the ownership check a value
     * it is built to reject.
     */
    public function addDay(
        Itinerary $itinerary,
        ItineraryEditor $editor
    ): RedirectResponse {
        $this->ensureOwner($itinerary);

        try {
            $editor->addDay($itinerary);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['itinerary' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'itinerary' => 'The day could not be added. Please try again.',
            ]);
        }

        return redirect()
            ->route('itineraries.show', $itinerary)
            ->with('status', 'Day added. Add stops to it below.');
    }

    /**
     * Take a day off a trip.
     *
     * The counterpart to addDay(), and its own endpoint for the same reason: a
     * day is a stored row, so it cannot ride the draft the editor saves.
     *
     * The ownership check is repeated here even though the day is route-model
     * bound. Binding resolves an ItineraryDay from the whole table, so nothing
     * about it is specific to this trip, and a traveller who posted another
     * traveller's day id must be refused rather than have it deleted.
     */
    public function removeDay(
        Itinerary $itinerary,
        ItineraryDay $day,
        ItineraryEditor $editor
    ): RedirectResponse {
        $this->ensureOwner($itinerary);

        abort_unless(
            (int) $day->itinerary_id === (int) $itinerary->id,
            403
        );

        try {
            $editor->removeDay($itinerary, $day);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['itinerary' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'itinerary' => 'The day could not be removed. Please try again.',
            ]);
        }

        return redirect()
            ->route('itineraries.show', $itinerary)
            ->with('status', 'Day removed.');
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