<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                    Operations
                </p>

                <h1 class="mt-3 font-display text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal sm:text-6xl">
                    Keep the places worth finding.
                </h1>
            </div>

            <span class="text-xs font-bold uppercase tracking-[0.2em] text-benguet-charcoal/45">
                TraMatch / Admin
            </span>
        </div>
    </x-slot>

    <div class="space-y-10">
        <section class="relative overflow-hidden rounded-[2rem] bg-volcanic-teal p-8 text-white shadow-xl sm:p-12">
            <div class="absolute -right-24 -top-24 h-96 w-96 rounded-full bg-boracay/25 blur-3xl"></div>
            <div class="absolute -bottom-32 left-1/3 h-80 w-80 rounded-full bg-philippine-gold/15 blur-3xl"></div>

            <div class="relative max-w-4xl">
                <p class="text-xs font-bold uppercase tracking-[0.3em] text-boracay-light">
                    Data operations
                </p>

                <h2 class="mt-5 font-display text-5xl font-semibold leading-[0.95] tracking-[-0.05em] sm:text-7xl">
                    The catalog is the beginning of every trip.
                </h2>

                <p class="mt-6 max-w-2xl leading-7 text-white/70">
                    Maintain destinations, review fresh source information, and keep the discovery experience accurate.
                </p>

                <div class="mt-8 flex flex-wrap gap-3">
                    @if (Route::has('admin.destinations.index'))
                        <a
                            href="{{ route('admin.destinations.index') }}"
                            class="inline-flex rounded-full bg-philippine-gold px-6 py-3 font-bold text-benguet-charcoal transition hover:bg-white"
                        >
                            Manage destinations →
                        </a>
                    @endif

                    @if (Route::has('admin.sources.index'))
                        <a
                            href="{{ route('admin.sources.index') }}"
                            class="inline-flex rounded-full border border-white/30 px-6 py-3 font-bold text-white transition hover:bg-white hover:text-volcanic-teal"
                        >
                            Manage sources
                        </a>
                    @endif

                    @if (Route::has('admin.proposals.index'))
                        <a
                            href="{{ route('admin.proposals.index') }}"
                            class="inline-flex rounded-full border border-white/30 px-6 py-3 font-bold text-white transition hover:bg-white hover:text-volcanic-teal"
                        >
                            Review updates
                        </a>
                    @endif
                </div>
            </div>
        </section>

        <section class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                    Destinations
                </p>

                <p class="mt-8 text-5xl font-semibold text-volcanic-teal">
                    {{ $destinationCount }}
                </p>

                <p class="mt-3 text-sm text-benguet-charcoal/55">
                    Locations in the public catalog
                </p>
            </div>

            <div class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                    Tags
                </p>

                <p class="mt-8 text-5xl font-semibold text-volcanic-teal">
                    {{ $tagCount }}
                </p>

                <p class="mt-3 text-sm text-benguet-charcoal/55">
                    Preference labels used for matching
                </p>
            </div>

            <div class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                    Reviews
                </p>

                <p class="mt-8 text-5xl font-semibold text-volcanic-teal">
                    {{ $reviewCount }}
                </p>

                <p class="mt-3 text-sm text-benguet-charcoal/55">
                    Traveler feedback entries
                </p>
            </div>

            <div class="rounded-[2rem] bg-philippine-gold/20 p-6 ring-1 ring-philippine-gold/40">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                    Pending proposals
                </p>

                <p class="mt-8 text-5xl font-semibold text-volcanic-teal">
                    {{ $pendingProposalCount }}
                </p>

                <p class="mt-3 text-sm text-benguet-charcoal/65">
                    Crawled updates waiting for review
                </p>
            </div>
        </section>

        <section class="grid gap-5 lg:grid-cols-3">
            <a
                href="{{ route('admin.destinations.index') }}"
                class="group rounded-[2rem] bg-island-white p-6 ring-1 ring-boracay-light transition hover:-translate-y-1 hover:shadow-xl"
            >
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                    Catalog
                </p>

                <h2 class="mt-6 font-display text-3xl font-semibold text-volcanic-teal">
                    Manage destinations
                </h2>

                <p class="mt-3 text-sm leading-6 text-benguet-charcoal/65">
                    Add, edit, archive, restore, or permanently remove locations.
                </p>

                <span class="mt-8 inline-block font-bold text-boracay-dark group-hover:text-volcanic-teal">
                    Open catalog →
                </span>
            </a>

            @if (Route::has('admin.sources.index'))
                <a
                    href="{{ route('admin.sources.index') }}"
                    class="group rounded-[2rem] bg-island-white p-6 ring-1 ring-boracay-light transition hover:-translate-y-1 hover:shadow-xl"
                >
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                        Freshness
                    </p>

                    <h2 class="mt-6 font-display text-3xl font-semibold text-volcanic-teal">
                        Manage sources
                    </h2>

                    <p class="mt-3 text-sm leading-6 text-benguet-charcoal/65">
                        Crawl approved official sources and monitor their status.
                    </p>

                    <div class="mt-8 flex flex-wrap gap-2 text-xs font-semibold">
                        <span class="rounded-full bg-boracay-light px-3 py-1 text-boracay-dark">
                            {{ $sourceCount }} registered
                        </span>

                        <span class="rounded-full bg-emerald-100 px-3 py-1 text-emerald-700">
                            {{ $successfulSourceCount }} successful
                        </span>

                        <span class="rounded-full bg-red-100 px-3 py-1 text-red-700">
                            {{ $failedSourceCount }} failed
                        </span>
                    </div>
                </a>
            @endif

            @if (Route::has('admin.proposals.index'))
                <a
                    href="{{ route('admin.proposals.index') }}"
                    class="group rounded-[2rem] bg-island-white p-6 ring-1 ring-boracay-light transition hover:-translate-y-1 hover:shadow-xl"
                >
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                        Verification
                    </p>

                    <h2 class="mt-6 font-display text-3xl font-semibold text-volcanic-teal">
                        Review updates
                    </h2>

                    <p class="mt-3 text-sm leading-6 text-benguet-charcoal/65">
                        Compare current destination data with proposed source updates before publishing.
                    </p>

                    <span class="mt-8 inline-block font-bold text-boracay-dark group-hover:text-volcanic-teal">
                        Open review queue →
                    </span>
                </a>
            @endif
        </section>
    </div>
</x-app-layout>