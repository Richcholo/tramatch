<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                    Operations / Destinations
                </p>

                <h1 class="mt-3 font-display text-4xl font-semibold tracking-[-0.04em] text-ink-strong sm:text-6xl">
                    Keep the catalog alive.
                </h1>
            </div>

            <a
                href="{{ route('admin.dashboard') }}"
                class="rounded-full border border-line px-5 py-3 text-sm font-semibold text-ink transition hover:bg-surface-raised"
            >
                ← Admin dashboard
            </a>
        </div>
    </x-slot>

    <div class="space-y-8">
        <section class="rounded-[1.5rem] border border-line bg-surface">
            <form method="GET" action="{{ route('admin.destinations.index') }}" class="grid gap-5 p-6 sm:grid-cols-3 sm:items-center sm:p-8">
                <label class="block sm:col-span-2">
                    <span class="text-[0.65rem] font-bold uppercase tracking-[0.25em] text-ink/60">
                        Location or destination
                    </span>

                    <input
                        id="destination-search"
                        name="search"
                        list="destination-location-options"
                        value="{{ $search }}"
                        placeholder="Search or enter a location"
                        class="mt-3 w-full border-0 border-b border-line bg-transparent px-0 pb-3 text-sm text-ink-strong placeholder:text-ink/40 focus:border-boracay focus:ring-0"
                    >
                    <datalist id="destination-location-options">
                        @foreach ($locations as $location)
                            <option value="{{ $location }}"></option>
                        @endforeach
                    </datalist>
                </label>

                <div class="flex items-end justify-between gap-4 sm:justify-end">
                    <span class="text-[0.65rem] font-bold uppercase tracking-[0.25em] text-ink/45">
                        {{ $destinations->total() }} places
                    </span>

                    <button type="submit" class="rounded-full bg-volcanic-teal px-5 py-3 text-xs font-bold uppercase tracking-[0.15em] text-white transition hover:bg-boracay-dark">
                        Filter
                    </button>
                </div>
            </form>
        </section>

        <div class="flex justify-start">
            <a
                href="{{ route('admin.destinations.create') }}"
                class="tm-primary-button rounded-full"
            >
                Add destination →
            </a>
        </div>

        <div class="overflow-x-auto rounded-[2rem] bg-surface-raised shadow-sm ring-1 ring-boracay-light">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-line bg-surface">
                    <tr>
                        <th class="px-6 py-5 font-bold text-ink-strong">
                            Destination
                        </th>

                        <th class="px-6 py-5 font-bold text-ink-strong">
                            Province
                        </th>

                        <th class="px-6 py-5 font-bold text-ink-strong">
                            Budget
                        </th>

                        <th class="px-6 py-5 font-bold text-ink-strong">
                            Status
                        </th>

                        <th class="px-6 py-5 text-right font-bold text-ink-strong">
                            Actions
                        </th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($destinations as $destination)
                        <tr class="border-b border-line last:border-0">
                            <td class="px-6 py-5">
                                <p class="font-bold text-ink-strong">
                                    {{ $destination->name }}
                                </p>

                                <p class="mt-1 text-xs text-ink/55">
                                    {{ $destination->municipality }}
                                </p>
                            </td>

                            <td class="px-6 py-5 text-ink/70">
                                {{ $destination->province }}
                            </td>

                            <td class="px-6 py-5 capitalize text-ink/70">
                                {{ $destination->budget_level }}
                            </td>

                            <td class="px-6 py-5">
                                @if ($destination->is_active)
                                    <span class="tm-gold-badge">
                                        Active
                                    </span>
                                @else
                                    <span class="rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-600">
                                        Archived
                                    </span>
                                @endif
                            </td>

                            <td class="px-6 py-5">
                                <div class="flex flex-wrap justify-end gap-3">
                                    <a
                                        href="{{ route('admin.destinations.edit', $destination) }}"
                                        class="font-semibold text-boracay-dark hover:text-ink-strong"
                                    >
                                        Edit
                                    </a>

                                    @if ($destination->is_active)
                                        <form
                                            method="POST"
                                            action="{{ route('admin.destinations.archive', $destination) }}"
                                        >
                                            @csrf
                                            @method('PATCH')

                                            <button class="font-semibold text-amber-700">
                                                Archive
                                            </button>
                                        </form>
                                    @else
                                        <form
                                            method="POST"
                                            action="{{ route('admin.destinations.restore', $destination) }}"
                                        >
                                            @csrf
                                            @method('PATCH')

                                            <button class="font-semibold text-emerald-700">
                                                Restore
                                            </button>
                                        </form>
                                    @endif

                                    <button
                                        type="button"
                                        onclick="document.getElementById('delete-destination-confirmation-{{ $destination->id }}').showModal()"
                                        class="font-semibold text-red-600"
                                    >
                                        Delete
                                    </button>

                                    <dialog
                                        id="delete-destination-confirmation-{{ $destination->id }}"
                                        aria-labelledby="delete-destination-confirmation-title-{{ $destination->id }}"
                                        aria-describedby="delete-destination-confirmation-description-{{ $destination->id }}"
                                        onclick="if (event.target === this) this.close()"
                                        class="m-auto w-[calc(100%-2rem)] max-w-md rounded-lg border border-red-200 dark:border-red-400/30 bg-surface p-0 text-ink shadow-2xl backdrop:bg-volcanic-teal/60 backdrop:backdrop-blur-sm"
                                    >
                                        <div class="p-6 sm:p-7">
                                            <div class="flex items-start gap-4">
                                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-50 dark:bg-red-500/150/20 text-red-700 dark:text-red-300" aria-hidden="true">
                                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12m-10 0 .7 13h6.6L16 7M9 7V4h6v3m-4 4v5m2-5v5" />
                                                    </svg>
                                                </span>
                                                <div>
                                                    <h2 id="delete-destination-confirmation-title-{{ $destination->id }}" class="font-display text-xl font-semibold text-ink-strong">
                                                        Permanently delete destination?
                                                    </h2>
                                                    <p id="delete-destination-confirmation-description-{{ $destination->id }}" class="mt-2 text-sm leading-6 text-ink/70">
                                                        “{{ $destination->name }}” and its reviews, swipe history, and itinerary stops will be deleted. This cannot be undone.
                                                    </p>
                                                </div>
                                            </div>

                                            <form method="POST" action="{{ route('admin.destinations.destroy', $destination) }}" class="mt-7 flex justify-end gap-3">
                                                @csrf
                                                @method('DELETE')
                                                <button type="button" autofocus onclick="this.closest('dialog').close()" class="rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-boracay focus:ring-offset-2 dark:focus:ring-offset-volcanic-teal">
                                                    Cancel
                                                </button>
                                                <button type="submit" class="rounded-full bg-red-700 px-4 py-2 text-sm font-bold text-white transition hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-700 focus:ring-offset-2 dark:focus:ring-offset-volcanic-teal">
                                                    Delete permanently
                                                </button>
                                            </form>
                                        </div>
                                    </dialog>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-ink/60">
                                No destinations found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $destinations->links() }}
    </div>
</x-app-layout>
