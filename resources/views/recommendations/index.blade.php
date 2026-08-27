<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-boracay-dark">
                    Your results
                </p>

                <h2 class="text-xl font-semibold text-benguet-charcoal">
                    Liked destinations
                </h2>
            </div>

            @if (Route::has('discover.index'))
                <a
                    href="{{ route('discover.index') }}"
                    class="rounded-xl border border-boracay px-4 py-2 text-sm font-semibold text-boracay-dark hover:bg-boracay-light"
                >
                    Continue discovering
                </a>
            @endif
        </div>
    </x-slot>

    <div class="space-y-8">
        <section class="rounded-2xl bg-volcanic-teal p-8 text-white shadow-sm">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-boracay-light">
                Personalized for you
            </p>

            <h1 class="mt-3 text-3xl font-bold">
                Your travel matches
            </h1>

            <p class="mt-3 max-w-2xl leading-7 text-white/75">
                These destinations were selected from the places you liked during Discover and ranked according to your travel preferences.
            </p>
        </section>

        @if ($needsProfile)
            <section class="rounded-2xl border border-philippine-gold bg-philippine-gold/20 p-6 text-benguet-charcoal">
                <h2 class="text-xl font-semibold">
                    Complete your travel profile first
                </h2>

                <p class="mt-2">
                    TraMatch needs your interests, budget, and trip details before it can create recommendations.
                </p>

                <a
                    href="{{ route('preferences.edit') }}"
                    class="mt-5 inline-flex rounded-xl bg-boracay-dark px-5 py-3 font-semibold text-white hover:bg-volcanic-teal"
                >
                    Set preferences
                </a>
            </section>
        @elseif ($recommendations->isEmpty())
            <section class="rounded-2xl bg-island-white p-8 text-center shadow-sm">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-philippine-gold/25 text-3xl text-benguet-charcoal">
                    ★
                </div>

                <h2 class="mt-5 text-2xl font-bold text-volcanic-teal">
                    Your liked destinations will appear here
                </h2>

                <p class="mx-auto mt-3 max-w-lg text-benguet-charcoal/75">
                    Swipe right on destinations in Discover to create your personalized recommendations.
                </p>

                @if (Route::has('discover.index'))
                    <a
                        href="{{ route('discover.index') }}"
                        class="tm-primary-button mt-6"
                    >
                        Go to Discover
                    </a>
                @endif
            </section>
        @else
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($recommendations as $destination)
                    <article class="overflow-hidden rounded-2xl bg-island-white shadow-sm ring-1 ring-boracay-light">
                        @if ($destination->image_url)
                            <img
                                src="{{ $destination->image_url }}"
                                alt="{{ $destination->name }}"
                                class="h-48 w-full object-cover"
                            >
                        @else
                            <div class="flex h-48 items-center justify-center bg-boracay-light text-xl font-bold text-boracay-dark">
                                {{ $destination->province }}
                            </div>
                        @endif

                        <div class="p-6">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-sm text-slate-500">
                                        {{ $destination->municipality }}, {{ $destination->province }}
                                    </p>

                                    <h2 class="mt-1 text-xl font-bold text-volcanic-teal">
                                        {{ $destination->name }}
                                    </h2>
                                </div>

                                <span class="tm-gold-badge whitespace-nowrap">
                                    {{ $destination->match_score }}%
                                </span>
                            </div>

                            <p class="mt-4 line-clamp-4 text-sm leading-6 text-benguet-charcoal/75">
                                {{ $destination->description }}
                            </p>

                            <div class="mt-4 flex flex-wrap gap-2">
                                @foreach ($destination->matched_tags as $tag)
                                    <span class="rounded-full bg-boracay-light px-2.5 py-1 text-xs font-medium text-boracay-dark">
                                        {{ $tag->name }}
                                    </span>
                                @endforeach
                            </div>

                            <div class="mt-5 rounded-xl bg-boracay-light p-4 text-sm text-volcanic-teal">
                                <p class="font-semibold">
                                    Why it matches
                                </p>

                                <p class="mt-1">
                                    {{ $destination->recommendation_reason ?? 'This destination matches your travel profile and budget.' }}
                                </p>
                            </div>

                            <div class="mt-5 flex items-center justify-between gap-4 text-sm">
                                <span class="font-medium text-benguet-charcoal">
                                    ₱{{ number_format($destination->estimated_cost, 2) }}
                                </span>

                                <a
                                    href="{{ route('destinations.show', $destination) }}"
                                    class="font-semibold text-boracay-dark hover:text-volcanic-teal"
                                >
                                    View details →
                                </a>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="flex flex-wrap gap-3">
                @if (Route::has('itineraries.create'))
                    <a
                        href="{{ route('itineraries.create') }}"
                        class="tm-primary-button"
                    >
                        Generate an itinerary
                    </a>
                @else
                    <span class="inline-flex rounded-xl bg-slate-200 px-5 py-3 font-semibold text-slate-500">
                        Itinerary generation comes next
                    </span>
                @endif

                @if (Route::has('discover.index'))
                    <a
                        href="{{ route('discover.index') }}"
                        class="inline-flex rounded-xl border border-boracay px-5 py-3 font-semibold text-boracay-dark hover:bg-boracay-light"
                    >
                        Discover more places
                    </a>
                @endif
            </div>
        @endif
    </div>
</x-app-layout>