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

    // Three real states, so the panel never promises something the user lacks.
    $isFresh = $likedCount === 0 && $itineraryCount === 0;
    $hasTrips = $itineraryCount > 0;

    [$panelTitle, $panelBody, $panelAction] = match (true) {
        $hasTrips => [
            'Your saved trips',
            'You have ' . $itineraryCount . ' saved ' . \Illuminate\Support\Str::plural('trip', $itineraryCount)
                . '. Open one to see the day-by-day plan and the driving route.',
            Route::has('itineraries.index') ? route('itineraries.index') : $discoverHref,
        ],
        $likedCount > 0 => [
            'Turn your likes into a trip',
            'You have ' . $likedCount . ' saved ' . \Illuminate\Support\Str::plural('place', $likedCount)
                . '. Build a day-by-day plan around them.',
            $discoverHref,
        ],
        default => [
            'Start with the places you like',
            'Swipe through Luzon destinations and keep the ones that fit. TraMatch builds an itinerary from what you keep.',
            $discoverHref,
        ],
    };

    $panelActionLabel = $hasTrips && Route::has('itineraries.index')
        ? 'Open your trips'
        : ($isFresh && ! Route::has('discover.index') ? 'Set your preferences' : 'Continue your deck');

    $firstName = trim($user->first_name ?? '') ?: $user->name;
@endphp

