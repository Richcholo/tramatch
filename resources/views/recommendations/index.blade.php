<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                    03 / Your places
                </p>

                <h1 class="mt-3 font-display text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal sm:text-6xl">
                    The places you kept.
                </h1>
            </div>

            @if (Route::has('discover.index'))
                <a
                    href="{{ route('discover.index') }}"
                    class="rounded-full border border-boracay px-4 py-2 text-sm font-semibold text-boracay-dark transition hover:bg-boracay-light"
                >
                    Continue discovering
                </a>
            @endif
        </div>
    </x-slot>

    <div class="space-y-10">
        <section class="rounded-[2rem] bg-volcanic-teal p-8 text-white shadow-xl sm:p-12">
            <p class="text-xs font-bold uppercase tracking-[0.3em] text-boracay-light">
                Swipe-informed recommendations
            </p>

            <h2 class="mt-5 max-w-4xl font-display text-5xl font-semibold leading-[0.95] tracking-[-0.05em] sm:text-7xl">
                Your instincts are becoming a route.
            </h2>

            <p class="mt-6 max-w-2xl leading-7 text-white/70">
                These destinations were liked during discovery and ranked against your travel profile.
            </p>
        </section>

        @if ($needsProfile)
            <section class="rounded-[2rem] border border-philippine-gold bg-philippine-gold/15 p-8">
                <p class="text-xs font-bold uppercase tracking-[0.25em] text-boracay-dark">
                    Start here
                </p>

                <h2 class="mt-3 font-display text-4xl font-semibold text-volcanic-teal">
                    Set your travel direction.
                </h2>

                <p class="mt-3 text-benguet-charcoal/70">
                    Add your interests and budget before discovering destinations.
                </p>

                <a
                    href="{{ route('preferences.edit') }}"
                    class="tm-primary-button mt-6 rounded-full"
                >
                    Set preferences →
                </a>
            </section>
        @elseif ($recommendations->isEmpty())
            <section class="rounded-[2rem] bg-island-white p-10 text-center shadow-sm ring-1 ring-boracay-light">
                <span class="tm-gold-badge">Your deck is waiting</span>

                <h2 class="mt-6 font-display text-4xl font-semibold text-volcanic-teal">
                    Your liked places will appear here.
                </h2>

                <p class="mx-auto mt-4 max-w-lg leading-7 text-benguet-charcoal/65">
                    Swipe right on destinations that feel like your kind of trip.
                </p>

                @if (Route::has('discover.index'))
                    <a
                        href="{{ route('discover.index') }}"
                        class="tm-primary-button mt-7 rounded-full"
                    >
                        Go to Discover →
                    </a>
                @endif
            </section>
        @else
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($recommendations as $destination)
                    <article class="group overflow-hidden rounded-[2rem] bg-island-white shadow-sm ring-1 ring-boracay-light">
                        @if ($destination->image_url)
                            <img
                                src="{{ $destination->image_url }}"
                                alt="{{ $destination->name }}"
                                class="h-56 w-full object-cover transition duration-700 group-hover:scale-105"
                            >
                        @else
                            <div class="flex h-56 items-center justify-center bg-gradient-to-br from-boracay to-volcanic-teal text-xl font-bold text-white">
                                {{ $destination->province }}
                            </div>
                        @endif

                        <div class="p-6">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-sm text-benguet-charcoal/60">
                                        {{ $destination->municipality }},
                                        {{ $destination->province }}
                                    </p>

                                    <h2 class="mt-2 text-2xl font-bold text-volcanic-teal">
                                        {{ $destination->name }}
                                    </h2>
                                </div>

                                <span class="tm-gold-badge whitespace-nowrap">
                                    {{ $destination->match_score }}%
                                </span>
                            </div>

                            <p class="mt-4 line-clamp-4 text-sm leading-6 text-benguet-charcoal/70">
                                {{ $destination->description }}
                            </p>

                            <div class="mt-5 flex flex-wrap gap-2">
                                @foreach ($destination->matched_tags as $tag)
                                    <span class="rounded-full bg-boracay-light px-3 py-1 text-xs font-semibold text-boracay-dark">
                                        {{ $tag->name }}
                                    </span>
                                @endforeach
                            </div>

                            <div class="mt-5 rounded-2xl bg-palawan-sand p-4 text-sm text-volcanic-teal">
                                <p class="font-semibold">
                                    Why it matches
                                </p>

                                <p class="mt-1 leading-6 text-benguet-charcoal/70">
                                    {{ $destination->recommendation_reason ?? 'This destination matches your profile and budget.' }}
                                </p>
                            </div>

                            <div class="mt-6 flex items-center justify-between gap-4">
                                <span class="text-sm font-semibold text-benguet-charcoal/70">
                                    ₱{{ number_format($destination->estimated_cost, 2) }}
                                </span>

                                <a
                                    href="{{ route('destinations.show', $destination) }}"
                                    class="font-semibold text-boracay-dark hover:text-volcanic-teal"
                                >
                                    View place →
                                </a>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="flex flex-wrap gap-4">
                @if (Route::has('itineraries.create'))
                    <a
                        href="{{ route('itineraries.create') }}"
                        class="tm-primary-button rounded-full"
                    >
                        Build itinerary →
                    </a>
                @endif

                @if (Route::has('discover.index'))
                    <a
                        href="{{ route('discover.index') }}"
                        class="inline-flex items-center rounded-full border border-boracay px-6 py-3 font-semibold text-boracay-dark transition hover:bg-boracay-light"
                    >
                        Discover more
                    </a>
                @endif
            </div>
        @endif
    </div>
</x-app-layout>