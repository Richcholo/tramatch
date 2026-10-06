<x-app-layout>
    <x-slot name="header">
        <a
            href="{{ route('destinations.index') }}"
            class="text-sm font-semibold text-boracay-dark hover:text-volcanic-teal"
        >
            ← Back to destinations
        </a>
    </x-slot>

    <div class="space-y-10">
        {{--
            The hero.

            An inset card again, inside the max-w-[1600px] container, with its
            rounded corners back. Three layouts were tried for the hero and the
            carousel: carousel above hero as inset cards; both full-bleed via
            width:100vw, which came out off-centre by half a scrollbar; and both
            full-bleed via negative margins, which was aligned but too wide for
            the page. This is the original inset treatment, and the carousel now
            lives down in the content beside the location map rather than beside
            the hero at all.

            No viewport units here and nothing to clip. The one thing worth not
            re-adding is a width class: anything that widens this block has to
            account for the container's padding, and a `100vw` in particular is
            off-centre because it includes the vertical scrollbar.
        --}}
        <section class="relative overflow-hidden rounded-[2rem] bg-volcanic-teal text-white shadow-xl">
                @if ($destination->image_url)
                <img
                    src="{{ $destination->image_url }}"
                    alt="{{ $destination->name }}"
                    class="absolute inset-0 h-full w-full object-cover opacity-55"
                >
            @else
                <div class="absolute inset-0 bg-gradient-to-br from-boracay via-cyan-500 to-volcanic-teal"></div>
            @endif

            <div class="absolute inset-0 bg-gradient-to-t from-volcanic-teal via-volcanic-teal/55 to-transparent"></div>

            <div class="relative flex min-h-[32rem] flex-col justify-end p-8 sm:p-12">
                <div class="max-w-4xl">
                    <p class="text-xs font-bold uppercase tracking-[0.3em] text-boracay-light">
                        {{ $destination->municipality }},
                        {{ $destination->province }}
                    </p>

                    <h1 class="mt-5 font-display text-5xl font-semibold leading-[0.95] tracking-[-0.05em] text-white sm:text-7xl">
                        {{ $destination->name }}
                    </h1>

                    <div class="mt-6 flex flex-wrap gap-2">
                        @foreach ($destination->tags as $tag)
                            <span class="rounded-full bg-white/15 px-3 py-1 text-xs font-semibold text-white backdrop-blur">
                                {{ $tag->name }}
                            </span>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        {{-- Directly under the hero, and as wide as the container allows. It has
             been in three places: here, full-bleed across the page with the hero
             (two attempts, both abandoned -- see AGENTS.md), and in a narrow
             column beside the location map.

             Back here because the map column left half the page empty on a wide
             screen, and the map was the wrong neighbour anyway: it put the photos
             at 40% of the container, far smaller than they need to be.

             Full container width, NOT full-bleed, and the difference is the whole
             point. Going edge to edge needs `100vw`, which measures the viewport
             including the vertical scrollbar and lands off-centre by half a
             scrollbar -- that is what made the earlier attempts look broken. The
             container's content box is ~1504px and needs no such arithmetic. If
             this ever wants to truly touch the screen edges, the scrollbar width
             has to be accounted for rather than clipped away.

             Consequence, stated plainly: at ~1504px against a typical 1080-1170px
             upload this box upscales ~1.3-1.4x, so the photos render soft on a
             wide screen. That is the trade for "as large as possible", and it is
             the one the earlier capped versions avoided. The real fix is resizing
             uploads, which needs GD or Imagick -- absent from both the CLI and
             XAMPP.

             Kept as a partial rather than inlined, so the markup and the
             gallery.js hooks stay in one file. --}}
        @include('destinations.partials.carousel', ['destination' => $destination])

        <div class="grid gap-8 lg:grid-cols-[1.2fr_0.8fr]">
            <section class="space-y-8">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                        01 / The place
                    </p>

                    <h2 class="mt-4 font-display text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal">
                        A place to remember.
                    </h2>

                    <p class="mt-5 max-w-3xl leading-8 text-benguet-charcoal/70">
                        {{ $destination->description }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-[1.5rem] bg-island-white p-5 ring-1 ring-boracay-light">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-boracay-dark">
                            Entrance
                        </p>

                        <p class="mt-5 text-2xl font-bold text-volcanic-teal">
                            ₱{{ number_format($destination->entrance_fee, 2) }}
                        </p>
                    </div>

                    <div class="rounded-[1.5rem] bg-island-white p-5 ring-1 ring-boracay-light">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-boracay-dark">
                            Estimated cost
                        </p>

                        <p class="mt-5 text-2xl font-bold text-volcanic-teal">
                            ₱{{ number_format($destination->estimated_cost, 2) }}
                        </p>
                    </div>

                    <div class="rounded-[1.5rem] bg-island-white p-5 ring-1 ring-boracay-light">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-boracay-dark">
                            Visit length
                        </p>

                        <p class="mt-5 text-2xl font-bold text-volcanic-teal">
                            {{ $destination->recommended_minutes }} min
                        </p>
                    </div>
                </div>

                <div class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-8">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                                Opening hours
                            </p>

                            <p class="mt-4 text-3xl font-bold text-volcanic-teal">
                                {{ $hoursLabel ?? 'Hours not listed' }}
                            </p>
                        </div>

                        <span @class([
                            'rounded-full px-4 py-2 text-sm font-semibold',
                            'bg-emerald-100 text-emerald-800' => $openState === 'open',
                            'bg-red-100 text-red-800' => in_array($openState, ['closed', 'closed_today'], true),
                            'bg-slate-100 text-slate-700' => $openState === 'unknown',
                        ])>
                            @if ($openState === 'open')
                                Open now
                            @elseif ($openState === 'closed_today')
                                Closed today ({{ $todayName }})
                            @elseif ($openState === 'closed')
                                Closed now
                            @else
                                Open/closed unknown
                            @endif
                        </span>
                    </div>

                    @if ($perDayHours)
                        <div class="mt-5 overflow-hidden rounded-2xl ring-1 ring-boracay-light">
                            @foreach ($daySlugs as $day)
                                @php
                                    $window = $perDayHours[$day] ?? null;
                                    $isToday = strtolower($todayName) === $day;
                                @endphp
                                <div @class([
                                    'flex items-center justify-between gap-4 px-4 py-2 text-sm',
                                    'bg-boracay-light/60 font-semibold text-volcanic-teal' => $isToday,
                                    'border-t border-boracay-light/70' => ! $loop->first,
                                ])>
                                    <span>{{ ucfirst($day) }}@if ($isToday)<span class="ml-1 text-xs font-normal text-boracay-dark">(today)</span>@endif</span>
                                    <span @class([
                                        'text-benguet-charcoal/50' => $window === null,
                                    ])>
                                        {{ $window === null ? 'Closed' : $window['open'].'–'.$window['close'] }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($closedDaysLabel && ! $perDayHours)
                        <p class="mt-4 text-sm text-benguet-charcoal/70">
                            <strong>{{ $closedDaysLabel }}.</strong>
                            Hours above are for the days it is open.
                        </p>
                    @endif

                    @if ($destination->operating_status && $destination->operating_status !== 'open' && $openState === 'unknown')
                        <p class="mt-4 text-sm text-benguet-charcoal/70">
                            Note: the source lists this place as
                            <strong>{{ str_replace('_', ' ', $destination->operating_status) }}</strong>.
                        </p>
                    @endif

                    @if ($destination->hoursKindLabel())
                        <p @class([
                            'mt-4 rounded-xl px-3 py-2 text-sm',
                            'bg-amber-50 text-amber-900 ring-1 ring-amber-200' => $destination->hoursKindIsAdvisory(),
                            'bg-boracay-light/60 text-benguet-charcoal/80 ring-1 ring-boracay-light' => ! $destination->hoursKindIsAdvisory(),
                        ])>
                            <strong>{{ $destination->hoursKindLabel() }}</strong>
                        </p>
                    @endif

                    @if ($destination->hours_note)
                        <p class="mt-4 text-sm text-benguet-charcoal/70">
                            {{ $destination->hours_note }}
                        </p>
                    @endif

                    <p class="mt-5 text-xs text-benguet-charcoal/50">
                        Times are Philippine time (UTC+8).
                        @if ($updatedOn)
                            <span class="mt-1 block">
                                Last checked {{ $updatedOn }}.
                            </span>
                        @endif
                    </p>

                    @if ($destination->hours_source_url)
                        <p class="mt-3 border-t border-boracay-light pt-3 text-xs text-benguet-charcoal/60">
                            Hours from
                            <a
                                href="{{ $destination->hours_source_url }}"
                                target="_blank"
                                rel="noopener noreferrer nofollow"
                                class="font-semibold text-volcanic-teal underline underline-offset-2 hover:text-boracay-dark"
                            >{{ $destination->hours_source_label ?: $destination->hours_source_url }}</a>
                            @if ($updatedOn)
                                <span class="block text-benguet-charcoal/45">
                                    Checked {{ $updatedOn }}. Hours change, so confirm before you travel.
                                </span>
                            @endif
                        </p>
                    @endif
                </div>

                <div class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-8">
                    <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                        02 / Location
                    </p>

                    <div
                        data-destination-map
                        data-lat="{{ $destination->latitude }}"
                        data-lng="{{ $destination->longitude }}"
                        data-name="{{ $destination->name }}"
                        class="mt-6 h-[28rem] rounded-[1.5rem] bg-boracay-light"
                    ></div>

                    <p class="mt-4 text-xs text-benguet-charcoal/50">
                        {{ $destination->latitude }},
                        {{ $destination->longitude }}
                    </p>
                </div>
            </section>

            <aside class="space-y-8">
                <section class="rounded-[2rem] bg-boracay-dark p-6 text-white shadow-xl sm:p-8">
                    <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-light">
                        03 / Travel fit
                    </p>

                    <h2 class="mt-5 font-display text-4xl font-semibold leading-tight">
                        {{ ucfirst($destination->budget_level) }}
                        and ready to discover.
                    </h2>

                    @if (Route::has('discover.index'))
                        <a
                            href="{{ route('discover.index') }}"
                            class="mt-8 inline-flex rounded-full bg-philippine-gold px-5 py-3 font-bold text-benguet-charcoal transition hover:bg-white"
                        >
                            Find similar places →
                        </a>
                    @endif
                </section>

                <section class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-8">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                                04 / Reviews
                            </p>

                            <h2 class="mt-4 font-display text-3xl font-semibold text-volcanic-teal">
                                Traveler notes.
                            </h2>
                        </div>

                        @if ($destination->reviews->isNotEmpty())
                            <span class="tm-gold-badge">
                                ★ {{ number_format($destination->reviews->avg('rating'), 1) }}
                            </span>
                        @endif
                    </div>

                    <div class="mt-6 space-y-5">
                        @forelse ($destination->reviews as $review)
                            <article class="border-t border-boracay-light pt-5">
                                <div class="flex items-center justify-between gap-3">
                                    <strong class="text-volcanic-teal">
                                        {{ $review->user->name }}
                                    </strong>

                                    <span class="text-sm font-semibold text-boracay-dark">
                                        ★ {{ $review->rating }}/5
                                    </span>
                                </div>

                                <p class="mt-3 text-sm leading-6 text-benguet-charcoal/70">
                                    {{ $review->comment }}
                                </p>
                            </article>
                        @empty
                            <p class="text-sm text-benguet-charcoal/60">
                                No reviews yet.
                            </p>
                        @endforelse
                    </div>

                    @auth
                        <form
                            method="POST"
                            action="{{ route('reviews.store', $destination) }}"
                            class="mt-8 border-t border-boracay-light pt-8"
                        >
                            @csrf

                            <h3 class="font-semibold text-volcanic-teal">
                                Share your experience
                            </h3>

                            <label class="mt-5 block">
                                <span class="text-sm font-semibold">
                                    Rating
                                </span>

                                <select
                                    name="rating"
                                    required
                                    class="mt-2 w-full rounded-xl border-boracay-light bg-palawan-sand focus:border-boracay focus:ring-boracay"
                                >
                                    @foreach ([5, 4, 3, 2, 1] as $rating)
                                        <option value="{{ $rating }}">
                                            {{ $rating }}/5
                                        </option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="mt-5 block">
                                <span class="text-sm font-semibold">
                                    Review
                                </span>

                                <textarea
                                    name="comment"
                                    rows="4"
                                    required
                                    minlength="10"
                                    class="mt-2 w-full rounded-xl border-boracay-light bg-palawan-sand focus:border-boracay focus:ring-boracay"
                                    placeholder="What should other travelers know?"
                                >{{ old('comment') }}</textarea>
                            </label>

                            <button
                                type="submit"
                                class="tm-primary-button mt-5 rounded-full"
                            >
                                Submit review
                            </button>
                        </form>
                    @else
                        <p class="mt-8 border-t border-boracay-light pt-8 text-sm text-benguet-charcoal/60">
                            Log in to leave a review.
                        </p>
                    @endauth
                </section>
            </aside>
        </div>
    </div>
</x-app-layout>