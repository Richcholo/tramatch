<x-app-layout>
    {{--
        /*
         * THE WHOLE PAGE IS THE STAGE'S WORLD.
         *
         * One continuous Deep Volcanic Teal surface, edge to edge, from the nav
         * bar down. The layout's header is already `bg-volcanic-teal`, so this
         * continues it instead of starting a new one -- there is no colour change
         * between the logo and the destination's name, and no Palawan Sand
         * anywhere on this page.
         *
         * The stage began as a dark theatre set into warm paper, and the heading
         * and the filtered bar sat on that paper above it. Asked to make the whole
         * page that layout, the paper went with the slab: a sand surface under a
         * teal one is two surfaces, and this only reads as one world with one
         * ground. Everything below -- the fees, the hours, the map, the reviews,
         * the form -- is now on the same surface, in the same light-on-dark
         * language, and the stage is its opening rather than a panel on it.
         *
         * `-mx-5 -my-10 sm:-mx-8` against the layout main's own
         * `px-5 py-10 sm:px-8 lg:px-12` is EXACT CANCELLATION, and only ever for
         * this one ground: the element ends up exactly as wide as its container's
         * content box with the background running under the padding. It is a
         * BACKGROUND BLEED, not a width. Nothing inside the page may carry a
         * `-mx-*`, and this is still not `100vw` -- that measures the viewport
         * including the vertical scrollbar and lands a full-bleed block
         * off-centre by about half a scrollbar.
         *
         * The `header` SLOT IS NOT USED HERE, and was not on the previous version
         * of this page either: it renders a bordered strip above `main`, on the
         * layout shell, which is now the same colour as the page and so has
         * nothing left to separate. The back link sits on the page with
         * everything else.
         */
    --}}
    <div class="tm-page -mx-5 -my-10 px-5 py-10 sm:-mx-8 sm:px-8 lg:-mx-8 lg:px-12">
        {{--
            THE OPENING SCREEN. Heading, filtered bar and stage, filling what is
            left of the first viewport below the nav bar.

            THE HEADING IS ISLAND WHITE, not the teal it is named for. Deep Volcanic
            Teal on Deep Volcanic Teal is 1:1 and the old heading simply vanished
            when the ground changed -- which is the whole reason this page's colour
            rule has to be re-derived rather than copied from the stage.
        --}}
        <section class="tm-page__opening">
            <div>
                <a
                    href="{{ route('destinations.index') }}"
                    class="text-sm font-semibold text-boracay-light transition hover:text-island-white"
                >
                    &larr; All destinations
                </a>

                <p class="mt-8 text-xs font-bold uppercase tracking-[0.3em] text-boracay-light">
                    Luzon / Philippines
                </p>

                <h1 class="mt-3 font-display text-5xl font-medium uppercase leading-[0.85] tracking-[-0.05em] text-island-white sm:text-7xl lg:text-8xl">
                    {{ $destination->name }}
                </h1>

                {{--
                    /*
                     * THE FILTERED BAR.
                     *
                     * A thin row of tracked uppercase sans labels under the heading.
                     * The interest tags are what it shows: they are the only
                     * per-destination editorial labels that exist as data, and they
                     * read the way a filter bar reads.
                     *
                     * NOT pills, and not buttons. They were pills on the old dark
                     * hero and they are labels here because that is what they are --
                     * nothing is filtered by them, so making them look interactive
                     * would be a lie.
                     *
                     * Boracay Light, NOT Philippine Gold. Gold is legal on this
                     * ground at 8.7:1 and is used for the accents below, but a
                     * decorative label is the one place a colour has no job, and
                     * spending the accent there devalues it everywhere else.
                     */
                --}}
                @if ($destination->tags->isNotEmpty())
                    <ul class="mt-6 flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-white/10 pt-5">
                        @foreach ($destination->tags->sortBy('name') as $tag)
                            <li class="text-[0.65rem] font-bold uppercase tracking-[0.25em] text-boracay-light">
                                {{ $tag->name }}
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{--
                /*
                 * THE STAGE.
                 *
                 * The opening statement of the page, with THIS destination's hero
                 * photograph already centred -- which is why this page's hero
                 * photograph block and the scroll-snap photo strip it used to sit
                 * above are both gone. Both of those photographs are panels in
                 * here now.
                 */
            --}}
            @include('components.destinations.stage')
        </section>

        {{-- THE FACTS. Same surface, same light-on-dark language, below the fold. --}}
        <div class="mt-16 grid gap-8 lg:grid-cols-[1.2fr_0.8fr]">
            <section class="space-y-8">
                <div>
                    <p class="tm-eyebrow">01 / The place</p>

                    <h2 class="mt-4 font-display text-4xl font-semibold tracking-[-0.04em] text-island-white sm:text-5xl">
                        A place to remember.
                    </h2>

                    {{--
                        /*
                         * `bodyDescription()`, NOT `description`.
                         *
                         * The stage caption prints the description's FIRST SENTENCE
                         * as its standfirst and this prints what is left. Printing
                         * the description in both places put the same opening
                         * sentence on screen twice within one screenful, which is
                         * what this pair exists to prevent.
                         *
                         * Falls back to the whole description when there is no
                         * standfirst to remove, so a one-sentence description is
                         * never emptied out of the page.
                         */
                    --}}
                    <p class="tm-prose mt-5 max-w-3xl">
                        {{ $destination->bodyDescription() }}
                    </p>
                </div>

                {{-- The three fees. --}}
                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach ([
                        ['Entrance', '₱'.number_format($destination->entrance_fee, 2)],
                        ['Estimated cost', '₱'.number_format($destination->estimated_cost, 2)],
                        ['Visit length', $destination->recommended_minutes.' min'],
                    ] as [$label, $value])
                        <div class="tm-panel">
                            <p class="tm-eyebrow">{{ $label }}</p>

                            <p class="mt-5 font-display text-3xl font-semibold tabular-nums text-island-white">
                                {{ $value }}
                            </p>
                        </div>
                    @endforeach
                </div>

                {{-- Opening hours, in full: per-day windows, closed days, operating status, the kind-of-hours caveat, the note, the timezone, the last-checked date and the cited source. --}}
                <div class="tm-panel">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="tm-eyebrow">Opening hours</p>

                            <p class="mt-4 font-display text-3xl font-semibold text-island-white">
                                {{ $hoursLabel ?? 'Hours not listed' }}
                            </p>
                        </div>

                        {{--
                            A LIGHT PILL on the dark ground, which is why the status
                            colours can stay: they are not tinting the background, they
                            are a chip of their own with dark text inside. And they are
                            never the only signal -- the badge says "Open now" /
                            "Closed now" / "Closed today", so nothing here is
                            communicated by colour alone.
                        --}}
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
                        <div class="mt-6 overflow-hidden rounded-2xl bg-black/20">
                            @foreach ($daySlugs as $day)
                                @php
                                    $window = $perDayHours[$day] ?? null;
                                    $isToday = strtolower($todayName) === $day;
                                @endphp
                                <div @class(['tm-row', 'tm-row--today' => $isToday])>
                                    <span>
                                        {{ ucfirst($day) }}
                                        @if ($isToday)
                                            <span class="tm-row__note">(today)</span>
                                        @endif
                                    </span>

                                    <span>
                                        {{ $window === null ? 'Closed' : $window['open'].'–'.$window['close'] }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($closedDaysLabel && ! $perDayHours)
                        <p class="tm-prose mt-5 text-sm">
                            <strong class="text-island-white">{{ $closedDaysLabel }}.</strong>
                            Hours above are for the days it is open.
                        </p>
                    @endif

                    @if ($destination->operating_status && $destination->operating_status !== 'open' && $openState === 'unknown')
                        <p class="tm-prose mt-5 text-sm">
                            Note: the source lists this place as
                            <strong class="text-island-white">{{ str_replace('_', ' ', $destination->operating_status) }}</strong>.
                        </p>
                    @endif

                    {{--
                        "This is a sign-up cut-off, not opening hours" must not read
                        as a neutral note on the dark ground either, so the advisory
                        keeps its amber. It is a light pill with dark text, like the
                        status badge above.
                    --}}
                    @if ($destination->hoursKindLabel())
                        <p @class([
                            'mt-5 rounded-xl px-4 py-3 text-sm',
                            'bg-amber-100 text-amber-900' => $destination->hoursKindIsAdvisory(),
                            'tm-prose border border-white/10' => ! $destination->hoursKindIsAdvisory(),
                        ])>
                            <strong>{{ $destination->hoursKindLabel() }}</strong>
                        </p>
                    @endif

                    @if ($destination->hours_note)
                        <p class="tm-prose mt-5 text-sm">
                            {{ $destination->hours_note }}
                        </p>
                    @endif

                    <p class="mt-6 border-t border-white/10 pt-5 text-xs text-boracay-light/50">
                        Times are Philippine time (UTC+8).
                        @if ($updatedOn)
                            <span class="mt-1 block">
                                Last checked {{ $updatedOn }}.
                            </span>
                        @endif
                    </p>

                    @if ($destination->hours_source_url)
                        <p class="mt-4 text-xs text-boracay-light/50">
                            Hours from
                            <a
                                href="{{ $destination->hours_source_url }}"
                                target="_blank"
                                rel="noopener noreferrer nofollow"
                                class="font-semibold text-boracay-light underline underline-offset-2 transition hover:text-island-white"
                            >{{ $destination->hours_source_label ?: $destination->hours_source_url }}</a>
                            @if ($updatedOn)
                                <span class="mt-1 block text-boracay-light/40">
                                    Checked {{ $updatedOn }}. Hours change, so confirm before you travel.
                                </span>
                            @endif
                        </p>
                    @endif
                </div>

                {{-- The map. --}}
                <div class="tm-panel">
                    <p class="tm-eyebrow">02 / Location</p>

                    <div
                        data-destination-map
                        data-lat="{{ $destination->latitude }}"
                        data-lng="{{ $destination->longitude }}"
                        data-name="{{ $destination->name }}"
                        class="mt-6 h-[28rem] w-full overflow-hidden rounded-[1.5rem] bg-boracay-light"
                    ></div>

                    {{-- On one line: the two numbers are one fact, and a newline between them
                         renders as `14.1,` / `121.5` on separate lines. --}}
                    <p class="mt-4 text-xs text-boracay-light/50">
                        {{ $destination->latitude }}, {{ $destination->longitude }}
                    </p>
                </div>
            </section>

            <aside class="space-y-8">
                {{--
                    A panel like every other one. It used to be a solid
                    `bg-boracay-dark` card, which on this ground was one opaque
                    rectangle among translucent ones and read as a different kind of
                    object rather than as a section of the same page.
                --}}
                <section class="tm-panel">
                    <p class="tm-eyebrow">03 / Travel fit</p>

                    <h2 class="mt-5 font-display text-4xl font-semibold leading-tight text-island-white">
                        {{ ucfirst($destination->budget_level) }}
                        and ready to discover.
                    </h2>

                    {{--
                        The one filled button on the page. Boracay Turquoise with Deep
                        Volcanic Teal text, which is the pairing that passes on a large
                        fill. Gold with charcoal text is the option that looks more
                        "on brand" and measures worse, and gold is spoken for as an
                        accent already.
                    --}}
                    @if (Route::has('discover.index'))
                        <a
                            href="{{ route('discover.index') }}"
                            class="mt-8 inline-flex rounded-full bg-boracay px-5 py-3 font-bold text-volcanic-teal transition hover:bg-island-white"
                        >
                            Find similar places →
                        </a>
                    @endif
                </section>

                <section class="tm-panel">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="tm-eyebrow">04 / Reviews</p>

                            <h2 class="mt-4 font-display text-3xl font-semibold text-island-white">
                                Traveler notes.
                            </h2>
                        </div>

                        @if ($destination->reviews->isNotEmpty())
                            <span class="tm-gold-badge tm-gold-badge--dark">
                                ★ {{ number_format($destination->reviews->avg('rating'), 1) }}
                            </span>
                        @endif
                    </div>

                    <div class="mt-6 space-y-5">
                        @forelse ($destination->reviews as $review)
                            <article class="border-t border-white/10 pt-5">
                                <div class="flex items-center justify-between gap-3">
                                    <strong class="text-island-white">
                                        {{ $review->user->name }}
                                    </strong>

                                    <span class="text-sm font-semibold text-boracay-light">
                                        ★ {{ $review->rating }}/5
                                    </span>
                                </div>

                                <p class="tm-prose mt-3 text-sm">
                                    {{ $review->comment }}
                                </p>
                            </article>
                        @empty
                            <p class="tm-prose text-sm">
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

                            <h3 class="font-semibold text-island-white">
                                Share your experience
                            </h3>

                            <label class="mt-5 block">
                                <span class="text-sm font-semibold text-boracay-light">
                                    Rating
                                </span>

                                <select name="rating" required class="tm-field mt-2">
                                    @foreach ([5, 4, 3, 2, 1] as $rating)
                                        <option value="{{ $rating }}">
                                            {{ $rating }}/5
                                        </option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="mt-5 block">
                                <span class="text-sm font-semibold text-boracay-light">
                                    Review
                                </span>

                                <textarea
                                    name="comment"
                                    rows="4"
                                    required
                                    minlength="10"
                                    class="tm-field mt-2"
                                    placeholder="What should other travelers know?"
                                >{{ old('comment') }}</textarea>
                            </label>

                            <button type="submit" class="tm-primary-button mt-5 rounded-full">
                                Submit review
                            </button>
                        </form>
                    @else
                        <p class="tm-prose mt-8 border-t border-white/10 pt-8 text-sm">
                            Log in to leave a review.
                        </p>
                    @endauth
                </section>
            </aside>
        </div>
    </div>
</x-app-layout>
