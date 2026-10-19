<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Services\SwipeDeckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SwipeDiscoveryController extends Controller
{
    public function index(SwipeDeckService $deck): View|RedirectResponse
    {
        $user = Auth::user();

        if (!$user->travelProfile) {
            return redirect()->route('preferences.edit')
                ->with('status', 'Complete your travel profile before discovering destinations.');
        }

        return view('discover.index', [
            'cards' => $deck->cards($user),
            'emptyState' => $deck->emptyState($user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'destination_id' => ['required', 'integer', 'exists:destinations,id'],
            'action' => ['required', 'in:liked,passed'],
        ]);

        $destination = Destination::findOrFail($validated['destination_id']);
        $profile = Auth::user()->travelProfile;

        abort_unless($destination->is_active && $profile, 404);
        abort_unless($destination->budget_level === $profile->budget_level, 422);

        DestinationSwipe::updateOrCreate(
            [
                'user_id' => Auth::id(),
                'destination_id' => $destination->id,
            ],
            ['action' => $validated['action']]
        );

        return response()->json([
            'message' => 'Swipe saved.',
            'action' => $validated['action'],
        ]);
    }

    /**
     * Clearing the deck is CONFIRMATION, NOT A PAGE STATE.
     *
     * It used to flash `session('status')`, which the layout renders as a
     * banner inside `<main>`. On this route that was wrong twice over:
     *
     *  - `main` is deliberately `w-full` here so the deck can be one dark
     *    surface edge to edge, which means it carries **no padding**. The banner
     *    therefore rendered full-bleed -- flush against both viewport edges,
     *    hard under the nav bar, and outside the field every other element on
     *    this page sits in.
     *  - it is a **light** banner on a Deep Volcanic Teal page, so it read as a
     *    slab that had escaped the design rather than as part of it.
     *
     * Wrapping the banner in the page's own field was tried first and was the
     * wrong fix: it made the notice fit while keeping a large light rectangle
     * pushed the deck down the screen. Nothing about resetting a deck changes
     * the page it lands on.
     *
     * `toast_message` is the key the existing `<x-flash-toast />` component
     * reads, and `discover/index.blade.php` includes it. Bottom-right, dismisses
     * itself in 5s, and takes no vertical space. The two keys never fire
     * together, which a test pins.
     */
    public function reset(): RedirectResponse
    {
        DestinationSwipe::where('user_id', Auth::id())->delete();

        return redirect()->route('discover.index')
            ->with('toast_message', 'Your discovery deck was reset.');
    }
}