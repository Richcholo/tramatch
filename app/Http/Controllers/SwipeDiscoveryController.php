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

    public function reset(): RedirectResponse
    {
        DestinationSwipe::where('user_id', Auth::id())->delete();

        return redirect()->route('discover.index')
            ->with('status', 'Your discovery deck was reset.');
    }
}