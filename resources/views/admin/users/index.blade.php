<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                Super admin / Users
            </p>

            <h1 class="mt-3 font-display text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal sm:text-6xl">
                Manage access.
            </h1>

            <a
                href="{{ route('admin.dashboard') }}"
                class="mt-5 inline-flex rounded-full border border-boracay-light px-5 py-3 text-sm font-semibold text-benguet-charcoal transition hover:bg-island-white"
            >
                ← Admin dashboard
            </a>
        </div>
    </x-slot>

    <div class="space-y-8">
        <div class="overflow-x-auto rounded-[2rem] bg-island-white shadow-sm ring-1 ring-boracay-light">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-boracay-light bg-palawan-sand">
                    <tr>
                        <th class="px-6 py-5 font-bold text-volcanic-teal">User</th>
                        <th class="px-6 py-5 font-bold text-volcanic-teal">Email</th>
                        <th class="px-6 py-5 font-bold text-volcanic-teal">Role</th>
                        <th class="px-6 py-5 text-right font-bold text-volcanic-teal">Actions</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($users as $user)
                        <tr class="border-b border-boracay-light last:border-0">
                            <td class="px-6 py-5 font-semibold text-volcanic-teal">
                                {{ $user->name }}
                            </td>
                            <td class="px-6 py-5 text-benguet-charcoal/70">
                                {{ $user->email }}
                            </td>
                            <td class="px-6 py-5 capitalize text-benguet-charcoal/70">
                                {{ str_replace('_', ' ', $user->role) }}
                            </td>
                            <td class="px-6 py-5 text-right">
                                @if ($user->role === 'traveler')
                                    <button
                                        type="button"
                                        onclick="document.getElementById('make-admin-confirmation-{{ $user->id }}').showModal()"
                                        class="font-semibold text-boracay-dark hover:text-volcanic-teal"
                                    >
                                        Make admin
                                    </button>

                                    <dialog
                                        id="make-admin-confirmation-{{ $user->id }}"
                                        aria-labelledby="make-admin-title-{{ $user->id }}"
                                        onclick="if (event.target === this) this.close()"
                                        class="m-auto w-[calc(100%-2rem)] max-w-md rounded-lg border border-boracay-light bg-palawan-sand p-0 text-benguet-charcoal shadow-2xl backdrop:bg-volcanic-teal/60 backdrop:backdrop-blur-sm"
                                    >
                                        <div class="p-6 sm:p-7">
                                            <h2 id="make-admin-title-{{ $user->id }}" class="font-display text-xl font-semibold text-volcanic-teal">
                                                Make {{ $user->name }} an admin?
                                            </h2>
                                            <p class="mt-2 text-sm leading-6 text-benguet-charcoal/70">
                                                This will give the user access to the admin dashboard and catalog management tools.
                                            </p>

                                            <form method="POST" action="{{ route('admin.users.make-admin', $user) }}" class="mt-7 flex justify-end gap-3">
                                                @csrf
                                                @method('PATCH')
                                                <button type="button" onclick="this.closest('dialog').close()" class="rounded-full border border-boracay-light px-4 py-2 text-sm font-semibold text-benguet-charcoal transition hover:bg-white">
                                                    Cancel
                                                </button>
                                                <button type="submit" class="rounded-full bg-volcanic-teal px-4 py-2 text-sm font-bold text-white transition hover:bg-boracay-dark">
                                                    Make admin
                                                </button>
                                            </form>
                                        </div>
                                    </dialog>
                                @elseif ($user->role === 'admin')
                                    <button
                                        type="button"
                                        onclick="document.getElementById('demote-admin-confirmation-{{ $user->id }}').showModal()"
                                        class="font-semibold text-amber-700 hover:text-amber-900"
                                    >
                                        Demote
                                    </button>

                                    <dialog
                                        id="demote-admin-confirmation-{{ $user->id }}"
                                        aria-labelledby="demote-admin-title-{{ $user->id }}"
                                        onclick="if (event.target === this) this.close()"
                                        class="m-auto w-[calc(100%-2rem)] max-w-md rounded-lg border border-amber-200 bg-palawan-sand p-0 text-benguet-charcoal shadow-2xl backdrop:bg-volcanic-teal/60 backdrop:backdrop-blur-sm"
                                    >
                                        <div class="p-6 sm:p-7">
                                            <h2 id="demote-admin-title-{{ $user->id }}" class="font-display text-xl font-semibold text-volcanic-teal">
                                                Demote {{ $user->name }}?
                                            </h2>
                                            <p class="mt-2 text-sm leading-6 text-benguet-charcoal/70">
                                                This will remove admin access and change the user back to a traveler.
                                            </p>

                                            <form method="POST" action="{{ route('admin.users.demote-admin', $user) }}" class="mt-7 flex justify-end gap-3">
                                                @csrf
                                                @method('PATCH')
                                                <button type="button" onclick="this.closest('dialog').close()" class="rounded-full border border-boracay-light px-4 py-2 text-sm font-semibold text-benguet-charcoal transition hover:bg-white">
                                                    Cancel
                                                </button>
                                                <button type="submit" class="rounded-full bg-amber-700 px-4 py-2 text-sm font-bold text-white transition hover:bg-amber-800">
                                                    Demote
                                                </button>
                                            </form>
                                        </div>
                                    </dialog>
                                @else
                                    <span class="text-benguet-charcoal/45">No action</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-benguet-charcoal/60">
                                No users found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $users->links() }}
    </div>
</x-app-layout>