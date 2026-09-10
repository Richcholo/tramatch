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
                        class="rounded-xl bg-philippine-gold px-5 py-3 font-bold text-benguet-charcoal transition hover:bg-white"
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
                    <div class="grid gap-6 md:grid-cols-2">
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
                                These are liked destinations that match your budget and at least one of your interests.
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
                                    </span>

                                    <span class="mt-1 block text-sm text-benguet-charcoal/60">
                                        {{ $destination->municipality }},
                                        {{ $destination->province }}
                                        · {{ $destination->match_score }}% match
                                    </span>

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
                            class="rounded-full bg-philippine-gold px-6 py-3 font-bold text-benguet-charcoal transition hover:bg-white"
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
                    Your liked destinations will appear after you choose an area.
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
    </script>
</x-app-layout>