<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        $users = User::query()
            ->latest()
            ->paginate(20);

        return view('admin.users.index', compact('users'));
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
}