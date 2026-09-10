@php
    $user = auth()->user();

    $likedCount = $user
        ->destinationSwipes()
        ->where('action', 'liked')
        ->count();

    $passedCount = $user
        ->destinationSwipes()
        ->where('action', 'passed')
        ->count();

    $itineraryCount = $user
        ->itineraries()
        ->count();

    $discoverHref = Route::has('discover.index')
        ? route('discover.index')
        : route('preferences.edit');

    $discoverLabel = Route::has('discover.index')
        ? 'Continue discovering'
        : 'Set your preferences';
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-6">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                    Your travel desk
                </p>

                <h1 class="mt-3 font-display text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal sm:text-6xl">
                    Welcome back, {{ $user->name }}.
                </h1>
            </div>

            <span class="text-sm font-semibold uppercase tracking-[0.18em] text-benguet-charcoal/50">
                TraMatch / Dashboard
            </span>
        </div>
    </x-slot>

    <div class="space-y-10">
        <section class="relative overflow-hidden rounded-[2rem] bg-volcanic-teal p-8 text-white shadow-xl sm:p-12">
            <div class="absolute -right-20 -top-20 h-72 w-72 rounded-full bg-boracay/30 blur-3xl"></div>
            <div class="absolute -bottom-32 left-1/3 h-72 w-72 rounded-full bg-philippine-gold/20 blur-3xl"></div>

            <div class="relative max-w-3xl">
                <p class="text-xs font-bold uppercase tracking-[0.3em] text-boracay-light">
                    Your next move
                </p>

                <h2 class="mt-5 font-display text-5xl font-semibold leading-[0.95] tracking-[-0.05em] sm:text-7xl">
                    The next place is closer than you think.
                </h2>

                <p class="mt-6 max-w-2xl text-lg leading-8 text-white/70">
                    Keep discovering destinations that match your taste, then turn your favorites into a trip that fits your time and budget.
                </p>

                <div class="mt-8 flex flex-wrap gap-4">
                    <a
                        href="{{ $discoverHref }}"
                        class="inline-flex items-center gap-3 rounded-full bg-philippine-gold px-6 py-3 font-bold text-benguet-charcoal transition hover:-translate-y-1 hover:bg-white"
                    >
                        {{ $discoverLabel }}
                        <span aria-hidden="true">↗</span>
                    </a>

                    @if (Route::has('preferences.edit'))
                        <a
                            href="{{ route('preferences.edit') }}"
                            class="inline-flex items-center rounded-full border border-white/30 px-6 py-3 font-semibold text-white transition hover:bg-white/10"
                        >
                            Edit preferences
                        </a>
                    @endif
                </div>
            </div>
        </section>

        <section class="grid gap-5 md:grid-cols-3">
            <article class="rounded-[2rem] border border-boracay-light bg-island-white p-6 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-[0.25em] text-boracay-dark">
                    01 / Liked
                </p>

                <p class="mt-8 text-5xl font-semibold tracking-[-0.05em] text-volcanic-teal">
                    {{ $likedCount }}
                </p>

                <p class="mt-2 text-sm text-benguet-charcoal/65">
                    Places that feel right for you.
                </p>
            </article>

            <article class="rounded-[2rem] border border-boracay-light bg-island-white p-6 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-[0.25em] text-boracay-dark">
                    02 / Passed
                </p>

                <p class="mt-8 text-5xl font-semibold tracking-[-0.05em] text-volcanic-teal">
                    {{ $passedCount }}
                </p>

                <p class="mt-2 text-sm text-benguet-charcoal/65">
                    Places filtered from your deck.
                </p>
            </article>

            <article class="rounded-[2rem] border border-boracay-light bg-island-white p-6 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-[0.25em] text-boracay-dark">
                    03 / Trips
                </p>

                <p class="mt-8 text-5xl font-semibold tracking-[-0.05em] text-volcanic-teal">
                    {{ $itineraryCount }}
                </p>

                <p class="mt-2 text-sm text-benguet-charcoal/65">
                    Travel plans you have saved.
                </p>
            </article>
        </section>

        <section class="grid gap-8 lg:grid-cols-[0.7fr_1.3fr]">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                    04 / The ritual
                </p>

                <h2 class="mt-4 font-display text-4xl font-semibold leading-tight tracking-[-0.04em] text-volcanic-teal sm:text-5xl">
                    Your taste is the starting point.
                </h2>

                <div class="mt-8 h-px w-24 bg-philippine-gold"></div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-[1.5rem] bg-island-white p-6 ring-1 ring-boracay-light">
                    <span class="text-3xl font-semibold text-philippine-gold">01</span>
                    <h3 class="mt-8 font-bold text-volcanic-teal">Choose a direction.</h3>
                    <p class="mt-3 text-sm leading-6 text-benguet-charcoal/65">
                        Budget, group, duration, and interests.
                    </p>
                </div>

                <div class="rounded-[1.5rem] bg-island-white p-6 ring-1 ring-boracay-light">
                    <span class="text-3xl font-semibold text-philippine-gold">02</span>
                    <h3 class="mt-8 font-bold text-volcanic-teal">Trust your instinct.</h3>
                    <p class="mt-3 text-sm leading-6 text-benguet-charcoal/65">
                        Like the places you want to remember.
                    </p>
                </div>

                <div class="rounded-[1.5rem] bg-island-white p-6 ring-1 ring-boracay-light">
                    <span class="text-3xl font-semibold text-philippine-gold">03</span>
                    <h3 class="mt-8 font-bold text-volcanic-teal">Make it real.</h3>
                    <p class="mt-3 text-sm leading-6 text-benguet-charcoal/65">
                        Turn your places into a plan.
                    </p>
                </div>
            </div>
        </section>
    </div>
</x-app-layout>