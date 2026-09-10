<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-boracay-dark">
                    TraMatch management
                </p>

                <h2 class="text-xl font-semibold text-benguet-charcoal">
                    Manage destinations
                </h2>
            </div>

            <a
                href="{{ route('admin.destinations.create') }}"
                class="tm-primary-button"
            >
                Add destination
            </a>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if (session('status'))
            <div class="rounded-xl border border-boracay bg-boracay-light p-4 text-benguet-charcoal">
                {{ session('status') }}
            </div>
        @endif

        <section>
            <p class="text-sm uppercase tracking-wide text-boracay-dark">
                Destination catalog
            </p>

            <h1 class="mt-1 text-3xl font-bold text-volcanic-teal">
                Destinations
            </h1>

            <p class="mt-2 text-benguet-charcoal/70">
                Add, edit, archive, restore, or permanently remove locations.
            </p>
        </section>

        <div class="overflow-x-auto rounded-2xl bg-island-white shadow-sm ring-1 ring-boracay-light">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-boracay-light bg-palawan-sand">
                    <tr>
                        <th class="px-5 py-4 font-semibold text-volcanic-teal">
                            Name
                        </th>

                        <th class="px-5 py-4 font-semibold text-volcanic-teal">
                            Province
                        </th>

                        <th class="px-5 py-4 font-semibold text-volcanic-teal">
                            Budget
                        </th>

                        <th class="px-5 py-4 font-semibold text-volcanic-teal">
                            Status
                        </th>

                        <th class="px-5 py-4 text-right font-semibold text-volcanic-teal">
                            Actions
                        </th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($destinations as $destination)
                        <tr class="border-b border-boracay-light last:border-0">
                            <td class="px-5 py-4 font-semibold text-volcanic-teal">
                                {{ $destination->name }}
                            </td>

                            <td class="px-5 py-4 text-benguet-charcoal/75">
                                {{ $destination->province }}
                            </td>

                            <td class="px-5 py-4 capitalize text-benguet-charcoal/75">
                                {{ $destination->budget_level }}
                            </td>

                            <td class="px-5 py-4">
                                @if ($destination->is_active)
                                    <span class="rounded-full bg-boracay-light px-3 py-1 text-xs font-semibold text-boracay-dark">
                                        Active
                                    </span>
                                @else
                                    <span class="rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-600">
                                        Archived
                                    </span>
                                @endif
                            </td>

                            <td class="px-5 py-4">
                                <div class="flex flex-wrap justify-end gap-3">
                                    <a
                                        href="{{ route('admin.destinations.edit', $destination) }}"
                                        class="font-medium text-boracay-dark hover:text-volcanic-teal"
                                    >
                                        Edit
                                    </a>

                                    @if ($destination->is_active)
                                        <form
                                            method="POST"
                                            action="{{ route('admin.destinations.archive', $destination) }}"
                                            onsubmit="return confirm('Remove this destination from the public catalog?')"
                                        >
                                            @csrf
                                            @method('PATCH')

                                            <button
                                                type="submit"
                                                class="font-medium text-amber-700 hover:text-amber-900"
                                            >
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

                                            <button
                                                type="submit"
                                                class="font-medium text-emerald-700 hover:text-emerald-900"
                                            >
                                                Restore
                                            </button>
                                        </form>
                                    @endif

                                    <form
                                        method="POST"
                                        action="{{ route('admin.destinations.destroy', $destination) }}"
                                        onsubmit="return confirm('Permanently delete this destination and its related reviews, swipes, itinerary items, and tags?')"
                                    >
                                        @csrf
                                        @method('DELETE')

                                        <button
                                            type="submit"
                                            class="font-medium text-red-600 hover:text-red-800"
                                        >
                                            Delete permanently
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td
                                colspan="5"
                                class="px-5 py-10 text-center text-benguet-charcoal/60"
                            >
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