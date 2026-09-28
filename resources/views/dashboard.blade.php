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
                    Liked
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
                    Passed
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
                    Trips
                </p>

                <p class="mt-8 text-5xl font-semibold tracking-[-0.05em] text-volcanic-teal">
                    {{ $itineraryCount }}
                </p>

                <p class="mt-2 text-sm text-benguet-charcoal/65">
                    Travel plans you have saved.
                </p>
            </article>
        </section>

        <section class="space-y-5">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.25em] text-boracay-dark">
                        Your travel desk
                    </p>
                    <h2 class="mt-2 font-display text-3xl font-semibold text-volcanic-teal">
                        Recent activity
                    </h2>
                </div>
                @if (Route::has('itineraries.index'))
                    <a href="{{ route('itineraries.index') }}" class="text-sm font-semibold text-volcanic-teal underline decoration-boracay underline-offset-4 transition hover:text-boracay-dark">
                        View all trips
                    </a>
                @endif
            </div>

            @if ($recentActivity->isNotEmpty())
                <div class="divide-y divide-boracay-light rounded-xl border border-boracay-light bg-island-white px-5 sm:px-7">
                    @foreach ($recentActivity as $activity)
                        <a href="{{ $activity['url'] }}" class="flex items-center justify-between gap-4 py-4 transition hover:text-boracay-dark">
                            <div class="flex min-w-0 items-start gap-4">
                                <span class="mt-1 inline-flex shrink-0 rounded-full px-2.5 py-1 text-[0.65rem] font-bold uppercase tracking-[0.12em] {{ $activity['type'] === 'Saved trip' ? 'bg-philippine-gold/20 text-benguet-charcoal' : 'bg-boracay-light text-boracay-dark' }}">
                                    {{ $activity['type'] === 'Saved trip' ? 'Trip' : 'Liked' }}
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-volcanic-teal">
                                        {{ $activity['title'] }}
                                    </p>
                                    <p class="mt-1 truncate text-xs text-benguet-charcoal/60">
                                        {{ $activity['type'] }} · {{ $activity['detail'] }}
                                    </p>
                                </div>
                            </div>
                            <time datetime="{{ $activity['date']->toIso8601String() }}" class="shrink-0 text-xs text-benguet-charcoal/50">
                                {{ $activity['date']->diffForHumans() }}
                            </time>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="rounded-xl border border-dashed border-boracay-light bg-island-white px-6 py-8">
                    <p class="font-semibold text-volcanic-teal">No recent activity yet.</p>
                    <p class="mt-1 text-sm text-benguet-charcoal/65">
                        Liked destinations and saved trips will appear here.
                    </p>
                    <a href="{{ $discoverHref }}" class="mt-4 inline-flex text-sm font-semibold text-volcanic-teal underline decoration-boracay underline-offset-4 hover:text-boracay-dark">
                        Start discovering
                    </a>
                </div>
            @endif
        </section>
    </div>
</x-app-layout>