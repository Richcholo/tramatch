<x-app-layout>
    <x-slot name="header">
        <a
            href="{{ route('itineraries.index') }}"
            class="text-sm font-medium text-boracay-dark hover:text-volcanic-teal"
        >
            ← Back to my itineraries
        </a>
    </x-slot>

    <div class="space-y-6">
        <section class="flex flex-wrap items-end justify-between gap-5">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-boracay-dark">
                    {{ ucfirst($itinerary->budget_level) }} trip
                    · {{ $itinerary->match_score }}% average match
                </p>

                <h1 class="mt-2 text-3xl font-bold text-volcanic-teal">
                    {{ $itinerary->title }}
                </h1>

                <p class="mt-2 text-benguet-charcoal/75">
                    Estimated total:
                    ₱{{ number_format($itinerary->total_estimated_cost, 2) }}
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
                        class="rounded-xl border border-boracay px-4 py-2 font-semibold text-boracay-dark hover:bg-boracay-light"
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
                        class="rounded-xl border border-red-600 px-4 py-2 font-semibold text-red-600 hover:bg-red-50"
                    >
                        Delete
                    </button>
                </form>
            </div>
        </section>

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

        <div
            id="itinerary-map"
            class="h-80 rounded-2xl bg-boracay-light"
            data-itinerary-points="{{ json_encode($mapPoints) }}"
        ></div>

        <div class="grid gap-5 lg:grid-cols-2">
            @foreach ($itinerary->days as $day)
                <section class="rounded-2xl bg-island-white p-6 shadow-sm ring-1 ring-boracay-light">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-xl font-bold text-volcanic-teal">
                            Day {{ $day->day_number }}
                        </h2>

                        @if ($day->date)
                            <span class="text-sm text-benguet-charcoal/70">
                                {{ $day->date->format('M d, Y') }}
                            </span>
                        @endif
                    </div>

                    <div class="mt-5 space-y-5">
                        @forelse ($day->items as $item)
                            <div class="border-l-2 border-boracay pl-4">
                                <p class="text-xs font-semibold text-boracay-dark">
                                    @if ($item->start_time && $item->end_time)
                                        {{ substr($item->start_time, 0, 5) }}
                                        –
                                        {{ substr($item->end_time, 0, 5) }}
                                    @else
                                        Time to be arranged
                                    @endif
                                </p>

                                <a
                                    href="{{ route('destinations.show', $item->destination) }}"
                                    class="mt-1 block font-semibold text-volcanic-teal hover:text-boracay-dark"
                                >
                                    {{ $item->destination->name }}
                                </a>

                                <p class="mt-1 text-sm text-benguet-charcoal/70">
                                    {{ $item->destination->municipality }},
                                    {{ $item->destination->province }}
                                </p>

                                <p class="mt-1 text-sm text-benguet-charcoal/70">
                                    Estimated cost:
                                    ₱{{ number_format($item->estimated_cost, 2) }}
                                </p>

                                @if ($item->travel_minutes_from_previous > 0)
                                    <p class="mt-1 text-xs text-benguet-charcoal/60">
                                        Approximately
                                        {{ $item->travel_minutes_from_previous }}
                                        minutes from the previous stop
                                    </p>
                                @endif

                                @if ($item->note)
                                    <p class="mt-2 text-sm italic text-benguet-charcoal/70">
                                        {{ $item->note }}
                                    </p>
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-benguet-charcoal/70">
                                No destinations were assigned to this day.
                            </p>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</x-app-layout>