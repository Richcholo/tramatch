<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * THE SIX-DIGIT CODE, checked at the HTTP boundary.
 *
 * One wrong-code answer for every failure shape — wrong digits, an expired
 * code, a code that was never issued — because telling a caller WHICH of
 * those it was is a guessing aid. The code burns on success: a hash nobody
 * can satisfy twice.
 */
class VerifyEmailCodeController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $user = $request->user();

        if (! $user->verifyEmailCode($validated['code'])) {
            return back()->withErrors([
                'code' => 'That code is not right, or it has expired. Request a new one.',
            ]);
        }

        return redirect()->intended(route('dashboard', absolute: false))
            ->with('status', 'Your email is verified. Welcome aboard.');
    }
}
