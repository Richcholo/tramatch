<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReviewController extends Controller
{
    public function store(Request $request, Destination $destination): RedirectResponse
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        Auth::user()->reviews()->updateOrCreate(
            ['destination_id' => $destination->id],
            [
                'rating' => $validated['rating'],
                'comment' => $validated['comment'],
                'status' => 'published',
            ]
        );

        return back()->with('status', 'Your review was saved.');
    }
}