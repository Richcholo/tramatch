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
                href="{{ route('admin.dashboard') }}"
                class="rounded-full border border-boracay-light px-5 py-3 text-sm font-semibold text-benguet-charcoal transition hover:bg-island-white"
            >
                ← Admin dashboard
            </a>
        </div>
    </x-slot>

    <div class="space-y-8">
        <section class="rounded-[1.5rem] border border-boracay-light bg-palawan-sand">
            <form method="GET" action="{{ route('admin.destinations.index') }}" class="grid gap-5 p-6 sm:grid-cols-3 sm:items-center sm:p-8">
                <label class="block sm:col-span-2">
                    <span class="text-[0.65rem] font-bold uppercase tracking-[0.25em] text-benguet-charcoal/60">
                        Location or destination
                    </span>

                    <input
                        id="destination-search"
                        name="search"
                        list="destination-location-options"
                        value="{{ $search }}"
                        placeholder="Search or enter a location"
                        class="mt-3 w-full border-0 border-b border-boracay-light bg-transparent px-0 pb-3 text-sm text-volcanic-teal placeholder:text-benguet-charcoal/40 focus:border-boracay focus:ring-0"
                    >
                    <datalist id="destination-location-options">
                        @foreach ($locations as $location)
                            <option value="{{ $location }}"></option>
                        @endforeach
                    </datalist>
                </label>

                <div class="flex items-end justify-between gap-4 sm:justify-end">
                    <span class="text-[0.65rem] font-bold uppercase tracking-[0.25em] text-benguet-charcoal/45">
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

        {{--
            BULK EDIT, AND WHY IT IS PAGE-SCOPED AND JAVALESS.

            Only the four fields that gate what a traveller is ever shown —
            budget tier, featured, active, and tags. Everything else this app
            knows about a destination is curated in the CSV and re-seeded over,
            so a bulk action here cannot reach curated data.

            **NO "SELECT ALL MATCHING" BANNER, DELIBERATELY.**
            `admin/sources/index` has one: a page checkbox, a session-backed
            cross-page selection and ~120 lines of script, whose hidden inputs
            carry deliberately different names (`filter_search`) so they cannot
            be confused with the GET filter. Copying that into a second page
            would be a second implementation of one behaviour — the failure
            this repo keeps paying for — and a first version of this action was
            about to do exactly that. Selection is therefore scoped to the
            page, and the cost of that choice is honest: fixing a tier split
            across a 112-destination catalogue means applying the change page
            by page.
            If cross-page selection is wanted here, EXTRACT the sources script
            into a shared partial first; do not paste it twice.

            The wrapper form holds the table, so every per-row form and every
            delete dialog inside a row has moved BELOW this form and its button
            points at it with `form="..."`. A nested form's start tag is
            discarded by the HTML parser and its first close tag ends the
            OUTER form — which would submit the bulk edit instead of the
            archive.
        --}}
        <form method="POST" action="{{ route('admin.destinations.bulk') }}">
            @csrf

            <div class="mt-8 rounded-[2rem] border border-boracay-light bg-palawan-sand p-6">
                <div class="flex flex-wrap items-end gap-4">
                    <div>
                        <label for="bulk_budget_level" class="block text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/60">
                            Budget tier
                        </label>
                        <select id="bulk_budget_level" name="budget_level" class="mt-1 min-h-11 rounded-md border-boracay-light bg-white px-3 py-2 text-sm">
                            <option value="">Leave unchanged</option>
                            @foreach (array_keys(\App\Models\Destination::BUDGET_TIERS) as $tier)
                                <option value="{{ $tier }}">{{ ucfirst($tier) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="bulk_is_featured" class="block text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/60">
                            Featured
                        </label>
                        <select id="bulk_is_featured" name="is_featured" class="mt-1 min-h-11 rounded-md border-boracay-light bg-white px-3 py-2 text-sm">
                            <option value="">Leave unchanged</option>
                            <option value="yes">Yes</option>
                            <option value="no">No</option>
                        </select>
                    </div>

                    <div>
                        <label for="bulk_is_active" class="block text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/60">
                            Published
                        </label>
                        <select id="bulk_is_active" name="is_active" class="mt-1 min-h-11 rounded-md border-boracay-light bg-white px-3 py-2 text-sm">
                            <option value="">Leave unchanged</option>
                            <option value="yes">Published</option>
                            <option value="no">Archived</option>
                        </select>
                    </div>

                    <div>
                        <label for="bulk_add_tags" class="block text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/60">
                            Add tags
                        </label>
                        <select id="bulk_add_tags" name="add_tags[]" multiple size="3" class="mt-1 min-h-11 rounded-md border-boracay-light bg-white px-3 py-2 text-sm">
                            @foreach ($tags as $tag)
                                <option value="{{ $tag->id }}">{{ $tag->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="bulk_remove_tags" class="block text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/60">
                            Remove tags
                        </label>
                        <select id="bulk_remove_tags" name="remove_tags[]" multiple size="3" class="mt-1 min-h-11 rounded-md border-boracay-light bg-white px-3 py-2 text-sm">
                            @foreach ($tags as $tag)
                                <option value="{{ $tag->id }}">{{ $tag->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <button
                        type="submit"
                        class="min-h-11 rounded-full bg-volcanic-teal px-6 py-3 text-sm font-bold text-white transition hover:bg-volcanic-teal/90"
                    >
                        Apply to selected
                    </button>
                </div>

                <p class="mt-4 text-xs text-benguet-charcoal/60">
                    Tick rows on this page, then choose what to change. Anything left
                    alone stays exactly as it is, and a tag cannot be added and
                    removed in the same pass. These are the only fields here that
                    decide what a traveller sees — descriptions, hours and fees are
                    curated in the CSV and are not editable in bulk.
                </p>

                @error('bulk')
                    <p class="mt-3 text-sm font-semibold text-red-700">{{ $message }}</p>
                @enderror
            </div>

        <div class="mt-5 overflow-x-auto rounded-[2rem] bg-island-white shadow-sm ring-1 ring-boracay-light">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-boracay-light bg-palawan-sand">
                    <tr>
                        <th class="w-12 px-6 py-5">
                            <span class="sr-only">Select</span>
                        </th>

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
                                <input
                                    type="checkbox"
                                    name="ids[]"
                                    value="{{ $destination->id }}"
                                    aria-label="Select {{ $destination->name }}"
                                    class="h-4 w-4 accent-boracay"
                                >
                            </td>

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

                                    {{-- These three controls keep their markup in the
                                         row but submit forms that now live BELOW the
                                         bulk wrapper, via `form="..."`. A form nested
                                         in the wrapper would have its start tag
                                         discarded and its close tag would end the
                                         bulk edit instead. --}}
                                    @if ($destination->is_active)
                                        <button
                                            type="submit"
                                            form="archive-destination-{{ $destination->id }}"
                                            class="font-semibold text-amber-700"
                                        >
                                            Archive
                                        </button>
                                    @else
                                        <button
                                            type="submit"
                                            form="restore-destination-{{ $destination->id }}"
                                            class="font-semibold text-emerald-700"
                                        >
                                            Restore
                                        </button>
                                    @endif

                                    <button
                                        type="button"
                                        onclick="document.getElementById('delete-destination-confirmation-{{ $destination->id }}').showModal()"
                                        class="font-semibold text-red-600"
                                    >
                                        Delete
                                    </button>

                                    {{-- The delete dialog is rendered BELOW the bulk wrapper, with its
                                         own form: a form nested in the wrapper is not a form at all. --}}
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-benguet-charcoal/60">
                                No destinations found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        </form>

        {{--
            THE FORMS THE ROWS' BUTTONS POINT AT, plus every delete
            dialog. All of them live here, OUTSIDE the bulk wrapper, because a
            <form> inside another <form> has its start tag discarded and its
            first </form> closes the outer one - which here would turn a Delete
            click into a bulk edit over the ticked rows. Same mechanism as
            admin/sources/index and the day's remove-button form.
        --}}
        @foreach ($destinations as $destination)
            <form
                id="archive-destination-{{ $destination->id }}"
                method="POST"
                action="{{ route('admin.destinations.archive', $destination) }}"
                class="hidden"
            >
                @csrf
                @method('PATCH')
            </form>

            <form
                id="restore-destination-{{ $destination->id }}"
                method="POST"
                action="{{ route('admin.destinations.restore', $destination) }}"
                class="hidden"
            >
                @csrf
                @method('PATCH')
            </form>

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
        @endforeach

        {{ $destinations->links() }}
    </div>
</x-app-layout>
