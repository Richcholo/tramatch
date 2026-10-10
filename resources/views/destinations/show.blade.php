<x-app-layout>
<<<<<<< Updated upstream
    <x-slot name="header">
        <a
            href="{{ route('destinations.index') }}"
            class="text-sm font-semibold text-boracay-dark hover:text-volcanic-teal"
        >
            ← Back to destinations
        </a>
    </x-slot>

    <div class="space-y-10">
        <section class="relative overflow-hidden rounded-[2rem] bg-volcanic-teal text-white shadow-xl">
            @if ($destination->image_url)
                <img
                    src="{{ $destination->image_url }}"
                    alt="{{ $destination->name }}"
                    class="absolute inset-0 h-full w-full object-cover opacity-55"
=======
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
         * `main` is full-width on this route. `-mx-5 -my-10 sm:-mx-8` against
         * its own `px-5 py-10 sm:px-8 lg:px-12` cancels the gutters so this
         * background reaches the viewport edges; the inner wrapper keeps the
         * page content at its original width. This is a BACKGROUND BLEED, not a
         * `100vw` width -- that measures the viewport including the vertical
         * scrollbar and lands a full-bleed block off-centre by about half a
         * scrollbar.
         *
         * The `header` SLOT IS NOT USED HERE, and was not on the previous version
         * of this page either: it renders a bordered strip above `main`, on the
         * layout shell, which is now the same colour as the page and so has
         * nothing left to separate. The back link sits on the page with
         * everything else.
         */
    --}}
    <div class="tm-page -mx-5 -my-10 px-5 py-10 sm:-mx-8 sm:px-8 lg:-mx-12 lg:px-12">
        {{--
        THE OPENING SCREEN. Heading, filtered bar and stage, filling what is
        left of the first viewport below the nav bar.

        THE HEADING IS ISLAND WHITE, not the teal it is named for. Deep Volcanic
        Teal on Deep Volcanic Teal is 1:1 and the old heading simply vanished
        when the ground changed -- which is the whole reason this page's colour
        rule has to be re-derived rather than copied from the stage.
        --}}
        <section class="tm-page__opening mx-auto w-full max-w-[1504px]">
            <div>
                <a
                    href="{{ route('destinations.index') }}"
                    class="text-sm font-semibold text-boracay-light transition hover:text-island-white"
>>>>>>> Stashed changes
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

<<<<<<< Updated upstream
        <div class="grid gap-8 lg:grid-cols-[1.2fr_0.8fr]">
=======
        {{-- THE FACTS. Same surface, same light-on-dark language, below the fold. --}}
        <div class="mx-auto mt-16 grid w-full max-w-[1504px] gap-8 lg:grid-cols-[1.2fr_0.8fr]">
>>>>>>> Stashed changes
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