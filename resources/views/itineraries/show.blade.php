@push('scripts')
    @vite(['resources/js/itinerary-editor.js'])

    <script>
    document.getElementById('llm-suggest-btn')?.addEventListener('click', async function () {
        const results = document.getElementById('llm-suggest-results');
        const button = this;
        button.disabled = true;
        results.innerHTML = '<p class="text-sm text-benguet-charcoal/60">Asking for suggestions&hellip;</p>';

        try {
            const response = await fetch('{{ route('itineraries.llm-suggest', $itinerary) }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error('Request failed');
            }

            const data = await response.json();
            results.innerHTML = '';

            if (!Array.isArray(data.suggestions) || data.suggestions.length === 0) {
                results.innerHTML = '<p class="text-sm text-benguet-charcoal/60">No suggestions came back. Try again later.</p>';
                return;
            }

            data.suggestions.forEach(function (suggestion) {
                const place = suggestion.place || {};
                const card = document.createElement('div');
                card.className = 'rounded-xl border border-boracay-light bg-white p-4 shadow-sm';

                const header = document.createElement('div');
                header.className = 'flex items-start justify-between gap-3';

                const title = document.createElement('div');
                title.className = 'font-semibold text-volcanic-teal';
                title.textContent = place.name || 'Unknown place';

                const kind = document.createElement('span');
                kind.className = 'shrink-0 rounded-full bg-boracay-light px-2 py-1 text-xs font-semibold text-boracay-dark';
                kind.textContent = place.type === 'lodging' ? 'Stay' : 'Eat';

                header.append(title, kind);

                const area = document.createElement('div');
                area.className = 'mt-0.5 text-xs text-benguet-charcoal/55';
                area.textContent = [place.municipality, place.province].filter(Boolean).join(', ');

                const reason = document.createElement('p');
                reason.className = 'mt-2 text-sm text-benguet-charcoal/70';
                reason.textContent = suggestion.reason || '';

                const label = document.createElement('p');
                label.className = 'mt-2 text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/45';
                label.textContent = "The model's own words, unchecked";

                const actions = document.createElement('div');
                actions.className = 'mt-3 flex flex-wrap items-center gap-2';

                // One day-select per suggestion card: the trip's own days,
                // posted with the place id to the hot-add endpoint. The server
                // re-validates everything (owner, day, duplicates, province).
                const daySelect = document.createElement('select');
                daySelect.className = 'min-h-9 rounded-md border border-boracay-light bg-palawan-sand px-2 py-1 text-sm';
                daySelect.setAttribute('aria-label', 'Day to add ' + (place.name || 'this place') + ' to');

                @foreach ($itinerary->days as $day)
                    const day{{ $day->id }} = document.createElement('option');
                    day{{ $day->id }}.value = '{{ $day->id }}';
                    day{{ $day->id }}.textContent = 'Day {{ $day->day_number }}';
                    daySelect.appendChild(day{{ $day->id }});
                @endforeach

                const add = document.createElement('button');
                add.type = 'button';
                add.className = 'inline-flex min-h-9 items-center rounded-md bg-boracay px-3 py-1 text-sm font-bold text-benguet-charcoal transition hover:bg-boracay-dark hover:text-white disabled:cursor-not-allowed disabled:opacity-40';
                add.textContent = 'Add';

                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';

                add.addEventListener('click', async function () {
                    add.disabled = true;

                    const body = new FormData();
                    body.append('day_id', daySelect.value);
                    body.append('_token', csrf);

                    try {
                        const response = await fetch('/itineraries/{{ $itinerary->id }}/places/' + encodeURIComponent(place.id) + '/add', {
                            method: 'POST',
                            body,
                        });

                        if (response.ok) {
                            window.location.reload();

                            return;
                        }

                        const errors = await response.json().catch(() => null);
                        const message = errors?.message || errors?.errors?.day_id?.[0] || 'Could not be added.';
                        add.textContent = message;
                    } catch (error) {
                        add.textContent = 'Could not be added.';
                        add.disabled = false;
                    }
                });

                actions.append(daySelect, add);

                card.append(header, area, reason, label, actions);
                results.appendChild(card);
            });
        } catch (error) {
            results.innerHTML = '<p class="text-sm text-red-700">Suggestions could not be loaded. Please try again.</p>';
        } finally {
            button.disabled = false;
        }
    });
    </script>
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

    @include('itineraries.partials.trip')

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

                <div class="flex flex-wrap items-center gap-3">
                    {{-- Its own form, deliberately outside the editor's. A day is a
                         row that must exist before a stop can be filed against it,
                         so it cannot ride the draft payload the editor saves. --}}
                    <form method="POST" action="{{ route('itineraries.days.store', $itinerary) }}">
                        @csrf

                        <button
                            type="submit"
                            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-boracay px-5 py-3 text-sm font-semibold text-volcanic-teal transition hover:bg-boracay hover:text-white"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
                            </svg>
                            Add a day
                        </button>
                    </form>

                    <button
                        type="button"
                        data-editor-toggle
                        aria-pressed="false"
                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-boracay px-5 py-3 text-sm font-semibold text-volcanic-teal transition hover:bg-boracay hover:text-white"
                    >
                        Edit itinerary
                    </button>

                    {{-- The LLM restaurant/hotel picker. --}}
                    <button
                        type="button"
                        id="llm-suggest-btn"
                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-volcanic-teal px-5 py-3 text-sm font-semibold text-white transition hover:bg-boracay-dark"
                    >
                        Suggest restaurants/hotels with AI
                    </button>

                    {{--
                        THE SHARE LINK. It is an owner-only page here, never
                        this trip's guest rendering: the partial's hero is
                        shared with the public link, so an entry point in
                        there would render a minting button to strangers.
                    --}}
                    <a
                        href="{{ route('itineraries.share', $itinerary) }}"
                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-boracay px-5 py-3 text-sm font-semibold text-volcanic-teal transition hover:bg-boracay hover:text-white"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5M10 13.828a4 4 0 010-5.656l3-3a4 4 0 015.656 5.656l-1.5 1.5" />
                        </svg>
                        Share
                    </a>

                    {{--
                        The PDF export.

                        A plain GET link, not a form or a fetch: the browser
                        handles the Content-Disposition itself, so this works
                        with scripting off and needs no JavaScript at all.
                        Inside the section header rather than the editor FORM
                        on purpose -- a button in here would submit the draft
                        payload, and a form inside the editor would be
                        discarded by the HTML parser and take every stop field
                        with it.
                    --}}
                    {{--
                        THE CALENDAR FILE, ONLY WHEN THERE ARE DATES.
                        An undated trip has no day to put anything on, so the
                        control is not rendered at all — a button whose only
                        possible outcome is a refusal is worse than no button,
                        and `calendar()` still explains itself if the URL is
                        typed by hand.
                    --}}
                    @if ($itinerary->start_date)
                        <a
                            href="{{ route('itineraries.calendar', $itinerary) }}"
                            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-boracay px-5 py-3 text-sm font-semibold text-volcanic-teal transition hover:bg-boracay hover:text-white"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z" />
                            </svg>
                            Add to calendar
                        </a>
                    @endif

                    <a
                        href="{{ route('itineraries.download', $itinerary) }}"
                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-boracay px-5 py-3 text-sm font-semibold text-volcanic-teal transition hover:bg-boracay hover:text-white"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2" />
                        </svg>
                        Download PDF
                    </a>
                </div>
            </div>

            {{-- The AI's restaurant and hotel picks render here once the
                 llm-suggest call has answered. --}}
            <div id="llm-suggest-results" class="mb-8 space-y-3"></div>

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

                        {{-- No cost input. It is curated catalogue data, not the
                             traveller's to change: a stored stop keeps what it
                             was generated with, a new one takes the
                             destination's own figure. See ItineraryEditor. --}}
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

                        <input type="hidden" name="items[__KEY__][item_type]" data-field="item-type" value="attraction">
                        <input type="hidden" name="items[__KEY__][place_id]" data-field="place-id" value="">
                        <input type="hidden" name="items[__KEY__][custom_name]" data-field="custom-name" value="">
                        <input type="hidden" name="items[__KEY__][custom_address]" data-field="custom-address" value="">
                        <input type="hidden" name="items[__KEY__][custom_latitude]" data-field="custom-latitude" value="">
                        <input type="hidden" name="items[__KEY__][custom_longitude]" data-field="custom-longitude" value="">

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

                @include('itineraries.partials.days')

            <form
                method="POST"
                action="{{ route('itineraries.update', $itinerary) }}"
                data-editor-form
                data-schedule-url="{{ route('itineraries.schedule', $itinerary) }}"
                class="mt-6 space-y-6"
                data-editor-edit
                hidden
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

                                <div class="flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        data-reflow
                                        class="inline-flex min-h-11 items-center justify-center rounded-md border border-philippine-gold bg-philippine-gold/20 px-3 py-2 text-xs font-bold text-benguet-charcoal transition hover:bg-philippine-gold"
                                    >
                                        Reflow times
                                    </button>

                                    {{-- The form this button submits to lives after the
                                         editor's, because a form inside it would be
                                         swallowed by the HTML parser and take every
                                         stop field with it. The `form` attribute
                                         binds the two without nesting. Hidden on a
                                         one-day trip, which cannot lose its only
                                         day -- see ItineraryEditor::removeDay(). --}}
                                    @if ($itinerary->days->count() > 1)
                                        <button
                                            type="submit"
                                            form="remove-day-{{ $day->id }}"
                                            class="inline-flex min-h-11 items-center justify-center rounded-md border border-red-200 px-3 py-2 text-xs font-bold text-red-700 transition hover:bg-red-50"
                                        >
                                            Remove day
                                        </button>
                                    @endif
                                </div>
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
                                        {{-- data-field="day-id" is what makes cross-day dragging
                                             possible: itinerary-editor.js rewrites this value when a row
                                             is dropped into a different day's list. The template row had
                                             the hook and the rendered rows did not, so a row that arrived
                                             with the page could be added and reordered but never
                                             moved between days. --}}
                                        <input type="hidden" name="items[{{ $item->id }}][day_id]" data-field="day-id" value="{{ $day->id }}">
                                        <input type="hidden" name="items[{{ $item->id }}][sort_order]" data-field="sort-order" value="{{ $item->sort_order }}">
                                        <input type="hidden" name="items[{{ $item->id }}][destination_id]" data-field="destination-id" value="{{ $item->destination_id }}">
                                        <input type="hidden" name="items[{{ $item->id }}][item_type]" data-field="item-type" value="{{ $item->item_type }}">
                                        <input type="hidden" name="items[{{ $item->id }}][place_id]" data-field="place-id" value="{{ $item->place_id }}">
                                        <input type="hidden" name="items[{{ $item->id }}][custom_name]" data-field="custom-name" value="{{ $item->custom_name }}">
                                        <input type="hidden" name="items[{{ $item->id }}][custom_address]" data-field="custom-address" value="{{ $item->custom_address }}">
                                        <input type="hidden" name="items[{{ $item->id }}][custom_latitude]" data-field="custom-latitude" value="{{ $item->custom_latitude }}">
                                        <input type="hidden" name="items[{{ $item->id }}][custom_longitude]" data-field="custom-longitude" value="{{ $item->custom_longitude }}">

                                        <div class="flex items-start gap-3">
                                            <button
                                                type="button"
                                                data-drag-handle
                                                aria-label="Drag to reorder {{ $item->placeName() }}"
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
                                                    @if ($item->isAttraction() && $item->destination)
                                                        <a
                                                            data-stop-name
                                                            href="{{ route('destinations.show', $item->destination) }}"
                                                            class="text-lg font-bold text-volcanic-teal hover:text-boracay-dark"
                                                        >
                                                            {{ $item->placeName() }}
                                                        </a>
                                                    @else
                                                        <span
                                                            data-stop-name
                                                            class="text-lg font-bold text-volcanic-teal"
                                                        >
                                                            {{ $item->placeName() }}
                                                        </span>
                                                    @endif

                                                    {{-- costLabel(), not a bare ₱0.00: a
                                                         place with no verified band prints
                                                         "Price not listed" and a free one
                                                         says "Free". --}}
                                                    <span data-stop-cost class="text-xs font-semibold text-benguet-charcoal/60">
                                                        {{ $item->costLabel() }}
                                                    </span>
                                                </div>

                                                <p data-stop-place class="mt-1 text-xs text-benguet-charcoal/55">
                                                    @if ($item->isCustom())
                                                        {{ $item->custom_address ?: 'Your own stop' }}
                                                    @elseif ($item->place)
                                                        {{ $item->place->municipality }}, {{ $item->place->province }}
                                                    @elseif ($item->destination)
                                                        {{ $item->destination->municipality }}, {{ $item->destination->province }}
                                                    @endif
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

                                            {{-- No cost input, same reason as the template row. The price shown in the
                                                 row header is the stored one and
                                                 stays put. --}}
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
                                                aria-label="Move {{ $item->placeName() }} earlier"
                                                class="inline-flex h-11 w-11 items-center justify-center rounded-md border border-boracay-light text-benguet-charcoal/70 transition hover:bg-boracay-light disabled:cursor-not-allowed disabled:opacity-30"
                                            >
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m5 15 7-7 7 7" />
                                                </svg>
                                            </button>

                                            <button
                                                type="button"
                                                data-move="down"
                                                aria-label="Move {{ $item->placeName() }} later"
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

            {{-- One remove-a-day form per day, here rather than inside the
                 editor's form above. A nested <form> is discarded by the HTML
                 parser and the first inner </form> closes the *outer* form, so a
                 button inside the editor pointing at a form beside it is the only
                 arrangement that posts where it looks like it posts. Each is
                 hidden with Tailwind's hidden class on purpose: unlike the editor
                 itself, nothing ever shows these, so display:none is what we want
                 rather than the trap described in AGENTS.md. --}}
            @foreach ($itinerary->days as $day)
                <form
                    id="remove-day-{{ $day->id }}"
                    method="POST"
                    action="{{ route('itineraries.days.destroy', [$itinerary, $day]) }}"
                    class="hidden"
                >
                    @csrf
                    @method('DELETE')
                </form>
            @endforeach

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
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-benguet-charcoal/55">
                            Attractions you liked
                        </p>

                        {{-- Cards, not rows: two per row on desktop, each with
                             its fee and location visible without scanning a
                             single column. --}}
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @forelse ($addableDestinations as $destination)
                            <label class="flex cursor-pointer flex-col gap-2 rounded-xl border border-transparent bg-white p-4 shadow-sm transition hover:border-boracay hover:shadow has-checked:border-boracay has-checked:ring-2 has-checked:ring-boracay">
                                <span class="flex items-start gap-3">
                                    <input
                                        type="checkbox"
                                        data-destination="{{ $destination->id }}"
                                        data-name="{{ $destination->name }}"
                                        {{-- data-location, NOT data-place. A place's
                                             checkbox uses data-place for its ID, and
                                             an attraction used to use data-place
                                             for this location string — so the
                                             script could not tell the two sets
                                             apart except by which other
                                             attributes were present. Same
                                             attribute name, two incompatible
                                             meanings, on sibling elements. --}}
                                        data-location="{{ $destination->municipality }}, {{ $destination->province }}"
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
                                </span>

                                <span class="pl-8 text-sm font-semibold text-benguet-charcoal/70">
                                    ₱{{ number_format((float) $destination->estimated_cost, 2) }}
                                </span>
                            </label>
                        @empty
                            <p class="rounded-lg bg-white p-6 text-sm leading-6 text-benguet-charcoal/70 sm:col-span-2">
                                Every place you liked is already on this trip. Swipe a few more in Discover to add somewhere new.
                            </p>
                        @endforelse
                        </div>

                        {{-- Food and lodging in this trip's province. No liked
                             rule: places have no swipes, so the picker lists
                             active rows the same way the editor accepts them. --}}
                        <p class="mt-6 text-xs font-bold uppercase tracking-[0.2em] text-benguet-charcoal/55">
                            Where to eat and stay
                        </p>

                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @forelse ($addablePlaces as $place)
                                <label class="flex cursor-pointer flex-col gap-2 rounded-xl border border-transparent bg-white p-4 shadow-sm transition hover:border-boracay hover:shadow has-checked:border-boracay has-checked:ring-2 has-checked:ring-boracay">
                                    <span class="flex items-start gap-3">
                                        <input
                                            type="checkbox"
                                            data-place="{{ $place->id }}"
                                            data-kind="{{ $place->isLodging() ? 'lodging' : 'meal' }}"
                                            data-name="{{ $place->name }}"
                                            data-place-label="{{ $place->municipality }}, {{ $place->province }}"
                                            data-fee="{{ $place->priceLabel() }}"
                                            data-url="{{ $place->source_url }}"
                                            class="mt-1 h-5 w-5 shrink-0 accent-boracay"
                                        >

                                        <span class="min-w-0 flex-1">
                                            <span class="block font-bold text-volcanic-teal">{{ $place->name }}</span>
                                            <span class="mt-0.5 block text-sm text-benguet-charcoal/60">
                                                {{ $place->typeLabel() }} · {{ $place->municipality }}, {{ $place->province }}
                                            </span>
                                        </span>
                                    </span>

                                    {{-- priceLabel(), never a bare number: "Price
                                         not listed" is a real answer, and a
                                         band always carries its unit. --}}
                                    <span class="pl-8 text-sm font-semibold text-benguet-charcoal/70">
                                        {{ $place->priceLabel() }}
                                    </span>
                                </label>
                            @empty
                                <p class="rounded-lg bg-white p-6 text-sm leading-6 text-benguet-charcoal/70 sm:col-span-2">
                                    Nothing curated here yet. Add your own booking below instead.
                                </p>
                            @endforelse
                        </div>

                        {{-- The traveller's own booking: a hotel already
                             reserved, a restaurant someone recommended. It has
                             no catalogue row, so it is typed here rather than
                             picked — and it posts the same draft shape under a
                             browser-invented key. --}}
                        <div class="mt-6 rounded-lg border border-boracay-light bg-white p-4">
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-benguet-charcoal/55">
                                Your own stop
                            </p>

                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <label class="block sm:col-span-2">
                                    <span class="text-xs font-semibold text-benguet-charcoal/60">Place name *</span>
                                    <input
                                        type="text"
                                        maxlength="150"
                                        data-custom-name
                                        placeholder="The hotel you already booked"
                                        class="mt-1 block min-h-11 w-full rounded-md border border-boracay-light bg-palawan-sand px-3 py-2 text-sm"
                                    >
                                </label>

                                <label class="block sm:col-span-2">
                                    <span class="text-xs font-semibold text-benguet-charcoal/60">Address</span>
                                    <input
                                        type="text"
                                        maxlength="255"
                                        data-custom-address
                                        placeholder="Street, town"
                                        class="mt-1 block min-h-11 w-full rounded-md border border-boracay-light bg-palawan-sand px-3 py-2 text-sm"
                                    >
                                </label>

                                <p class="text-xs text-benguet-charcoal/55 sm:col-span-2">
                                    Without a map pin this stop cannot be routed to — the leg will say so rather than guess.
                                </p>

                                <label class="block">
                                    <span class="text-xs font-semibold text-benguet-charcoal/60">Latitude</span>
                                    <input
                                        type="number"
                                        step="any"
                                        data-custom-latitude
                                        placeholder="16.4120"
                                        class="mt-1 block min-h-11 w-full rounded-md border border-boracay-light bg-palawan-sand px-3 py-2 text-sm"
                                    >
                                </label>

                                <label class="block">
                                    <span class="text-xs font-semibold text-benguet-charcoal/60">Longitude</span>
                                    <input
                                        type="number"
                                        step="any"
                                        data-custom-longitude
                                        placeholder="120.5960"
                                        class="mt-1 block min-h-11 w-full rounded-md border border-boracay-light bg-palawan-sand px-3 py-2 text-sm"
                                    >
                                </label>
                            </div>

                            <button
                                type="button"
                                data-add-custom
                                class="mt-4 inline-flex min-h-11 items-center justify-center rounded-md border border-boracay px-4 py-2 text-sm font-semibold text-boracay-dark transition hover:bg-boracay-light"
                            >
                                Add this stop
                            </button>
                        </div>
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