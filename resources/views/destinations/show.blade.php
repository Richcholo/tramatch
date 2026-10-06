{{--
    The destination page.

    THE WHOLE PAGE SITS ON A BLURRED PHOTOGRAPH of this destination. The image is a
    fixed layer behind everything, and every card below is translucent, so the
    photograph is the page's material rather than a band above it. That is the one
    structural change from the previous layout, which had an opaque cream body and
    a dark hero above it.

    `position: fixed` rather than `absolute` so the photograph stays put as the
    reader scrolls -- an absolutely-positioned layer would scroll away and leave the
    lower half of the page on flat volcanic-teal, which is not the effect.

    It is safe here despite the warning in AGENTS.md about scroll containers: that
    was about `overflow: hidden` on `body`, which is not set, and about Lenis
    being configured with a wrapper, which it is not (`lerp` only). A `fixed`
    descendant breaks only when an ancestor between it and the viewport has a
    transform, filter or will-change. `<main>` here has `relative z-10` -- position
    and z-index only.

    NO `100vw`. It measures the viewport INCLUDING the vertical scrollbar and lands
    off-centre by half a scrollbar. The hero bleeds using negative margins that
    cancel `<main>`'s own padding instead, and those values must stay in step with
    `px-5 sm:px-8 lg:px-12`.

    EVERY PIECE OF INFORMATION THE PREVIOUS PAGE SHOWED IS STILL HERE: name, place,
    description, entrance fee, estimated cost, visit length, opening hours in full
    (per-day table, closed days, operating status, the kind-of-hours caveat, the
    note, the timezone, the last-checked date and the cited source), coordinates,
    map, budget tier, interest tags, photographs, reviews and the review form. Only
    the arrangement and the palette changed.
--}}

{{-- The blurred backdrop. Decorative, so it is hidden from assistive tech and
     cannot take a click meant for the page. `scale-125` because `blur-3xl` samples
     outside the element box and a 1:1 image otherwise leaves a transparent rim,
     which shows as a hard edge. --}}
<div
    aria-hidden="true"
    class="pointer-events-none fixed inset-0 z-0 overflow-hidden bg-volcanic-teal"
>
    @if ($destination->image_url)
        <img
            src="{{ $destination->image_url }}"
            alt=""
            class="absolute inset-0 h-full w-full scale-125 object-cover opacity-60 blur-3xl"
        >
    @else
        <span class="absolute inset-0 bg-gradient-to-br from-boracay via-cyan-500 to-volcanic-teal"></span>
    @endif

    {{-- The scrim. A gradient rather than a flat fill: heaviest at the top and
         bottom where the content is dense, lighter through the middle so the
         photograph is actually visible. A flat 70% across the whole page flattened
         it to solid teal and threw away the only reason for the layer. --}}
    <span class="absolute inset-0 bg-gradient-to-b from-volcanic-teal/92 via-volcanic-teal/78 to-volcanic-teal/95"></span>
</div>