<x-app-layout>
    <x-slot name="header">
        {{-- Editorial: one big serif line, then very small metadata beneath.
             Not a wide-tracked uppercase eyebrow, which is the generic default. --}}
        <div class="max-w-3xl">
            <p class="text-sm text-benguet-charcoal/75">
                {{ now()->format('l, j F') }}
            </p>

            <h1 class="mt-3 font-display text-4xl font-semibold leading-tight tracking-[-0.02em] text-volcanic-teal sm:text-5xl">
                Welcome back, {{ $firstName }}.
            </h1>
        </div>
    </x-slot>

    <div class="mx-auto max-w-5xl space-y-10">
        <section class="relative isolate overflow-hidden rounded-[2rem] bg-volcanic-teal p-7 text-white shadow-xl sm:p-10 lg:p-12">
            <div class="pointer-events-none absolute -right-20 -top-24 -z-10 h-72 w-72 rounded-full bg-boracay/20 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-36 left-1/3 -z-10 h-72 w-72 rounded-full bg-philippine-gold/10 blur-3xl"></div>

            <p class="text-xs font-bold uppercase tracking-[0.25em] text-boracay-light">
                Your next adventure
            </p>

            <h2 class="mt-4 max-w-2xl font-display text-3xl font-semibold leading-tight tracking-[-0.02em] text-island-white sm:text-4xl">
                {{ $panelTitle }}
            </h2>

            <p class="mt-4 max-w-2xl text-base leading-7 text-white/75">
                {{ $panelBody }}
            </p>

            <div class="mt-8 flex flex-col gap-4 sm:flex-row sm:items-center">
                <a
                    href="{{ $panelAction }}"
                    class="inline-flex min-h-12 items-center justify-center rounded-full bg-boracay px-6 py-3 text-sm font-bold text-volcanic-teal transition-colors duration-150 hover:bg-boracay-light focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-philippine-gold"
                >
                    {{ $panelActionLabel }}
                </a>

                @if (Route::has('preferences.edit') && ! $isFresh)
                    <a
                        href="{{ route('preferences.edit') }}"
                        class="inline-flex min-h-11 items-center justify-center rounded-full border border-white/25 px-5 py-3 text-sm font-semibold text-white transition-colors duration-150 hover:border-white/60 hover:bg-white/10"
                    >
                        Adjust preferences
                    </a>
                @endif
            </div>
        </section>

        @unless ($isFresh)
            <section aria-labelledby="your-tally">
                <div class="flex items-end justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.22em] text-boracay-dark">Your journey</p>
                        <h2 id="your-tally" class="mt-2 font-display text-2xl font-semibold text-volcanic-teal">
                            Your tally
                        </h2>
                    </div>
                    <p class="hidden text-sm text-benguet-charcoal/60 sm:block">A little progress, one place at a time.</p>
                </div>

                <dl class="mt-5 grid grid-cols-3 gap-2 sm:gap-4">
                    <div class="min-w-0 rounded-2xl border border-boracay-light bg-palawan-sand p-3 shadow-sm sm:rounded-3xl sm:p-6">
                        <dt class="text-xs font-semibold text-benguet-charcoal/70 sm:text-sm">Liked</dt>
                        <dd class="mt-2 font-display text-2xl font-semibold leading-none text-volcanic-teal sm:mt-3 sm:text-4xl">
                            {{ $likedCount }}
                        </dd>
                        <p class="mt-3 hidden text-xs text-benguet-charcoal/55 sm:block">Places worth remembering</p>
                    </div>

                    <div class="min-w-0 rounded-2xl border border-boracay-light bg-palawan-sand p-3 shadow-sm sm:rounded-3xl sm:p-6">
                        <dt class="text-xs font-semibold text-benguet-charcoal/70 sm:text-sm">Passed</dt>
                        <dd class="mt-2 font-display text-2xl font-semibold leading-none text-volcanic-teal sm:mt-3 sm:text-4xl">
                            {{ $passedCount }}
                        </dd>
                        <p class="mt-3 hidden text-xs text-benguet-charcoal/55 sm:block">Not quite the right fit</p>
                    </div>

                    <div class="min-w-0 rounded-2xl border border-boracay-light bg-palawan-sand p-3 shadow-sm sm:rounded-3xl sm:p-6">
                        <dt class="text-xs font-semibold text-benguet-charcoal/70 sm:text-sm">Trips</dt>
                        <dd class="mt-2 font-display text-2xl font-semibold leading-none text-volcanic-teal sm:mt-3 sm:text-4xl">
                            {{ $itineraryCount }}
                        </dd>
                        <p class="mt-3 hidden text-xs text-benguet-charcoal/55 sm:block">Plans ready to explore</p>
                    </div>
                </dl>
            </section>
        @endunless

        <section aria-labelledby="recent-activity" class="rounded-[2rem] border border-boracay-light bg-palawan-sand p-6 shadow-sm sm:p-8">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.22em] text-boracay-dark">Your travel story</p>
                    <h2 id="recent-activity" class="mt-2 font-display text-2xl font-semibold text-volcanic-teal">
                        Recent activity
                    </h2>
                </div>

                @if ($recentActivity->isNotEmpty() && Route::has('itineraries.index'))
                    <a
                        href="{{ route('itineraries.index') }}"
                        class="inline-flex min-h-11 items-center rounded-full px-4 text-sm font-semibold text-volcanic-teal transition-colors duration-150 hover:bg-boracay-light"
                    >
                        All trips
                    </a>
                @endif
            </div>

            @if ($recentActivity->isNotEmpty())
                <ul class="mt-5 divide-y divide-boracay-light">
                    @foreach ($recentActivity as $activity)
                        <li>
                            <a
                                href="{{ $activity['url'] }}"
                                class="flex min-h-11 items-center justify-between gap-6 rounded-xl px-3 py-4 transition-colors duration-150 hover:bg-boracay-light/50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-boracay"
                            >
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-boracay-dark">
                                        {{ $activity['type'] }}
                                    </p>

                                    <p class="mt-1 truncate font-semibold text-volcanic-teal sm:text-lg">
                                        {{ $activity['title'] }}
                                    </p>
                                </div>

                                <time
                                    datetime="{{ $activity['date']->toIso8601String() }}"
                                    class="shrink-0 text-xs text-benguet-charcoal/60 sm:text-sm"
                                >
                                    {{ $activity['date']->diffForHumans() }}
                                </time>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="mt-5 rounded-2xl border border-dashed border-boracay-dark/30 bg-white/50 p-7">
                    <p class="font-semibold text-volcanic-teal">
                        Nothing here yet.
                    </p>

                    <p class="mt-2 max-w-md text-sm leading-6 text-benguet-charcoal/75">
                        The places you like and the trips you save will show up here, newest first.
                    </p>

                    <a
                        href="{{ $discoverHref }}"
                        class="mt-5 inline-flex min-h-11 items-center text-sm font-semibold text-volcanic-teal underline decoration-boracay underline-offset-4 transition-colors duration-150 hover:text-boracay-dark"
                    >
                        {{ $isFresh ? 'Start swiping' : 'Keep swiping' }}
                    </a>
                </div>
            @endif
        </section>
    </div>
</x-app-layout>