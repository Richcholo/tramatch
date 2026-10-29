<x-app-layout>
    <x-slot name="header">
        <a
            href="{{ route('itineraries.index') }}"
            class="text-sm font-semibold text-boracay-dark hover:text-volcanic-teal"
        >
            ← Back to my itineraries
        </a>
    </x-slot>

    <div class="mx-auto max-w-5xl">
        <div class="mb-10">
            <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                04 / Make it a plan
            </p>

            <h1 class="mt-4 font-display text-5xl font-semibold leading-[0.95] tracking-[-0.05em] text-volcanic-teal sm:text-7xl">
                Choose the places.
                We’ll help shape the day.
            </h1>

            <p class="mt-6 max-w-2xl text-lg leading-8 text-benguet-charcoal/70">
                Select an area, choose the exact destinations you want, and keep control of the final itinerary.
            </p>
        </div>

        <form
            method="GET"
            action="{{ route('itineraries.create') }}"
            class="rounded-[2rem] bg-volcanic-teal p-6 shadow-xl sm:p-8"
        >
            <label class="block">
                <span class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-light">
                    Choose an area
                </span>

                <div class="mt-3 flex flex-wrap gap-3">
                    <select
                        name="area"
                        class="min-w-0 flex-1 rounded-xl border-white/20 bg-white/10 text-white focus:border-philippine-gold focus:ring-philippine-gold"
                    >
                        <option value="" class="text-benguet-charcoal">
                            Select a province
                        </option>

                        @foreach ($areas as $availableArea)
                            <option
                                value="{{ $availableArea }}"
                                class="text-benguet-charcoal"
                                @selected($area === $availableArea)
                            >
                                {{ $availableArea }}
                            </option>
                        @endforeach
                    </select>

                    <button
                        type="submit"
                        class="rounded-xl bg-philippine-gold px-5 py-3 font-bold text-benguet-charcoal transition hover:bg-white active:scale-[0.98] active:duration-75"
                    >
                        Load places
                    </button>
                </div>
            </label>
        </form>

        @if ($area !== '')
            <form
                method="POST"
                action="{{ route('itineraries.store') }}"
                data-itinerary-form
                class="mt-8 space-y-8"
            >
                @csrf

                <input
                    type="hidden"
                    name="area"
                    value="{{ $area }}"
                >

                <section class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-10">
                    <div class="grid gap-6 md:grid-cols-3">
                        <label class="block">
                            <span class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                                Itinerary title
                            </span>

                            <input
                                name="title"
                                value="{{ old('title', 'My ' . $area . ' trip') }}"
                                required
                                class="mt-3 w-full rounded-xl border-boracay-light bg-palawan-sand focus:border-boracay focus:ring-boracay"
                                placeholder="Example: A slow weekend in Pampanga"
                            >
                        </label>

                        <label class="block">
                            <span class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                                Start date
                            </span>

                            <input
                                type="date"
                                name="start_date"
                                value="{{ old('start_date') }}"
                                min="{{ now()->toDateString() }}"
                                class="mt-3 w-full rounded-xl border-boracay-light bg-palawan-sand focus:border-boracay focus:ring-boracay"
                            >
                        </label>

                        <label class="block">
                            <span class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                                Days
                            </span>

                            <input
                                type="number"
                                name="days"
                                value="{{ old('days', $profile->trip_duration_days) }}"
                                min="1"
                                max="{{ $maxDays }}"
                                inputmode="numeric"
                                class="mt-3 w-full rounded-xl border-boracay-light bg-palawan-sand focus:border-boracay focus:ring-boracay"
                            >

                            <span class="mt-2 block text-xs leading-5 text-benguet-charcoal/60">
                                Defaults to the {{ $profile->trip_duration_days }} {{ $profile->trip_duration_days === 1 ? 'day' : 'days' }} set in your preferences. More days than stops leaves the extra days open.
                            </span>
                        </label>
                    </div>
                </section>

                <section class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-10">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                                {{ $area }} / Eligible places
                            </p>

                            <h2 class="mt-3 font-display text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal">
                                Choose your stops.
                            </h2>

                            <p class="mt-3 text-sm leading-6 text-benguet-charcoal/65">
                                {{-- Was "liked destinations that match your budget and at
                                     least one of your interests", which is no longer
                                     true of this list and was quietly load-bearing:
                                     a traveller who liked five places saw five
                                     tick-boxes and could not build a week. --}}
                                Everything in {{ $area }} at your budget, the ones you liked first.
                                The match score is how closely each one fits your interests.
                            </p>
                        </div>

                        <span class="tm-gold-badge">
                            {{ $destinations->count() }} available
                        </span>
                    </div>

                    <div class="mt-8 space-y-3">
                        @forelse ($destinations as $destination)
                            <label
                                data-destination-option
                                data-destination-id="{{ $destination->id }}"
                                data-destination-name="{{ $destination->name }}"
                                class="flex cursor-pointer items-center gap-4 rounded-2xl border border-boracay-light bg-palawan-sand p-4 transition hover:border-boracay"
                            >
                                <input
                                    type="checkbox"
                                    value="{{ $destination->id }}"
                                    data-destination-checkbox
                                    @checked(in_array($destination->id, $selectedIds, true))
                                    class="h-5 w-5 rounded border-boracay text-boracay focus:ring-boracay"
                                >

                                <span class="min-w-0 flex-1">
                                    <span class="block font-bold text-volcanic-teal">
                                        {{ $destination->name }}

                                        {{-- Liked ones sort to the top, and this
                                             says why they are there. Without it the
                                             list looks arbitrary: the traveller
                                             swiped five places and cannot tell
                                             which of these twenty they were. --}}
                                        @if ($destination->is_liked ?? false)
                                            <span class="ml-1 align-middle text-xs font-semibold uppercase tracking-wide text-boracay-dark">
                                                Liked
                                            </span>
                                        @endif
                                    </span>

                                    <span class="mt-1 block text-sm text-benguet-charcoal/60">
                                        {{ $destination->municipality }},
                                        {{ $destination->province }}
                                        · {{ $destination->match_score }}% match
                                    </span>

                                    {{-- WHY THAT NUMBER, ONLY WHERE IT IS UNEXPLAINED.
                                         A candidate carrying shared interests shows
                                         its tag chips immediately below, so the
                                         score has visible reasons. A candidate
                                         with NO chips is the one row where a bare
                                         "30% match" stands alone — and 30 is the
                                         floor every place starts on, not a verdict.
                                         The sentence comes from the same presenter
                                         the recommendations page uses. --}}
                                    @if (($destination->matched_tags ?? collect())->isEmpty())
                                        <span class="mt-1 block text-xs text-benguet-charcoal/55">
                                            {{ $destination->match_explanation['reason'] ?? '' }}
                                        </span>
                                    @endif

                                    <span class="mt-2 flex flex-wrap gap-2">
                                        @foreach ($destination->matched_tags as $tag)
                                            <span class="rounded-full bg-boracay-light px-2 py-1 text-xs font-semibold text-boracay-dark">
                                                {{ $tag->name }}
                                            </span>
                                        @endforeach
                                    </span>
                                </span>

                                <span class="tm-gold-badge whitespace-nowrap">
                                    ₱{{ number_format($destination->estimated_cost, 2) }}
                                </span>
                            </label>
                        @empty
                            <div class="rounded-2xl bg-palawan-sand p-6">
                                <p class="text-sm text-benguet-charcoal/65">
                                    No liked destinations match this area yet. Try another province or return to Discover.
                                </p>
                            </div>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-10">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                                Not sure which to keep?
                            </p>

                            <h2 class="mt-3 font-display text-3xl font-semibold tracking-[-0.04em] text-volcanic-teal">
                                Ask for a second opinion.
                            </h2>

                            <p class="mt-3 text-sm leading-6 text-benguet-charcoal/65">
                                The assistant ranks YOUR shortlist and gives one sentence per pick. It suggests — you decide; every fact on a card comes from our catalogue, and its words are its own.
                            </p>
                        </div>

                        <button
                            type="button"
                            id="generation-assist-btn"
                            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-volcanic-teal px-5 py-3 text-sm font-semibold text-white transition hover:bg-boracay-dark disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            Suggest my best picks
                        </button>
                    </div>

                    <div id="generation-assist-results" class="mt-6 grid gap-3 sm:grid-cols-2"></div>
                </section>

                <section class="rounded-[2rem] bg-volcanic-teal p-6 text-white shadow-xl sm:p-10">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-light">
                                Selected order
                            </p>

                            <h2 class="mt-3 font-display text-4xl font-semibold">
                                Your stops, your sequence.
                            </h2>
                        </div>

                        <span class="text-sm text-white/55">
                            Move stops before generating.
                        </span>
                    </div>

                    <div
                        data-selected-destinations
                        class="mt-8 min-h-20 space-y-3"
                    ></div>

                    <div class="mt-8 flex flex-wrap items-center justify-between gap-4 border-t border-white/15 pt-8">
                        <p class="text-sm leading-6 text-white/65">
                            TraMatch will help fit your selected places into each day without adding destinations you did not choose.
                        </p>

                        <button
                            type="submit"
                            class="rounded-full bg-philippine-gold px-6 py-3 font-bold text-benguet-charcoal transition hover:bg-white active:scale-[0.98] active:duration-75"
                        >
                            Generate itinerary →
                        </button>
                    </div>
                </section>
            </form>
        @else
            <section class="mt-8 rounded-[2rem] bg-island-white p-10 text-center shadow-sm ring-1 ring-boracay-light">
                <p class="text-xs font-bold uppercase tracking-[0.25em] text-boracay-dark">
                    Choose your direction
                </p>

                <h2 class="mt-5 font-display text-4xl font-semibold text-volcanic-teal">
                    Start with a province.
                </h2>

                <p class="mx-auto mt-4 max-w-xl leading-7 text-benguet-charcoal/65">
                    Every place we have in that province will appear once you choose an area.
                </p>
            </section>
        @endif
    </div>

    <script>
        const destinationOptions = Array.from(
            document.querySelectorAll('[data-destination-option]')
        );

        const destinationCheckboxes = Array.from(
            document.querySelectorAll('[data-destination-checkbox]')
        );

        const selectedContainer = document.querySelector(
            '[data-selected-destinations]'
        );

        const itineraryForm = document.querySelector(
            '[data-itinerary-form]'
        );

        const initialSelectedIds = @json($selectedIds);

        let selectedOrder = initialSelectedIds
            .map((id) => String(id))
            .filter((id) => {
                return destinationOptions.some(
                    (option) => option.dataset.destinationId === id
                );
            });

        const getOption = (id) => {
            return destinationOptions.find(
                (option) => option.dataset.destinationId === id
            );
        };

        const renderSelectedDestinations = () => {
            if (!selectedContainer) {
                return;
            }

            selectedContainer.innerHTML = '';

            destinationCheckboxes.forEach((checkbox) => {
                checkbox.checked = selectedOrder.includes(
                    String(checkbox.value)
                );
            });

            if (selectedOrder.length === 0) {
                const empty = document.createElement('p');

                empty.className = 'rounded-2xl border border-dashed border-white/25 p-5 text-sm text-white/55';
                empty.textContent = 'Select at least one destination above.';

                selectedContainer.appendChild(empty);

                return;
            }

            selectedOrder.forEach((id, index) => {
                const option = getOption(id);

                if (!option) {
                    return;
                }

                const row = document.createElement('div');
                row.dataset.selectedDestinationId = id;
                row.className = 'flex items-center gap-3 rounded-2xl border border-white/15 bg-white/5 p-4';

                const number = document.createElement('span');
                number.className = 'text-sm font-bold text-philippine-gold';
                number.textContent = String(index + 1).padStart(2, '0');

                const name = document.createElement('span');
                name.className = 'min-w-0 flex-1 font-semibold text-white';
                name.textContent = option.dataset.destinationName;

                const moveUp = document.createElement('button');
                moveUp.type = 'button';
                moveUp.dataset.moveUp = id;
                moveUp.className = 'rounded-lg px-2 py-1 text-sm text-white/60 hover:bg-white/10 hover:text-white';
                moveUp.textContent = '↑';

                const moveDown = document.createElement('button');
                moveDown.type = 'button';
                moveDown.dataset.moveDown = id;
                moveDown.className = 'rounded-lg px-2 py-1 text-sm text-white/60 hover:bg-white/10 hover:text-white';
                moveDown.textContent = '↓';

                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'destination_ids[]';
                hidden.value = id;

                row.append(
                    number,
                    name,
                    moveUp,
                    moveDown,
                    hidden
                );

                selectedContainer.appendChild(row);
            });
        };

        destinationCheckboxes.forEach((checkbox) => {
            checkbox.addEventListener('change', () => {
                const id = String(checkbox.value);

                if (checkbox.checked) {
                    if (!selectedOrder.includes(id)) {
                        selectedOrder.push(id);
                    }
                } else {
                    selectedOrder = selectedOrder.filter(
                        (selectedId) => selectedId !== id
                    );
                }

                renderSelectedDestinations();
            });
        });

        selectedContainer?.addEventListener('click', (event) => {
            const button = event.target.closest('button');

            if (!button) {
                return;
            }

            const id = button.dataset.moveUp
                || button.dataset.moveDown;

            if (!id) {
                return;
            }

            const currentIndex = selectedOrder.indexOf(id);

            if (currentIndex === -1) {
                return;
            }

            if (button.dataset.moveUp && currentIndex > 0) {
                [
                    selectedOrder[currentIndex - 1],
                    selectedOrder[currentIndex],
                ] = [
                    selectedOrder[currentIndex],
                    selectedOrder[currentIndex - 1],
                ];
            }

            if (
                button.dataset.moveDown
                && currentIndex < selectedOrder.length - 1
            ) {
                [
                    selectedOrder[currentIndex],
                    selectedOrder[currentIndex + 1],
                ] = [
                    selectedOrder[currentIndex + 1],
                    selectedOrder[currentIndex],
                ];
            }

            renderSelectedDestinations();
        });

        itineraryForm?.addEventListener('submit', (event) => {
            if (selectedOrder.length === 0) {
                event.preventDefault();
                window.alert('Select at least one destination.');
            }
        });

        renderSelectedDestinations();

        /* ------- the generation assist: the model ranks OUR candidate list */

        const assistBtn = document.getElementById('generation-assist-btn');
        const assistResults = document.getElementById('generation-assist-results');

        const areaInput = document.querySelector('select[name="area"]');

        const areaValue = () => areaInput?.value?.trim() ?? '';

        assistBtn?.addEventListener('click', async function () {
            const area = areaValue();
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';

            if (area === '' || selectedOrder.length === 0) {
                assistResults.innerHTML =
                    '<p class="rounded-xl bg-palawan-sand p-4 text-sm text-benguet-charcoal/65 sm:col-span-2">Choose an area and at least one destination first.</p>';

                return;
            }

            assistBtn.disabled = true;
            assistResults.innerHTML =
                '<p class="text-sm text-benguet-charcoal/60 sm:col-span-2">Ranking your shortlist&hellip;</p>';

            try {
                const response = await fetch('{{ route('itineraries.generation-assist') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({
                        area,
                        destination_ids: selectedOrder.map(Number),
                    }),
                });

                if (!response.ok) {
                    throw new Error('Request failed');
                }

                const data = await response.json();
                const picks = Array.isArray(data.suggestions) ? data.suggestions : [];

                assistResults.innerHTML = '';

                if (picks.length === 0) {
                    assistResults.innerHTML =
                        '<p class="text-sm text-benguet-charcoal/60 sm:col-span-2">No suggestions came back. Try again later.</p>';

                    return;
                }

                picks.forEach((pick, index) => {
                    const id = String(pick.destination?.id ?? '');
                    const card = document.createElement('div');
                    card.className = 'rounded-xl border border-boracay-light bg-palawan-sand p-4';

                    // THE RANK IS THE MODEL'S OWN ORDER, as a number. The
                    // suggestions arrive ranked; without the numeral a
                    // traveller sees an unordered pile of cards.
                    const header = document.createElement('div');
                    header.className = 'flex items-start gap-3';

                    const rank = document.createElement('span');
                    rank.className = 'flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-philippine-gold text-sm font-bold text-benguet-charcoal';
                    rank.textContent = String(index + 1);

                    const title = document.createElement('button');
                    title.type = 'button';
                    title.className = 'text-left font-semibold text-volcanic-teal hover:text-boracay-dark';
                    title.textContent = pick.destination?.name || 'Unknown place';

                    // Clicking a card ticks or unticks that destination in the
                    // shortlist above — the suggestion acts on the form, never
                    // on its own.
                    title.addEventListener('click', () => {
                        const checkbox = destinationCheckboxes.find(
                            (box) => String(box.value) === id
                        );

                        if (checkbox) {
                            checkbox.checked = !checkbox.checked;
                            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    });

                    const reason = document.createElement('p');
                    reason.className = 'mt-1 text-sm text-benguet-charcoal/70';
                    reason.textContent = pick.reason || '';

                    const label = document.createElement('p');
                    label.className = 'mt-2 text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/50';
                    label.textContent = "The model's own words, unchecked";

                    header.append(rank, title);
                    card.append(header, reason, label);
                    assistResults.appendChild(card);
                });
            } catch (error) {
                assistResults.innerHTML =
                    '<p class="text-sm text-red-700 sm:col-span-2">Suggestions could not be loaded. Please try again.</p>';
            } finally {
                assistBtn.disabled = false;
            }
        });
    </script>
</x-app-layout>