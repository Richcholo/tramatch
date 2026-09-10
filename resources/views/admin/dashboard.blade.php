<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                Operations
            </p>

            <h1 class="mt-3 font-display text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal sm:text-6xl">
                Keep the places worth finding.
            </h1>
        </div>
    </x-slot>

    <div class="space-y-10">
        <section class="rounded-[2rem] bg-volcanic-teal p-8 text-white shadow-xl sm:p-12">
            <p class="text-xs font-bold uppercase tracking-[0.3em] text-boracay-light">
                TraMatch / Admin
            </p>

            <h2 class="mt-5 max-w-3xl font-display text-5xl font-semibold leading-[0.95] tracking-[-0.05em] sm:text-7xl">
                The catalog is the beginning of every trip.
            </h2>

            <p class="mt-6 max-w-2xl leading-7 text-white/70">
                Maintain the places, tags, and traveler feedback that power the discovery experience.
            </p>

            <a
                href="{{ route('admin.destinations.index') }}"
                class="mt-8 inline-flex rounded-full bg-philippine-gold px-6 py-3 font-bold text-benguet-charcoal transition hover:bg-white"
            >
                Manage destinations →
            </a>
        </section>

        <section class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                    Destinations
                </p>

                <p class="mt-8 text-5xl font-semibold text-volcanic-teal">
                    {{ $destinationCount }}
                </p>
            </div>

            <div class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                    Tags
                </p>

                <p class="mt-8 text-5xl font-semibold text-volcanic-teal">
                    {{ $tagCount }}
                </p>
            </div>

            <div class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                    Reviews
                </p>

                <p class="mt-8 text-5xl font-semibold text-volcanic-teal">
                    {{ $reviewCount }}
                </p>
            </div>

            <div class="rounded-[2rem] bg-philippine-gold/20 p-6 ring-1 ring-philippine-gold/40">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                    Pending reviews
                </p>

                <p class="mt-8 text-5xl font-semibold text-volcanic-teal">
                    {{ $pendingReviewCount }}
                </p>
            </div>
        </section>
    </div>
</x-app-layout>