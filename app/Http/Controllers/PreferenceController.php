<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PreferenceController extends Controller
{
    public function edit(): View
    {
        $user = Auth::user();
        $profile = $user->travelProfile;
        $tags = Tag::orderBy('name')->get();
        $selectedWeights = $profile
            ? $profile->tags->pluck('pivot.weight', 'id')->map(fn ($weight) => (int) $weight)->all()
            : [];

        return view('preferences.edit', compact('profile', 'tags', 'selectedWeights'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'budget_level' => ['required', 'in:economy,mid-range,premium'],
            'group_size' => ['required', 'integer', 'min:1', 'max:50'],
            'trip_duration_days' => ['required', 'integer', 'min:1', 'max:30'],
            'preferred_region' => ['nullable', 'string', 'max:100'],
            'weights' => ['required', 'array'],
            'weights.*' => ['integer', 'in:0,1,2,3'],
        ]);

        $user = Auth::user();
        $profile = $user->travelProfile()->updateOrCreate([], [
            'budget_level' => $validated['budget_level'],
            'group_size' => $validated['group_size'],
            'trip_duration_days' => $validated['trip_duration_days'],
            'preferred_region' => $validated['preferred_region'] ?? null,
        ]);

        $validTagIds = Tag::pluck('id')->map(fn ($id) => (string) $id)->all();
        $weights = collect($validated['weights'])
            ->filter(fn ($weight, $tagId) => in_array((string) $tagId, $validTagIds, true))
            ->filter(fn ($weight) => (int) $weight > 0)
            ->map(fn ($weight) => (int) $weight)
            ->all();

        if (count($weights) === 0) {
            return back()->withErrors(['weights' => 'Choose at least one travel interest.'])->withInput();
        }

        $profile->tags()->sync(
            collect($weights)->mapWithKeys(fn ($weight, $tagId) => [
                $tagId => ['weight' => $weight],
            ])->all()
        );

        $user->destinationSwipes()->delete();

        return redirect()->route('discover.index')
            ->with('status', 'Your travel preferences were saved. Start swiping to personalize your results.');
        }
}