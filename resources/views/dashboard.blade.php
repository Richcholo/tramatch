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

    <div class="mx-auto max-w-4xl space-y-14">
        {{-- The single focal point of the page. One accent colour, one
             unmistakable action. Space separates regions rather than
             elevation, which is why there is no drop shadow here. --}}
        <section class="rounded-lg border border-boracay-light bg-palawan-sand p-7 sm:p-10">
            <h2 class="font-display text-3xl font-semibold leading-tight tracking-[-0.02em] text-volcanic-teal sm:text-4xl">
                {{ $panelTitle }}
            </h2>

            <p class="mt-4 max-w-xl text-base leading-7 text-benguet-charcoal/75">
                {{ $panelBody }}
            </p>

            <div class="mt-8 flex flex-col gap-4 sm:flex-row sm:items-center">
                <a
                    href="{{ $panelAction }}"
                    {{-- Label is volcanic-teal, not benguet-charcoal: charcoal on
                         boracay measures 4.24:1 and fails WCAG AA. Hover inverts
                         to ink, which is both higher contrast and a clearer
                         signal than a subtle darkening. --}}
                    class="inline-flex min-h-11 items-center justify-center rounded-lg bg-boracay px-6 py-3 text-sm font-semibold text-volcanic-teal transition-colors duration-150 hover:bg-volcanic-teal hover:text-white"
                >
                    {{ $panelActionLabel }}
                </a>

                @if (Route::has('preferences.edit') && ! $isFresh)
                    <a
                        href="{{ route('preferences.edit') }}"
                        class="inline-flex min-h-11 items-center justify-center rounded-lg px-2 py-3 text-sm font-semibold text-volcanic-teal underline decoration-boracay underline-offset-4 transition-colors duration-150 hover:text-boracay-dark"
                    >
                        Adjust preferences
                    </a>
                @endif
            </div>
        </section>

        {{-- Counts are a ledger, not three identical cards. A single row
             divided by hairlines reads as one fact, where a card grid reads as
             three competing ones. Hidden while fresh, because three zeros tell
             a new user nothing. --}}
        @unless ($isFresh)
            <section aria-labelledby="your-tally">
                <h2 id="your-tally" class="font-display text-2xl font-semibold text-volcanic-teal">
                    Your tally
                </h2>

                <dl class="mt-5 grid grid-cols-1 divide-y divide-boracay-light border-y border-boracay-light sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                    <div class="py-6 sm:px-6 sm:py-7 sm:first:pl-0">
                        <dd class="font-display text-4xl font-semibold leading-none text-volcanic-teal">
                            {{ $likedCount }}
                        </dd>
                        <dt class="mt-2 text-sm text-benguet-charcoal/75">Liked</dt>
                    </div>

                    <div class="py-6 sm:px-6 sm:py-7">
                        <dd class="font-display text-4xl font-semibold leading-none text-volcanic-teal">
                            {{ $passedCount }}
                        </dd>
                        <dt class="mt-2 text-sm text-benguet-charcoal/75">Passed</dt>
                    </div>

                    <div class="py-6 sm:px-6 sm:py-7 sm:last:pr-0">
                        <dd class="font-display text-4xl font-semibold leading-none text-volcanic-teal">
                            {{ $itineraryCount }}
                        </dd>
                        <dt class="mt-2 text-sm text-benguet-charcoal/75">Trips</dt>
                    </div>
                </dl>
            </section>
        @endunless

        {{-- Empty state: honest about what is missing, and gives the one
             action that fills it. --}}
        <section aria-labelledby="recent-activity">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <h2 id="recent-activity" class="font-display text-2xl font-semibold text-volcanic-teal">
                    Recent activity
                </h2>

                @if ($recentActivity->isNotEmpty() && Route::has('itineraries.index'))
                    <a
                        href="{{ route('itineraries.index') }}"
                        class="inline-flex min-h-11 items-center text-sm font-semibold text-volcanic-teal underline decoration-boracay underline-offset-4 transition-colors duration-150 hover:text-boracay-dark"
                    >
                        All trips
                    </a>
                @endif
            </div>

            @if ($recentActivity->isNotEmpty())
                <ul class="mt-5 divide-y divide-boracay-light border-y border-boracay-light">
                    @foreach ($recentActivity as $activity)
                        <li>
                            <a
                                href="{{ $activity['url'] }}"
                                class="flex min-h-11 items-center justify-between gap-6 py-4 transition-colors duration-150 hover:bg-boracay-light/40"
                            >
                                <div class="min-w-0">
                                    {{-- Type is a quiet label, not a capsule badge.
                                         A pill here was decoration with no function. --}}
                                    <p class="text-xs text-benguet-charcoal/75">
                                        {{ $activity['type'] }}
                                    </p>

                                    <p class="mt-1 truncate font-semibold text-volcanic-teal">
                                        {{ $activity['title'] }}
                                    </p>
                                </div>

                                <time
                                    datetime="{{ $activity['date']->toIso8601String() }}"
                                    class="shrink-0 text-sm text-benguet-charcoal/75"
                                >
                                    {{ $activity['date']->diffForHumans() }}
                                </time>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="mt-5 rounded-lg border border-boracay-light bg-palawan-sand p-7">
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