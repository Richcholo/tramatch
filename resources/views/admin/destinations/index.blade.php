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

                                    <form
                                        method="POST"
                                        action="{{ route('admin.destinations.destroy', $destination) }}"
                                        onsubmit="return confirm('Permanently delete this destination?')"
                                    >
                                        @csrf
                                        @method('DELETE')

                                        <button class="font-semibold text-red-600">
                                            Delete
                                        </button>
                                    </form>
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