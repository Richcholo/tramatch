<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                    Operations / Destinations
                </p>

                <h1 class="mt-3 font-display text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal sm:text-6xl">
                    Keep the catalog alive.
                </h1>
            </div>

            <a
                href="{{ route('admin.destinations.create') }}"
                class="tm-primary-button rounded-full"
            >
                Add destination →
            </a>
        </div>
    </x-slot>

    <div class="space-y-8">
        @if (session('status'))
            <div class="rounded-2xl border border-boracay bg-boracay-light p-4 text-benguet-charcoal">
                {{ session('status') }}
            </div>
        @endif

        <div class="overflow-x-auto rounded-[2rem] bg-island-white shadow-sm ring-1 ring-boracay-light">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-boracay-light bg-palawan-sand">
                    <tr>
                        <th class="px-6 py-5 font-bold text-volcanic-teal">
                            Destination
                        </th>

                        <th class="px-6 py-5 font-bold text-volcanic-teal">
                            Province
                        </th>

                        <th class="px-6 py-5 font-bold text-volcanic-teal">
                            Budget
                        </th>

                        <th class="px-6 py-5 font-bold text-volcanic-teal">
                            Status
                        </th>

                        <th class="px-6 py-5 text-right font-bold text-volcanic-teal">
                            Actions
                        </th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($destinations as $destination)
                        <tr class="border-b border-boracay-light last:border-0">
                            <td class="px-6 py-5">
                                <p class="font-bold text-volcanic-teal">
                                    {{ $destination->name }}
                                </p>

                                <p class="mt-1 text-xs text-benguet-charcoal/55">
                                    {{ $destination->municipality }}
                                </p>
                            </td>

                            <td class="px-6 py-5 text-benguet-charcoal/70">
                                {{ $destination->province }}
                            </td>

                            <td class="px-6 py-5 capitalize text-benguet-charcoal/70">
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
                                        class="font-semibold text-boracay-dark hover:text-volcanic-teal"
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
                                        class="m-auto w-[calc(100%-2rem)] max-w-md rounded-lg border border-red-200 bg-palawan-sand p-0 text-benguet-charcoal shadow-2xl backdrop:bg-volcanic-teal/60 backdrop:backdrop-blur-sm"
                                    >
                                        <div class="p-6 sm:p-7">
                                            <div class="flex items-start gap-4">
                                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-700" aria-hidden="true">
                                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12m-10 0 .7 13h6.6L16 7M9 7V4h6v3m-4 4v5m2-5v5" />
                                                    </svg>
                                                </span>
                                                <div>
                                                    <h2 id="delete-destination-confirmation-title-{{ $destination->id }}" class="font-display text-xl font-semibold text-volcanic-teal">
                                                        Permanently delete destination?
                                                    </h2>
                                                    <p id="delete-destination-confirmation-description-{{ $destination->id }}" class="mt-2 text-sm leading-6 text-benguet-charcoal/70">
                                                        “{{ $destination->name }}” and its reviews, swipe history, and itinerary stops will be deleted. This cannot be undone.
                                                    </p>
                                                </div>
                                            </div>

                                            <form method="POST" action="{{ route('admin.destinations.destroy', $destination) }}" class="mt-7 flex justify-end gap-3">
                                                @csrf
                                                @method('DELETE')
                                                <button type="button" autofocus onclick="this.closest('dialog').close()" class="rounded-full border border-boracay-light px-4 py-2 text-sm font-semibold text-benguet-charcoal transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-boracay focus:ring-offset-2">
                                                    Cancel
                                                </button>
                                                <button type="submit" class="rounded-full bg-red-700 px-4 py-2 text-sm font-bold text-white transition hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-700 focus:ring-offset-2">
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
                            <td colspan="5" class="px-6 py-12 text-center text-benguet-charcoal/60">
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