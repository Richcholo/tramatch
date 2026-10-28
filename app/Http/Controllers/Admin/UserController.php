<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        // Archived accounts are a deliberate state, not a filtered accident:
        // the list shows active users unless the operator asks for the
        // archive, and the archive view is where a restore lives.
        $showArchived = $request->boolean('archived');

        $users = User::query()
            ->when($showArchived, fn ($query) => $query->onlyTrashed())
            ->latest()
            ->paginate(20);

        return view('admin.users.index', [
            'users' => $users,
            'showArchived' => $showArchived,
        ]);
    }

    public function makeAdmin(User $user): RedirectResponse
    {
        if (!$user->isSuperAdmin()) {
            $user->role = 'admin';
            $user->save();
        }

        return back()->with('status', "{$user->name} is now an admin.");
    }

    public function demoteAdmin(User $user): RedirectResponse
    {
        if ($user->role === 'admin') {
            $user->role = 'traveler';
            $user->save();
        }

        return back()->with('status', "{$user->name} is now a traveler.");
    }

    /**
     * ARCHIVE, NEVER DESTROY. `User` carries SoftDeletes, so this sets
     * deleted_at and nothing else: the account can no longer sign in, every
     * itinerary, swipe and review stays exactly where it is, and a super
     * admin can bring it back.
     *
     * Two fences. Nobody archives themselves — an admin who does is locked
     * out of their own admin tools by their own hand. And the hierarchy ends
     * where the ability to undo an archive lives: a super admin account is
     * not archived, and an admin account only by a super admin.
     */
    public function archive(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        if ($actor->id === $user->id) {
            return back()->withErrors([
                'user' => 'You cannot archive your own account.',
            ]);
        }

        if ($user->isSuperAdmin()) {
            return back()->withErrors([
                'user' => 'A super admin account cannot be archived.',
            ]);
        }

        if ($user->isAdmin() && ! $actor->isSuperAdmin()) {
            return back()->withErrors([
                'user' => 'Only a super admin can archive an admin account.',
            ]);
        }

        $user->delete();

        return back()->with('status', "{$user->name} was archived. They can no longer sign in; nothing of theirs was destroyed.");
    }

    /**
     * Bring an archived account back. Its email may have been registered
     * again in the meantime — that refusal names the collision rather than
     * crashing the unique constraint.
     */
    public function restore(int $id): RedirectResponse
    {
        $user = User::onlyTrashed()->findOrFail($id);

        // The email column is unique across archived rows too, so the check
        // must be: withTrashed. A live row with the same email means the
        // address was registered again; an archived one means restoring would
        // violate the constraint even though nobody is using it.
        $emailTaken = User::withTrashed()
            ->where('email', $user->email)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($emailTaken) {
            return back()->withErrors([
                'user' => "Cannot restore {$user->name}: the email {$user->email} now belongs to another account.",
            ]);
        }

        $user->restore();

        return back()->with('status', "{$user->name} was restored and can sign in again.");
    }
}