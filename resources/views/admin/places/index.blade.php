<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                    Operations / Places
                </p>

                <h1 class="mt-3 font-display text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal sm:text-6xl">
                    Where they eat and sleep.
                </h1>
            </div>

            <a
                href="{{ route('admin.dashboard') }}"
                class="rounded-full border border-boracay-light px-5 py-3 text-sm font-semibold text-benguet-charcoal transition hover:bg-island-white"
            >
                ← Admin dashboard
            </a>
        </div>
    </x-slot>

    <div class="space-y-8">
        {{-- The coverage report is the curation tool, not decoration. Per
             cluster and per budget tier it shows how many food places and
             hotels exist and names what is missing — because "is this cluster
             big enough" is always "for which tiers, and what is missing", and
             a single summed count would hide a tier with nothing in it. --}}
        <section class="rounded-[1.5rem] border border-boracay-light bg-palawan-sand p-6 sm:p-8">
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.25em] text-benguet-charcoal/60">
                Cluster coverage — food and lodging per budget tier
            </p>

            <div class="mt-5 grid gap-5 lg:grid-cols-3">
                @foreach ($coverage as $clusterKey => $cluster)
                    <div class="rounded-2xl bg-island-white p-5 ring-1 ring-boracay-light">
                        <p class="font-display text-xl font-semibold text-volcanic-teal">
                            {{ $cluster['label'] }}
                        </p>

                        <p class="mt-1 text-xs text-benguet-charcoal/55">
                            {{ $cluster['province'] }} · needs {{ \App\Models\Place::CLUSTER_MINIMUMS['food'] }} food, {{ \App\Models\Place::CLUSTER_MINIMUMS['lodging'] }} lodging per tier
                        </p>

                        <dl class="mt-4 space-y-3">
                            @foreach ($cluster['tiers'] as $tier => $report)
                                <div class="border-t border-boracay-light pt-3 first:border-0 first:pt-0">
                                    <div class="flex items-center justify-between gap-3">
                                        <dt class="text-xs font-bold uppercase tracking-[0.15em] text-benguet-charcoal/70">
                                            {{ $tier }}
                                        </dt>

                                        @if ($report['readiness']['ready'])
                                            <span class="tm-gold-badge">
                                                Ready
                                            </span>
                                        @else
                                            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">
                                                Short
                                            </span>
                                        @endif
                                    </div>

                                    <dd class="mt-1 text-sm text-benguet-charcoal/70">
                                        {{ $report['counts']['food'] }} food · {{ $report['counts']['lodging'] }} lodging · {{ $report['counts']['priced'] }} priced
                                    </dd>

                                    @if (! $report['readiness']['ready'])
                                        <dd class="mt-1 text-xs text-benguet-charcoal/55">
                                            Missing: {{ implode('; ', $report['readiness']['gaps']) }}
                                        </dd>
                                    @endif
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-[1.5rem] border border-boracay-light bg-palawan-sand">
            <form method="GET" action="{{ route('admin.places.index') }}" class="grid gap-5 p-6 sm:grid-cols-4 sm:items-center sm:p-8">
                <label class="block sm:col-span-2">
                    <span class="text-[0.65rem] font-bold uppercase tracking-[0.25em] text-benguet-charcoal/60">
                        Name, cuisine, or location
                    </span>

                    <input
                        id="place-search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Search places"
                        class="mt-3 w-full border-0 border-b border-boracay-light bg-transparent px-0 pb-3 text-sm text-volcanic-teal placeholder:text-benguet-charcoal/40 focus:border-boracay focus:ring-0"
                    >
                </label>

                <label class="block">
                    <span class="text-[0.65rem] font-bold uppercase tracking-[0.25em] text-benguet-charcoal/60">
                        Kind
                    </span>

                    <select
                        name="type"
                        class="mt-3 w-full border-0 border-b border-boracay-light bg-transparent px-0 pb-3 text-sm text-volcanic-teal focus:border-boracay focus:ring-0"
                    >
                        <option value="">Food and lodging</option>
                        <option value="food" @selected($type === 'food')>Where to eat</option>
                        <option value="lodging" @selected($type === 'lodging')>Where to stay</option>
                    </select>
                </label>

                <div class="flex items-end justify-between gap-4 sm:justify-end">
                    <span class="text-[0.65rem] font-bold uppercase tracking-[0.25em] text-benguet-charcoal/45">
                        {{ $places->total() }} places
                    </span>

                    <button type="submit" class="rounded-full bg-volcanic-teal px-5 py-3 text-xs font-bold uppercase tracking-[0.15em] text-white transition hover:bg-boracay-dark">
                        Filter
                    </button>
                </div>
            </form>
        </section>

        <div class="flex justify-start">
            <a
                href="{{ route('admin.places.create') }}"
                class="tm-primary-button rounded-full"
            >
                Add place →
            </a>
        </div>

        <div class="overflow-x-auto rounded-[2rem] bg-island-white shadow-sm ring-1 ring-boracay-light">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-boracay-light bg-palawan-sand">
                    <tr>
                        <th class="px-6 py-5 font-bold text-volcanic-teal">
                            Place
                        </th>

                        <th class="px-6 py-5 font-bold text-volcanic-teal">
                            Price
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
                    @forelse ($places as $place)
                        <tr class="border-b border-boracay-light last:border-0">
                            <td class="px-6 py-5">
                                <p class="font-bold text-volcanic-teal">
                                    {{ $place->name }}
                                </p>

                                <p class="mt-1 text-xs text-benguet-charcoal/55">
                                    {{ $place->typeLabel() }} · {{ $place->municipality }}, {{ $place->province }}
                                </p>
                            </td>

                            <td class="px-6 py-5 text-benguet-charcoal/70">
                                {{ $place->priceLabel() }}
                            </td>

                            <td class="px-6 py-5 capitalize text-benguet-charcoal/70">
                                {{ $place->budget_level }}
                            </td>

                            <td class="px-6 py-5">
                                @if ($place->is_active)
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
                                        href="{{ route('admin.places.edit', $place) }}"
                                        class="font-semibold text-boracay-dark hover:text-volcanic-teal"
                                    >
                                        Edit
                                    </a>

                                    @if ($place->is_active)
                                        <form
                                            method="POST"
                                            action="{{ route('admin.places.archive', $place) }}"
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
                                            action="{{ route('admin.places.restore', $place) }}"
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
                                        onclick="document.getElementById('delete-place-confirmation-{{ $place->id }}').showModal()"
                                        class="font-semibold text-red-600"
                                    >
                                        Delete
                                    </button>

                                    <dialog
                                        id="delete-place-confirmation-{{ $place->id }}"
                                        aria-labelledby="delete-place-confirmation-title-{{ $place->id }}"
                                        aria-describedby="delete-place-confirmation-description-{{ $place->id }}"
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
                                                    <h2 id="delete-place-confirmation-title-{{ $place->id }}" class="font-display text-xl font-semibold text-volcanic-teal">
                                                        Permanently delete place?
                                                    </h2>
                                                    <p id="delete-place-confirmation-description-{{ $place->id }}" class="mt-2 text-sm leading-6 text-benguet-charcoal/70">
                                                        “{{ $place->name }}” will be removed from the catalogue. Itinerary stops that point at it stay on their trips and say the place is gone — nothing is deleted from any traveler's trip.
                                                    </p>
                                                </div>
                                            </div>

                                            <form method="POST" action="{{ route('admin.places.destroy', $place) }}" class="mt-7 flex justify-end gap-3">
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
                                No places found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $places->links() }}
    </div>
</x-app-layout>
