<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user()->load('travelProfile.tags');
        $travelProfile = $user->travelProfile;
        $preferenceChecklist = [
            'Budget' => $travelProfile !== null,
            'Group size' => $travelProfile !== null,
            'Trip length' => $travelProfile !== null,
            'Interests' => $travelProfile?->tags->isNotEmpty() ?? false,
        ];
        $completedPreferences = count(array_filter($preferenceChecklist));
        $missingPreferences = array_keys(array_filter(
            $preferenceChecklist,
            fn (bool $isComplete): bool => ! $isComplete
        ));

        return view('profile.show', [
            'user' => $user,
            'travelProfile' => $travelProfile,
            'completedPreferences' => $completedPreferences,
            'totalPreferences' => count($preferenceChecklist),
            'missingPreferences' => $missingPreferences,
            'preferencePercentage' => (int) round(
                $completedPreferences / count($preferenceChecklist) * 100
            ),
        ]);
    }

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    public function updatePhoto(Request $request): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();
        $previousPhotoPath = $user->profile_photo_path;
        $photoPath = $request->file('photo')->store('profile-photos', 'public');

        if (! $photoPath) {
            return Redirect::route('profile.edit')
                ->withErrors(['photo' => 'The profile photo could not be saved.']);
        }

        $user->profile_photo_path = $photoPath;
        $user->save();

        if ($previousPhotoPath) {
            Storage::disk('public')->delete($previousPhotoPath);
        }

        return Redirect::route('profile.edit')->with('photoUpdated', true);
    }

    public function removePhoto(Request $request): RedirectResponse
    {
        $user = $request->user();
        $photoPath = $user->profile_photo_path;

        $user->profile_photo_path = null;
        $user->save();

        if ($photoPath) {
            Storage::disk('public')->delete($photoPath);
        }

        return Redirect::route('profile.edit')->with('photoRemoved', true);
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
