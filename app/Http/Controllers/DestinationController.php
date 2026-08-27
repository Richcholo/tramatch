<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DestinationController extends Controller
{
    public function index(Request $request): View
    {
        $destinations = Destination::query()
            ->with('tags')
            ->where('is_active', true)
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('province', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('budget_level'), function ($query) use ($request) {
                $query->where('budget_level', $request->string('budget_level')->toString());
            })
            ->latest()
            ->paginate(9)
            ->withQueryString();

        return view('destinations.index', compact('destinations'));
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