@push('scripts')
    @vite(['resources/js/itinerary-editor.js'])
@endpush

<x-app-layout>
    <x-slot name="header">
        <a
            href="{{ route('itineraries.index') }}"
            class="text-sm font-semibold text-boracay-dark hover:text-volcanic-teal"
        >
            ← Back to my itineraries
        </a>
    </x-slot>

    @php
        $mapPoints = $itinerary->days
            ->flatMap(function ($day) {
                return $day->items->map(function ($item) use ($day) {
                    return [
                        'day' => $day->day_number,
                        'name' => $item->destination->name,
                        'lat' => $item->destination->latitude,
                        'lng' => $item->destination->longitude,
                    ];
                });
            })
            ->values()
            ->toArray();
    @endphp

    <div class="space-y-10">
        <section class="relative overflow-hidden rounded-[2rem] bg-volcanic-teal p-8 text-white shadow-xl sm:p-12">
            <div class="absolute -right-24 -top-24 h-96 w-96 rounded-full bg-boracay/25 blur-3xl"></div>
            <div class="absolute -bottom-32 left-1/3 h-80 w-80 rounded-full bg-philippine-gold/15 blur-3xl"></div>

            <div class="relative flex flex-wrap items-end justify-between gap-8">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.3em] text-boracay-light">
                        {{ ucfirst($itinerary->budget_level) }} trip
                    </p>

                    @if ($itinerary->area)
                        <p class="mt-3 text-sm font-semibold uppercase tracking-[0.22em] text-philippine-gold">
                            {{ $itinerary->area }}
                        </p>
                    @endif

                    <h1 class="mt-5 max-w-4xl font-display text-5xl font-semibold leading-[0.95] tracking-[-0.05em] sm:text-7xl">
                        {{ $itinerary->title }}
                    </h1>

                    <p class="mt-6 text-white/65">
                        {{ $itinerary->trip_duration_days }} day(s)
                        · {{ $itinerary->match_score }}% average match
                        · ₱{{ number_format($itinerary->total_estimated_cost, 2) }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <form
                        method="POST"
                        action="{{ route('itineraries.complete', $itinerary) }}"
                    >
                        @csrf
                        @method('PATCH')

                        <button
                            type="submit"
                            class="rounded-full border border-white/35 px-5 py-3 text-sm font-semibold text-white transition hover:bg-white hover:text-volcanic-teal"
                        >
                            {{ $itinerary->is_completed ? 'Mark active' : 'Mark completed' }}
                        </button>
                    </form>

                    <button
                        type="button"
                        onclick="document.getElementById('delete-itinerary-confirmation').showModal()"
                        class="rounded-full bg-red-500/15 px-5 py-3 text-sm font-semibold text-red-200 transition hover:bg-red-500 hover:text-white"
                    >
                        Delete
                    </button>
                </div>
            </div>
        </section>

        <dialog
            id="delete-itinerary-confirmation"
            aria-labelledby="delete-itinerary-confirmation-title"
            aria-describedby="delete-itinerary-confirmation-description"
            onclick="if (event.target === this) this.close()"
            class="m-auto w-[calc(100%-2rem)] max-w-md rounded-lg border border-red-200 bg-palawan-sand p-0 text-benguet-charcoal shadow-2xl backdrop:bg-volcanic-teal/60 backdrop:backdrop-blur-sm"
        >
            <div class="p-6 sm:p-7">
                <div class="flex items-start gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-700">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12m-10 0 .7 13h6.6L16 7M9 7V4h6v3m-4 4v5m2-5v5" />
                        </svg>
                    </span>

                    <div>
                        <h2 id="delete-itinerary-confirmation-title" class="font-display text-xl font-semibold text-volcanic-teal">
                            Delete this itinerary?
                        </h2>

                        <p id="delete-itinerary-confirmation-description" class="mt-2 text-sm leading-6 text-benguet-charcoal/70">
                            “{{ $itinerary->title }}” and its saved schedule will be permanently deleted.
                        </p>
                    </div>
                </div>

                <form method="POST" action="{{ route('itineraries.destroy', $itinerary) }}" class="mt-7 flex justify-end gap-3">
                    @csrf
                    @method('DELETE')

                    <button
                        type="button"
                        autofocus
                        onclick="this.closest('dialog').close()"
                        class="rounded-full border border-boracay-light px-4 py-2 text-sm font-semibold text-benguet-charcoal transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-boracay focus:ring-offset-2"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="rounded-full bg-red-700 px-4 py-2 text-sm font-bold text-white transition hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-700 focus:ring-offset-2"
                    >
                        Delete itinerary
                    </button>
                </form>
            </div>
        </dialog>

        @if (session('itinerary_warnings'))
            <section class="rounded-[2rem] border border-philippine-gold/50 bg-philippine-gold/15 p-6 text-benguet-charcoal sm:p-8">
                <p class="text-xs font-bold uppercase tracking-[0.25em] text-boracay-dark">
                    Planning notes
                </p>

                <h2 class="mt-4 font-display text-3xl font-semibold text-volcanic-teal">
                    Some selected places were not scheduled.
                </h2>

                <p class="mt-3 text-sm leading-6 text-benguet-charcoal/70">
                    The itinerary only saved destinations that fit the daily time window.
                </p>

                <ul class="mt-5 space-y-3">
                    @foreach (session('itinerary_warnings') as $warning)
                        <li class="rounded-xl bg-white/60 p-4 text-sm">
                            <strong class="text-volcanic-teal">
                                {{ $warning['name'] }}
                            </strong>

                            <span class="text-benguet-charcoal/70">
                                — {{ $warning['reason'] }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="rounded-[2rem] bg-island-white p-4 shadow-sm ring-1 ring-boracay-light sm:p-6">
            <div
                id="itinerary-map"
                data-itinerary-points="{{ json_encode($mapPoints) }}"
                class="h-[28rem] rounded-[1.5rem] bg-boracay-light"
            ></div>
        </section>

        <section data-itinerary-editor class="mt-4">
            <div class="mb-8 flex flex-wrap items-end justify-between gap-6">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                        The route
                    </p>

                    <h2 class="mt-3 font-display text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal">
                        Your days, in order.
                    </h2>

                    <p class="mt-3 max-w-2xl leading-7 text-benguet-charcoal/65">
                        TraMatch helps organize the places you selected while keeping the final destination choices yours.
                    </p>
                </div>

                <button
                    type="button"
                    data-editor-toggle
                    aria-pressed="false"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-boracay px-5 py-3 text-sm font-semibold text-volcanic-teal transition hover:bg-boracay hover:text-white"
                >
                    Edit itinerary
                </button>
            </div>

            {{-- One row shape, cloned by the editor when a stop is added. Keeping
                 the markup here rather than in JS means a field added later
                 exists in one place. --}}
            <template data-stop-template>
                <li
                    data-stop
                    class="rounded-xl border border-boracay-light bg-white p-4 shadow-sm"
                >
                    <input type="hidden" name="items[__KEY__][day_id]" data-field="day-id" value="">
                    <input type="hidden" name="items[__KEY__][sort_order]" data-field="sort-order" value="0">

                    <div class="flex items-start gap-3">
                        <button
                            type="button"
                            data-drag-handle
                            aria-label="Drag to reorder this stop"
                            class="mt-1 shrink-0 cursor-grab touch-none rounded-md p-2 text-benguet-charcoal/40 transition hover:bg-boracay-light hover:text-volcanic-teal active:cursor-grabbing"
                        >
                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <circle cx="9" cy="6" r="1.6" />
                                <circle cx="15" cy="6" r="1.6" />
                                <circle cx="9" cy="12" r="1.6" />
                                <circle cx="15" cy="12" r="1.6" />
                                <circle cx="9" cy="18" r="1.6" />
                                <circle cx="15" cy="18" r="1.6" />
                            </svg>
                        </button>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                <a
                                    data-stop-name
                                    href="{{ route('destinations.index') }}"
                                    class="text-lg font-bold text-volcanic-teal hover:text-boracay-dark"
                                >
                                    New stop
                                </a>

                                <span data-stop-cost class="text-xs font-semibold text-benguet-charcoal/60"></span>
                            </div>

                            <p data-stop-place class="mt-1 text-xs text-benguet-charcoal/55"></p>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/60">Starts</span>
                            <input
                                type="time"
                                name="items[__KEY__][start_time]"
                                data-field="start-time"
                                class="mt-1 block min-h-11 w-full rounded-md border border-boracay-light bg-palawan-sand px-3 py-2 text-sm"
                            >
                        </label>

                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/60">Ends</span>
                            <input
                                type="time"
                                name="items[__KEY__][end_time]"
                                data-field="end-time"
                                class="mt-1 block min-h-11 w-full rounded-md border border-boracay-light bg-palawan-sand px-3 py-2 text-sm"
                            >
                        </label>

                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/60">Estimated cost (₱)</span>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                name="items[__KEY__][estimated_cost]"
                                data-field="estimated-cost"
                                class="mt-1 block min-h-11 w-full rounded-md border border-boracay-light bg-palawan-sand px-3 py-2 text-sm"
                            >
                        </label>

                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/60">Note</span>
                            <input
                                type="text"
                                maxlength="500"
                                name="items[__KEY__][note]"
                                data-field="note"
                                placeholder="Optional"
                                class="mt-1 block min-h-11 w-full rounded-md border border-boracay-light bg-palawan-sand px-3 py-2 text-sm"
                            >
                        </label>
                    </div>

                    <p data-travel class="mt-3 text-xs font-semibold text-boracay-dark" hidden></p>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                        <input
                            type="hidden"
                            name="items[__KEY__][destination_id]"
                            data-field="destination-id"
                            value=""
                        >

                        <div class="flex gap-2">
                            <button
                                type="button"
                                data-move="up"
                                aria-label="Move this stop earlier"
                                class="inline-flex h-11 w-11 items-center justify-center rounded-md border border-boracay-light text-benguet-charcoal/70 transition hover:bg-boracay-light disabled:cursor-not-allowed disabled:opacity-30"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m5 15 7-7 7 7" />
                                </svg>
                            </button>

                            <button
                                type="button"
                                data-move="down"
                                aria-label="Move this stop later"
                                class="inline-flex h-11 w-11 items-center justify-center rounded-md border border-boracay-light text-benguet-charcoal/70 transition hover:bg-boracay-light disabled:cursor-not-allowed disabled:opacity-30"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7" />
                                </svg>
                            </button>

                            <button
                                type="button"
                                data-remove
                                class="inline-flex min-h-11 items-center justify-center rounded-md border border-red-200 px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-50"
                            >
                                Remove
                            </button>
                        </div>
                    </div>
                </li>
            </template>

            <div data-editor-read>
                <div class="grid gap-6 lg:grid-cols-2">
                    @foreach ($itinerary->days as $day)
                        <article class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-8">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <h3 class="text-2xl font-bold text-volcanic-teal">
                                    Day {{ $day->day_number }}
                                </h3>

                                @if ($day->date)
                                    <span class="text-sm text-benguet-charcoal/60">
                                        {{ $day->date->format('M d, Y') }}
                                    </span>
                                @endif
                            </div>

                            <div class="mt-7 space-y-6">
                                @forelse ($day->items as $item)
                                    <div class="relative border-l-2 border-boracay pl-5">
                                        <span class="absolute -left-[0.45rem] top-0 h-3 w-3 rounded-full bg-philippine-gold"></span>

                                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-boracay-dark">
                                            @if ($item->start_time && $item->end_time)
                                                {{ substr($item->start_time, 0, 5) }}
                                                –
                                                {{ substr($item->end_time, 0, 5) }}
                                            @else
                                                Flexible time
                                            @endif
                                        </p>

                                        <a
                                            href="{{ route('destinations.show', $item->destination) }}"
                                            class="mt-2 block text-xl font-bold text-volcanic-teal hover:text-boracay-dark"
                                        >
                                            {{ $item->destination->name }}
                                        </a>

                                        <p class="mt-2 text-sm text-benguet-charcoal/60">
                                            {{ $item->destination->municipality }},
                                            {{ $item->destination->province }}
                                        </p>

                                        <div class="mt-3 flex flex-wrap gap-3 text-xs font-semibold text-benguet-charcoal/60">
                                            <span>
                                                ₱{{ number_format($item->estimated_cost, 2) }}
                                            </span>

                                            @if ($item->travel_minutes_from_previous > 0)
                                                <span>
                                                    {{ $item->travel_minutes_from_previous }} min travel
                                                </span>
                                            @endif
                                        </div>

                                        @if ($item->note)
                                            <p class="mt-3 text-sm italic text-benguet-charcoal/60">
                                                {{ $item->note }}
                                            </p>
                                        @endif
                                    </div>
                                @empty
                                    <div class="rounded-2xl bg-palawan-sand p-5">
                                        <p class="text-sm text-benguet-charcoal/60">
                                            No destinations fit this day’s schedule.
                                        </p>
                                    </div>
                                @endforelse
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>

            <form
                method="POST"
                action="{{ route('itineraries.update', $itinerary) }}"
                data-editor-form
                data-schedule-url="{{ route('itineraries.schedule', $itinerary) }}"
                class="mt-6 hidden space-y-6"
                data-editor-edit
            >
                @csrf
                @method('PATCH')

                <p
                    data-editor-notice
                    data-tone="info"
                    role="status"
                    hidden
                    class="rounded-lg border border-boracay bg-boracay-light px-4 py-3 text-sm font-semibold text-volcanic-teal"
                ></p>

                <div class="grid gap-6 lg:grid-cols-2">
                    @foreach ($itinerary->days as $day)
                        <section
                            data-day-card
                            data-day-id="{{ $day->id }}"
                            data-day-number="{{ $day->day_number }}"
                            class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-8"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-2xl font-bold text-volcanic-teal">
                                        Day {{ $day->day_number }}
                                    </h3>

                                    @if ($day->date)
                                        <p class="mt-1 text-sm text-benguet-charcoal/60">
                                            {{ $day->date->format('M d, Y') }}
                                        </p>
                                    @endif
                                </div>

                                <button
                                    type="button"
                                    data-reflow
                                    class="inline-flex min-h-11 items-center justify-center rounded-md border border-philippine-gold bg-philippine-gold/20 px-3 py-2 text-xs font-bold text-benguet-charcoal transition hover:bg-philippine-gold"
                                >
                                    Reflow times
                                </button>
                            </div>

                            <ol
                                data-stop-list
                                data-day-id="{{ $day->id }}"
                                class="mt-6 space-y-4"
                            >
                                @foreach ($day->items as $item)
                                    <li
                                        data-stop
                                        data-key="{{ $item->id }}"
                                        class="rounded-xl border border-boracay-light bg-white p-4 shadow-sm"
                                    >
                                        <input type="hidden" name="items[{{ $item->id }}][day_id]" value="{{ $day->id }}">
                                        <input type="hidden" name="items[{{ $item->id }}][sort_order]" data-field="sort-order" value="{{ $item->sort_order }}">
                                        <input type="hidden" name="items[{{ $item->id }}][destination_id]" data-field="destination-id" value="{{ $item->destination_id }}">

                                        <div class="flex items-start gap-3">
                                            <button
                                                type="button"
                                                data-drag-handle
                                                aria-label="Drag to reorder {{ $item->destination->name }}"
                                                class="mt-1 shrink-0 cursor-grab touch-none rounded-md p-2 text-benguet-charcoal/40 transition hover:bg-boracay-light hover:text-volcanic-teal active:cursor-grabbing"
                                            >
                                                <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                    <circle cx="9" cy="6" r="1.6" />
                                                    <circle cx="15" cy="6" r="1.6" />
                                                    <circle cx="9" cy="12" r="1.6" />
                                                    <circle cx="15" cy="12" r="1.6" />
                                                    <circle cx="9" cy="18" r="1.6" />
                                                    <circle cx="15" cy="18" r="1.6" />
                                                </svg>
                                            </button>

                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                                    <a
                                                        data-stop-name
                                                        href="{{ route('destinations.show', $item->destination) }}"
                                                        class="text-lg font-bold text-volcanic-teal hover:text-boracay-dark"
                                                    >
                                                        {{ $item->destination->name }}
                                                    </a>

                                                    <span data-stop-cost class="text-xs font-semibold text-benguet-charcoal/60">
                                                        ₱{{ number_format($item->estimated_cost, 2) }}
                                                    </span>
                                                </div>

                                                <p data-stop-place class="mt-1 text-xs text-benguet-charcoal/55">
                                                    {{ $item->destination->municipality }},
                                                    {{ $item->destination->province }}
                                                </p>
                                            </div>
                                        </div>

                                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                            <label class="block">
                                                <span class="text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/60">Starts</span>
                                                <input
                                                    type="time"
                                                    name="items[{{ $item->id }}][start_time]"
                                                    data-field="start-time"
                                                    value="{{ $item->start_time ? substr($item->start_time, 0, 5) : '' }}"
                                                    class="mt-1 block min-h-11 w-full rounded-md border border-boracay-light bg-palawan-sand px-3 py-2 text-sm"
                                                >
                                            </label>

                                            <label class="block">
                                                <span class="text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/60">Ends</span>
                                                <input
                                                    type="time"
                                                    name="items[{{ $item->id }}][end_time]"
                                                    data-field="end-time"
                                                    value="{{ $item->end_time ? substr($item->end_time, 0, 5) : '' }}"
                                                    class="mt-1 block min-h-11 w-full rounded-md border border-boracay-light bg-palawan-sand px-3 py-2 text-sm"
                                                >
                                            </label>

                                            <label class="block">
                                                <span class="text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/60">Estimated cost (₱)</span>
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    name="items[{{ $item->id }}][estimated_cost]"
                                                    data-field="estimated-cost"
                                                    value="{{ number_format((float) $item->estimated_cost, 2, '.', '') }}"
                                                    class="mt-1 block min-h-11 w-full rounded-md border border-boracay-light bg-palawan-sand px-3 py-2 text-sm"
                                                >
                                            </label>

                                            <label class="block">
                                                <span class="text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/60">Note</span>
                                                <input
                                                    type="text"
                                                    maxlength="500"
                                                    name="items[{{ $item->id }}][note]"
                                                    data-field="note"
                                                    value="{{ $item->note }}"
                                                    placeholder="Optional"
                                                    class="mt-1 block min-h-11 w-full rounded-md border border-boracay-light bg-palawan-sand px-3 py-2 text-sm"
                                                >
                                            </label>
                                        </div>

                                        <p data-travel class="mt-3 text-xs font-semibold text-boracay-dark" hidden></p>

                                        <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
                                            <button
                                                type="button"
                                                data-move="up"
                                                aria-label="Move {{ $item->destination->name }} earlier"
                                                class="inline-flex h-11 w-11 items-center justify-center rounded-md border border-boracay-light text-benguet-charcoal/70 transition hover:bg-boracay-light disabled:cursor-not-allowed disabled:opacity-30"
                                            >
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m5 15 7-7 7 7" />
                                                </svg>
                                            </button>

                                            <button
                                                type="button"
                                                data-move="down"
                                                aria-label="Move {{ $item->destination->name }} later"
                                                class="inline-flex h-11 w-11 items-center justify-center rounded-md border border-boracay-light text-benguet-charcoal/70 transition hover:bg-boracay-light disabled:cursor-not-allowed disabled:opacity-30"
                                            >
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7" />
                                                </svg>
                                            </button>

                                            <button
                                                type="button"
                                                data-remove
                                                class="inline-flex min-h-11 items-center justify-center rounded-md border border-red-200 px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-50"
                                            >
                                                Remove
                                            </button>
                                        </div>
                                    </li>
                                @endforeach
                            </ol>

                            <button
                                type="button"
                                data-add
                                class="mt-5 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-md border border-dashed border-boracay px-4 py-3 text-sm font-semibold text-boracay-dark transition hover:bg-boracay-light"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
                                </svg>
                                Add a stop
                            </button>
                        </section>
                    @endforeach
                </div>

                <div class="sticky bottom-4 z-20 flex flex-wrap justify-end gap-3">
                    <button
                        type="button"
                        data-editor-cancel
                        class="inline-flex min-h-11 items-center justify-center rounded-lg border border-benguet-charcoal/30 bg-white px-5 py-3 text-sm font-semibold text-benguet-charcoal transition hover:bg-palawan-sand"
                    >
                        Discard changes
                    </button>

                    <button
                        type="submit"
                        class="inline-flex min-h-11 items-center justify-center rounded-lg bg-boracay px-6 py-3 text-sm font-bold text-benguet-charcoal transition hover:bg-boracay-dark hover:text-white"
                    >
                        Save itinerary
                    </button>
                </div>
            </form>

            <dialog
                data-add-dialog
                aria-labelledby="add-stop-title"
                class="m-auto max-h-[85vh] w-[calc(100%-2rem)] max-w-2xl rounded-lg border border-boracay-light bg-palawan-sand p-0 text-benguet-charcoal shadow-2xl backdrop:bg-volcanic-teal/60 backdrop:backdrop-blur-sm"
            >
                <div class="flex max-h-[85vh] flex-col">
                    <div class="border-b border-boracay-light p-6">
                        <h2 id="add-stop-title" class="font-display text-2xl font-semibold text-volcanic-teal">
                            Add a place you liked
                        </h2>

                        <p class="mt-2 text-sm leading-6 text-benguet-charcoal/70">
                            These are the places you liked that this trip does not visit yet. Pick as many as you want and say which day each one goes on.
                        </p>

                        <div class="mt-5">
                            <span class="text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/60">Add to</span>

                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($itinerary->days as $day)
                                    <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-md border border-boracay-light bg-white px-4 py-2 text-sm font-semibold has-checked:border-boracay has-checked:bg-boracay-light">
                                        <input
                                            type="radio"
                                            name="add-target-day"
                                            value="{{ $day->id }}"
                                            data-day-option
                                            class="h-4 w-4 accent-boracay"
                                        >
                                        Day {{ $day->day_number }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto p-6">
                        @forelse ($addableDestinations as $destination)
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-transparent px-3 py-3 transition hover:border-boracay-light hover:bg-white has-checked:border-boracay has-checked:bg-white">
                                <input
                                    type="checkbox"
                                    data-destination="{{ $destination->id }}"
                                    data-name="{{ $destination->name }}"
                                    data-place="{{ $destination->municipality }}, {{ $destination->province }}"
                                    data-cost="{{ number_format((float) $destination->estimated_cost, 2, '.', '') }}"
                                    data-fee="₱{{ number_format((float) $destination->estimated_cost, 2) }}"
                                    data-url="{{ route('destinations.show', $destination) }}"
                                    class="mt-1 h-5 w-5 shrink-0 accent-boracay"
                                >

                                <span class="min-w-0 flex-1">
                                    <span class="block font-bold text-volcanic-teal">{{ $destination->name }}</span>
                                    <span class="mt-0.5 block text-sm text-benguet-charcoal/60">
                                        {{ $destination->municipality }}, {{ $destination->province }}
                                    </span>
                                </span>

                                <span class="shrink-0 text-sm font-semibold text-benguet-charcoal/70">
                                    ₱{{ number_format((float) $destination->estimated_cost, 2) }}
                                </span>
                            </label>
                        @empty
                            <p class="rounded-lg bg-white p-6 text-sm leading-6 text-benguet-charcoal/70">
                                Every place you liked is already on this trip. Swipe a few more in Discover to add somewhere new.
                            </p>
                        @endforelse
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-boracay-light p-6">
                        <button
                            type="button"
                            onclick="this.closest('dialog').close()"
                            class="inline-flex min-h-11 items-center justify-center rounded-lg border border-benguet-charcoal/30 px-4 py-2 text-sm font-semibold text-benguet-charcoal transition hover:bg-white"
                        >
                            Cancel
                        </button>

                        <button
                            type="button"
                            data-add-confirm
                            disabled
                            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-boracay px-5 py-3 text-sm font-bold text-benguet-charcoal transition hover:bg-boracay-dark hover:text-white disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            Add <span data-add-count>0</span> more
                        </button>
                    </div>
                </div>
            </dialog>
        </section>
    </div>
</x-app-layout>