<div class="relative z-10">
    {{-- ---------------------------------------------------------------------
         The hero: the destinations fan, over the photograph.
         --------------------------------------------------------------------- --}}
    <div class="-mx-5 -mt-10 sm:-mx-8 lg:-mx-12">
        @include('components.destinations.hero-carousel', [
            'destination' => $destination,
            'slides' => $carouselSlides,
            'activeIndex' => $carouselActiveIndex,
            'neighbours' => $carouselNeighbours,
        ])
    </div>

    {{-- A quiet way back, since the layout's header slot is a light band and the
         page is dark from the very top. --}}
    <div class="-mx-5 flex items-center justify-between gap-4 px-5 pt-8 sm:-mx-8 sm:px-8 lg:-mx-12 lg:px-12">
        <a
            href="{{ route('destinations.index') }}"
            class="text-sm font-semibold text-white/70 transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-philippine-gold"
        >
            ← All destinations
        </a>

        <span class="hidden text-xs font-bold uppercase tracking-[0.25em] text-white/40 sm:block">
            Luzon / Philippines
        </span>
    </div>

    {{-- ---------------------------------------------------------------------
         The facts, as a strip immediately under the fan.
         --------------------------------------------------------------------- --}}
    <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            [
                'label' => 'Entrance',
                'value' => '₱'.number_format($destination->entrance_fee, 2),
            ],
            [
                'label' => 'Estimated cost',
                'value' => '₱'.number_format($destination->estimated_cost, 2),
            ],
            [
                'label' => 'Visit length',
                'value' => $destination->recommended_minutes.' min',
            ],
            [
                'label' => 'Status',
                'value' => match ($openState) {
                    'open' => 'Open now',
                    'closed' => 'Closed now',
                    'closed_today' => 'Closed today',
                    default => 'Hours unknown',
                },
                'tone' => match ($openState) {
                    'open' => 'good',
                    'closed', 'closed_today' => 'bad',
                    default => 'neutral',
                },
            ],
        ] as $fact)
            <div class="rounded-2xl border border-white/10 bg-white/[0.06] p-5 backdrop-blur-sm">
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-boracay-light">
                    {{ $fact['label'] }}
                </p>

                <p @class([
                    'mt-3 text-2xl font-bold',
                    'text-emerald-300' => ($fact['tone'] ?? '') === 'good',
                    'text-red-300' => ($fact['tone'] ?? '') === 'bad',
                    'text-white' => ($fact['tone'] ?? 'neutral') === 'neutral',
                ])>{{ $fact['value'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- ---------------------------------------------------------------------
         The body.
         --------------------------------------------------------------------- --}}
    <div class="mt-8 grid gap-8 lg:grid-cols-[1.15fr_0.85fr]">
        {{-- Left column: the prose, the photographs, the reviews. --}}
        <div class="space-y-8">
            <section class="rounded-[2rem] border border-white/10 bg-white/[0.06] p-6 backdrop-blur-sm sm:p-8">
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-light">
                    01 / The place
                </p>

                <p class="mt-5 max-w-3xl text-lg leading-8 text-white/80">
                    {{ $destination->description }}
                </p>

                {{-- The interest tags. They were on the stage caption before the
                     caption became the page's h1, and they are the only place a
                     traveller is told what a place is actually good for. --}}
                @if ($destination->tags->isNotEmpty())
                    <div class="mt-6 flex flex-wrap gap-2">
                        @foreach ($destination->tags as $tag)
                            <span class="rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold text-white/85">
                                {{ $tag->name }}
                            </span>
                        @endforeach
                    </div>
                @endif
            </section>

            @if ($destination->images->isNotEmpty())
                <section class="rounded-[2rem] border border-white/10 bg-white/[0.06] p-6 backdrop-blur-sm sm:p-8">
                    <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-light">
                        02 / Photographs
                    </p>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        @foreach ($destination->images as $image)
                            <figure class="overflow-hidden rounded-2xl bg-volcanic-teal/40 ring-1 ring-white/10">
                                <img
                                    src="{{ $image->path }}"
                                    alt=""
                                    width="640"
                                    height="480"
                                    loading="lazy"
                                    decoding="async"
                                    class="aspect-[4/3] w-full object-cover"
                                >
                            </figure>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="rounded-[2rem] border border-white/10 bg-white/[0.06] p-6 backdrop-blur-sm sm:p-8">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-light">
                            03 / Reviews
                        </p>

                        <h2 class="mt-4 font-display text-3xl font-semibold text-white">
                            Traveler notes.
                        </h2>
                    </div>

                    @if ($destination->reviews->isNotEmpty())
                        <span class="tm-gold-badge shrink-0">
                            ★ {{ number_format($destination->reviews->avg('rating'), 1) }}
                        </span>
                    @endif
                </div>

                <div class="mt-6 space-y-5">
                    @forelse ($destination->reviews as $review)
                        <article class="border-t border-white/10 pt-5">
                            <div class="flex items-center justify-between gap-3">
                                <strong class="text-white">
                                    {{ $review->user->name }}
                                </strong>

                                <span class="text-sm font-semibold text-boracay-light">
                                    ★ {{ $review->rating }}/5
                                </span>
                            </div>

                            <p class="mt-3 text-sm leading-6 text-white/70">
                                {{ $review->comment }}
                            </p>
                        </article>
                    @empty
                        <p class="text-sm text-white/60">
                            No reviews yet.
                        </p>
                    @endforelse
                </div>

                @auth
                    <form
                        method="POST"
                        action="{{ route('reviews.store', $destination) }}"
                        class="mt-8 border-t border-white/10 pt-8"
                    >
                        @csrf

                        <h3 class="font-semibold text-white">
                            Share your experience
                        </h3>

                        <label class="mt-5 block">
                            <span class="text-sm font-semibold text-white/80">
                                Rating
                            </span>

                            <select
                                name="rating"
                                required
                                class="mt-2 w-full rounded-xl border-white/20 bg-white/10 text-white focus:border-boracay focus:ring-boracay"
                            >
                                @foreach ([5, 4, 3, 2, 1] as $rating)
                                    <option value="{{ $rating }}">
                                        {{ $rating }}/5
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label class="mt-5 block">
                            <span class="text-sm font-semibold text-white/80">
                                Review
                            </span>

                            <textarea
                                name="comment"
                                rows="4"
                                required
                                minlength="10"
                                class="mt-2 w-full rounded-xl border-white/20 bg-white/10 text-white placeholder:text-white/40 focus:border-boracay focus:ring-boracay"
                                placeholder="What should other travelers know?"
                            >{{ old('comment') }}</textarea>
                        </label>

                        <button type="submit" class="tm-primary-button mt-5 rounded-full">
                            Submit review
                        </button>
                    </form>
                @else
                    <p class="mt-8 border-t border-white/10 pt-8 text-sm text-white/60">
                        Log in to leave a review.
                    </p>
                @endauth
            </section>
        </div>

        {{-- Right column: hours, map, travel fit. --}}
        <div class="space-y-8">
            <section class="rounded-[2rem] border border-white/10 bg-white/[0.06] p-6 backdrop-blur-sm sm:p-8">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-light">
                            Opening hours
                        </p>

                        <p class="mt-4 text-2xl font-bold text-white">
                            {{ $hoursLabel ?? 'Hours not listed' }}
                        </p>
                    </div>

                    {{--
                        The state pill, restated beside the hours rather than
                        replacing them: "Closed today (Friday)" needs the day it is
                        referring to, and the tile above is too far away to read
                        across.
                    --}}
                    <span @class([
                        'rounded-full px-4 py-2 text-sm font-semibold ring-1',
                        'bg-emerald-400/20 text-emerald-200 ring-emerald-300/40' => $openState === 'open',
                        'bg-red-400/20 text-red-200 ring-red-300/40' => in_array($openState, ['closed', 'closed_today'], true),
                        'bg-white/10 text-white/70 ring-white/20' => $openState === 'unknown',
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
                    <div class="mt-5 overflow-hidden rounded-2xl ring-1 ring-white/10">
                        @foreach ($daySlugs as $day)
                            @php
                                $window = $perDayHours[$day] ?? null;
                                $isToday = strtolower($todayName) === $day;
                            @endphp
                            <div @class([
                                'flex items-center justify-between gap-4 px-4 py-2 text-sm',
                                'bg-boracay/20 font-semibold text-white' => $isToday,
                                'border-t border-white/10' => ! $loop->first,
                            ])>
                                <span>
                                    {{ ucfirst($day) }}
                                    @if ($isToday)<span class="ml-1 text-xs font-normal text-boracay-light">(today)</span>@endif
                                </span>
                                <span @class(['text-white/45' => $window === null])>
                                    {{ $window === null ? 'Closed' : $window['open'].'–'.$window['close'] }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($closedDaysLabel && ! $perDayHours)
                    <p class="mt-4 text-sm text-white/70">
                        <strong class="text-white">{{ $closedDaysLabel }}.</strong>
                        Hours above are for the days it is open.
                    </p>
                @endif

                @if ($destination->operating_status && $destination->operating_status !== 'open' && $openState === 'unknown')
                    <p class="mt-4 text-sm text-white/70">
                        Note: the source lists this place as
                        <strong class="text-white">{{ str_replace('_', ' ', $destination->operating_status) }}</strong>.
                    </p>
                @endif

                @if ($destination->hoursKindLabel())
                    {{--
                        The advisory styling is amber on the dark ground too, because
                        "this is a sign-up cut-off, not opening hours" must not read
                        as a neutral note. See Destination::hoursKindIsAdvisory().
                    --}}
                    <p @class([
                        'mt-4 rounded-xl px-3 py-2 text-sm ring-1',
                        'bg-amber-400/15 text-amber-100 ring-amber-300/30' => $destination->hoursKindIsAdvisory(),
                        'bg-white/10 text-white/80 ring-white/15' => ! $destination->hoursKindIsAdvisory(),
                    ])>
                        <strong>{{ $destination->hoursKindLabel() }}</strong>
                    </p>
                @endif

                @if ($destination->hours_note)
                    <p class="mt-4 text-sm text-white/70">
                        {{ $destination->hours_note }}
                    </p>
                @endif

                <p class="mt-5 text-xs text-white/45">
                    Times are Philippine time (UTC+8).
                    @if ($updatedOn)
                        <span class="mt-1 block">
                            Last checked {{ $updatedOn }}.
                        </span>
                    @endif
                </p>

                {{-- The citation. A traveller must be able to check these hours, so
                     this is not optional chrome. --}}
                @if ($destination->hours_source_url)
                    <p class="mt-3 border-t border-white/10 pt-3 text-xs text-white/55">
                        Hours from
                        <a
                            href="{{ $destination->hours_source_url }}"
                            target="_blank"
                            rel="noopener noreferrer nofollow"
                            class="font-semibold text-boracay-light underline underline-offset-2 hover:text-white"
                        >{{ $destination->hours_source_label ?: $destination->hours_source_url }}</a>
                        @if ($updatedOn)
                            <span class="block text-white/40">
                                Checked {{ $updatedOn }}. Hours change, so confirm before you travel.
                            </span>
                        @endif
                    </p>
                @endif
            </section>

            <section class="rounded-[2rem] border border-white/10 bg-white/[0.06] p-6 backdrop-blur-sm sm:p-8">
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-light">
                    04 / Location
                </p>

                {{-- `data-destination-map` and its siblings are read by app.js. The
                     container keeps a light ground so the tiles stay legible; a
                     darkened map is an unreadable map. --}}
                <div
                    data-destination-map
                    data-lat="{{ $destination->latitude }}"
                    data-lng="{{ $destination->longitude }}"
                    data-name="{{ $destination->name }}"
                    class="mt-6 h-[24rem] rounded-2xl bg-palawan-sand"
                ></div>

                <p class="mt-4 text-xs text-white/45">
                    {{ $destination->latitude }},
                    {{ $destination->longitude }}
                </p>
            </section>

            <section class="rounded-[2rem] border border-white/10 bg-white/[0.06] p-6 backdrop-blur-sm sm:p-8">
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-light">
                    05 / Travel fit
                </p>

                <h2 class="mt-5 font-display text-3xl font-semibold leading-tight text-white">
                    {{ ucfirst($destination->budget_level) }}
                    and ready to discover.
                </h2>

                @if (Route::has('discover.index'))
                    <a
                        href="{{ route('discover.index') }}"
                        class="tm-primary-button mt-7 inline-flex rounded-full"
                    >
                        Find similar places →
                    </a>
                @endif
            </section>
        </div>
    </div>

    <div class="mt-12 border-t border-white/10 pt-8 text-center">
        <a
            href="{{ route('destinations.index') }}"
            class="text-sm font-semibold text-white/60 transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-philippine-gold"
        >
            ← Back to all destinations
        </a>
    </div>
</div>