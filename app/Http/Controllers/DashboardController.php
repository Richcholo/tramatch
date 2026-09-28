<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View|RedirectResponse
    {
        $user = Auth::user();

        if (!$user->travelProfile) {
            return redirect()->route('preferences.edit');
        }

        $recentLikes = $user->destinationSwipes()
            ->where('action', 'liked')
            ->with('destination')
            ->latest()
            ->take(3)
            ->get()
            ->map(fn ($swipe) => [
                'type' => 'Liked destination',
                'title' => $swipe->destination->name,
                'detail' => collect([
                    $swipe->destination->municipality,
                    $swipe->destination->province,
                ])->filter()->implode(', '),
                'url' => route('destinations.show', $swipe->destination),
                'date' => $swipe->updated_at,
            ]);

        $recentTrips = $user->itineraries()
            ->latest()
            ->take(3)
            ->get()
            ->map(fn ($itinerary) => [
                'type' => 'Saved trip',
                'title' => $itinerary->title,
                'detail' => collect([
                    $itinerary->area,
                    $itinerary->start_date?->format('M j, Y'),
                ])->filter()->implode(' · ') ?: 'Itinerary saved',
                'url' => route('itineraries.show', $itinerary),
                'date' => $itinerary->updated_at,
            ]);

        $recentActivity = $recentLikes
            ->concat($recentTrips)
            ->sortByDesc('date')
            ->take(5)
            ->values();

        return view('dashboard', compact('recentActivity'));
    }
}