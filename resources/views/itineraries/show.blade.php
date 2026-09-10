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

                    <form
                        method="POST"
                        action="{{ route('itineraries.destroy', $itinerary) }}"
                        onsubmit="return confirm('Delete this itinerary?')"
                    >
                        @csrf
                        @method('DELETE')

                        <button
                            type="submit"
                            class="rounded-full bg-red-500/15 px-5 py-3 text-sm font-semibold text-red-200 transition hover:bg-red-500 hover:text-white"
                        >
                            Delete
                        </button>
                    </form>
                </div>
            </div>
        </section>

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

        <section>
            <div class="mb-8">
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
        </section>
    </div>
</x-app-layout>