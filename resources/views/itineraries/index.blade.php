<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                    04 / Your plans
                </p>

                <h1 class="mt-3 font-display text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal sm:text-6xl">
                    Trips worth returning to.
                </h1>
            </div>

            <a
                href="{{ route('itineraries.create') }}"
                class="tm-primary-button rounded-full"
            >
                Create itinerary →
            </a>
        </div>
    </x-slot>

    <div class="space-y-8">
        @forelse ($itineraries as $itinerary)
            <article class="group rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light transition hover:-translate-y-1 hover:shadow-xl sm:p-8">
                <div class="flex flex-wrap items-start justify-between gap-6">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.25em] text-boracay-dark">
                            {{ ucfirst($itinerary->budget_level) }} trip
                        </p>

                        <h2 class="mt-4 font-display text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal">
                            {{ $itinerary->title }}
                        </h2>

                        <p class="mt-3 text-sm text-benguet-charcoal/60">
                            {{ $itinerary->trip_duration_days }} day(s)
                            · {{ $itinerary->match_score }}% average match
                            · ₱{{ number_format($itinerary->total_estimated_cost, 2) }}
                        </p>
                    </div>

                    <span class="tm-gold-badge">
                        {{ $itinerary->is_completed ? 'Completed' : 'In progress' }}
                    </span>
                </div>

                <div class="mt-8 flex flex-wrap items-center justify-between gap-4 border-t border-boracay-light pt-5">
                    @if ($itinerary->start_date)
                        <p class="text-sm text-benguet-charcoal/60">
                            Starting {{ $itinerary->start_date->format('M d, Y') }}
                        </p>
                    @else
                        <p class="text-sm text-benguet-charcoal/60">
                            Flexible start date
                        </p>
                    @endif

                    <a
                        href="{{ route('itineraries.show', $itinerary) }}"
                        class="font-semibold text-boracay-dark transition group-hover:text-volcanic-teal"
                    >
                        Open itinerary →
                    </a>
                </div>
            </article>
        @empty
            <section class="rounded-[2rem] bg-volcanic-teal p-10 text-white">
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-light">
                    No saved trips
                </p>

                <h2 class="mt-5 font-display text-5xl font-semibold">
                    Your first itinerary starts with a place you like.
                </h2>

                @if (Route::has('discover.index'))
                    <a
                        href="{{ route('discover.index') }}"
                        class="mt-8 inline-flex rounded-full bg-philippine-gold px-6 py-3 font-bold text-benguet-charcoal"
                    >
                        Go to Discover →
                    </a>
                @endif
            </section>
        @endforelse

        {{ $itineraries->links() }}
    </div>
</x-app-layout>