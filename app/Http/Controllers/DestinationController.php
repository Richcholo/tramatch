<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DestinationController extends Controller
{
<<<<<<< Updated upstream
    public function index(Request $request): View
=======
    /**
     * How long a slide holds before autoplay advances, in milliseconds.
     *
     * Not the four seconds a carousel is usually given. The stage's own motion is
     * 620ms of panel travel and a 700-900ms backdrop cross-fade, and a dwell
     * shorter than the sum of those reads as an interruption rather than as
     * rhythm -- the visitor spends longer watching the transition than reading
     * the caption. Seven seconds is long enough to read the caption and the
     * standfirst under it.
     *
     * Rendered into the markup as `data-stage-interval`, so the script and the
     * view have one number to agree with.
     */
     private const STAGE_INTERVAL_MS = 7000;
     private const DESTINATIONS_PER_PAGE = 9;

     public function index(Request $request): View
>>>>>>> Stashed changes
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'in:6,9'],
        ]);
        $perPage = (int) ($validated['per_page'] ?? self::DESTINATIONS_PER_PAGE);

        $locations = Destination::query()
            ->where('is_active', true)
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

        $destinations = Destination::query()
            ->with('tags')
            ->where('is_active', true)
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('province', 'like', "%{$search}%")
                        ->orWhere('municipality', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('budget_level'), function ($query) use ($request) {
                $query->where('budget_level', $request->string('budget_level')->toString());
            })
            ->latest()
            ->paginate($perPage)
            ->onEachSide(1)
            ->withQueryString();

<<<<<<< Updated upstream
        return view('destinations.index', compact('destinations', 'locations'));
=======
        /*
         * NO STAGE HERE, and that is a decision rather than an omission.
         *
         * The stage is a fanned carousel of photographs that opens on the
         * destination you have ARRIVED at, with that destination's own photographs
         * in the fan. Putting it on the listing would mean 65 destinations'
         * photographs marching past above a grid of 9 of the same destinations, and
         * the chevrons would be navigating away from a page whose whole purpose is
         * to let you choose between them.
         *
         * `?slide=` therefore does not exist on this route: there is no stage to
         * centre. It is on the stage's own route, where the URL already names the
         * active photograph's destination.
         */
        return view('destinations.index', compact('destinations', 'locations', 'perPage'));
>>>>>>> Stashed changes
    }

    public function show(Destination $destination): View
    {
        abort_unless($destination->is_active, 404);

        $destination->load([
            'tags',
            'reviews' => fn ($query) => $query
                ->where('status', 'published')
                ->with('user')
                ->latest(),
        ]);

        return view('destinations.show', compact('destination'));
    }
}