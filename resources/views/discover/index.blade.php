<x-app-layout>
    {{--
        ONE DARK SURFACE, FROM THE NAV BAR DOWN -- the same ground the
        destination stage lives on, and for the same reason: the deck
        is a theatre of photographs, and a photograph reads best on the
        ground it is lit from.

        The layout's `main` is deliberately bare on this route (`w-full`,
        no padding of its own), so `.tm-page` needs none of the
        destination page's negative-margin bleed -- the teal simply runs
        edge to edge. Do not add the destination page's `-mx-*` classes
        here: there is no padding to cancel, so they would overrun the
        viewport instead of bleeding back over it.

        On this ground the palette INVERTS, exactly as it does on the
        destination page: gold and Boracay Turquoise are the accents
        (they fail on Palawan Sand, they carry on Deep Volcanic Teal),
        and the dark palette -- `text-benguet-charcoal`,
        `text-volcanic-teal`, `bg-palawan-sand` -- is what must not
        appear. The exceptions are the light surfaces, which are the
        point: the count pill and the reset dialog are light chips with
        dark text, the same "light pill on teal" the stage's status
        badges use.
    --}}
    {{--
        Transient feedback is a TOAST, not the layout's flash banner.

        The banner is a light slab inside `<main>`, and on this route `main` has
        no padding -- it is `w-full` so the deck can be one dark surface edge to
        edge. A light banner there renders full-bleed against the viewport edges
        and reads as a slab that escaped the design rather than as part of it.
        Wrapping it in the page's own field was tried first and was the wrong
        fix: that made the notice fit while keeping a large light rectangle
        pushing the deck down the screen.

        Resetting the deck also changes nothing about the page it lands on, so
        there is no page state here for a banner to describe.

        `toast_message` is the key the crawl pages already use with this
        component, so the two keys never fire together.
    --}}
    <x-flash-toast />

    <div class="tm-page relative isolate overflow-hidden py-10 sm:py-12">
        {{--
            THE BACKDROP: the page's own ground, a blurred and
            darkened photograph behind everything, which cross-fades
            to the main photo of whichever card the pointer is over.

            Two layers, because `src` cannot be cross-faded -- it
            can only be replaced -- so the script paints the hidden
            layer with the hovered card's photograph first and then
            swaps which layer is shown. That is the destinations
            stage's backdrop mechanism, moved to the deck.

            It is the page's ground, so it is the page's first
            child and nothing else: `z-index: -1` inside the page's
            own stacking context (`isolate` on `.tm-page`) puts it
            above the teal and below every word.
        --}}
        <div data-discover-backdrops class="tm-discover-backdrops" aria-hidden="true">
            <img data-discover-backdrop class="tm-discover-backdrop" alt="">
            <img data-discover-backdrop class="tm-discover-backdrop" alt="">
        </div>

        <header class="mb-8 border-b border-white/10 px-5 pb-6 sm:px-8 lg:px-12">
            <div class="mx-auto flex max-w-6xl flex-col justify-between gap-6 sm:flex-row sm:items-end">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.25em] text-philippine-gold">
                    Your next escape
                </p>
                <h1 class="mt-3 max-w-2xl font-display text-4xl font-semibold leading-tight text-island-white sm:text-5xl">
                    Find your next favorite.
                </h1>
            </div>

            <div class="flex flex-wrap items-center gap-4 sm:justify-end">
                {{--
                    A LIGHT PILL, deliberately. The stage's status badges
                    keep their light chips on the teal ground, and this
                    counter is the same kind of thing: a small fact that
                    reads as ink on paper, set down on the dark.
                --}}
                <p class="rounded-full bg-island-white px-4 py-2 text-sm font-semibold text-volcanic-teal ring-1 ring-white/20">
                    <span data-swipe-count>{{ $cards->count() }}</span>
                    <span class="ml-1 text-benguet-charcoal/65">{{ $cards->count() === 1 ? 'place' : 'places' }} left</span>
                </p>
                {{--
                    THE TAKE-BACK. Shown only when a decision exists to
                    undo — this is the affordance for the swipe that just
                    happened, whatever it was (like, pass or skip). Undoing
                    pops the MOST RECENT row server-side, so it works even
                    after a reload; the script only restores the card it is
                    holding, and reloads when the server's answer is not it.
                --}}
                @if ($swipedCount > 0)
                    <button type="button" data-swipe-undo data-undo-endpoint="{{ route('discover.swipes.undo') }}" class="min-h-10 rounded-md px-3 py-2 text-sm font-semibold text-island-white ring-1 ring-white/25 transition hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-philippine-gold active:scale-[0.98] active:duration-75">
                        Undo last
                    </button>
                @endif
                {{--
                    THE ASSIST. Rendered only when a Gemini key is
                    configured, because a control that cannot work should
                    not be offered. It is a plain POST form like every
                    other redirect affordance here — no JavaScript — and
                    the reply comes back as the suggestion panel below.
                --}}
                @if ($assistAvailable)
                    <form method="POST" action="{{ route('discover.assist') }}">
                        @csrf
                        <button type="submit" class="min-h-10 rounded-md px-3 py-2 text-sm font-semibold text-philippine-gold ring-1 ring-philippine-gold/40 transition hover:bg-philippine-gold/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-philippine-gold active:scale-[0.98] active:duration-75">
                            Stuck? Ask for a nudge
                        </button>
                    </form>
                @endif
                <button type="button" onclick="document.getElementById('reset-discovery-confirmation').showModal()" class="min-h-10 rounded-md px-3 py-2 text-sm font-semibold text-island-white ring-1 ring-white/25 transition hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-philippine-gold">
                    Reset deck
                </button>
            </div>
            </div>
        </header>

        <dialog
            id="reset-discovery-confirmation"
            aria-labelledby="reset-discovery-confirmation-title"
            aria-describedby="reset-discovery-confirmation-description"
            onclick="if (event.target === this) this.close()"
            class="m-auto w-[calc(100%-2rem)] max-w-md rounded-lg border border-boracay-light bg-palawan-sand p-0 text-benguet-charcoal shadow-2xl backdrop:bg-volcanic-teal/60 backdrop:backdrop-blur-sm"
        >
            <div class="p-6 sm:p-7">
                <div class="flex items-start gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-boracay-light text-boracay-dark" aria-hidden="true">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12a7.5 7.5 0 0112.8-5.3L20 9m0 0V4m0 5h-5m4.5 3a7.5 7.5 0 01-12.8 5.3L4 15m0 0v5m0-5h5" />
                        </svg>
                    </span>
                    <div>
                        <h2 id="reset-discovery-confirmation-title" class="font-display text-xl font-semibold text-volcanic-teal">
                            Reset your discovery deck?
                        </h2>
                        <p id="reset-discovery-confirmation-description" class="mt-2 text-sm leading-6 text-benguet-charcoal/70">
                            Your previous passes and likes will be cleared so you can start fresh.
                        </p>
                    </div>
                </div>

                <form method="POST" action="{{ route('discover.reset') }}" class="mt-7 flex justify-end gap-3">
                    @csrf
                    <button type="button" autofocus onclick="this.closest('dialog').close()" class="rounded-full border border-boracay-light px-4 py-2 text-sm font-semibold text-benguet-charcoal transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-boracay focus:ring-offset-2">
                        Cancel
                    </button>
                    <button type="submit" class="rounded-full bg-boracay px-4 py-2 text-sm font-bold text-benguet-charcoal transition hover:bg-boracay-dark hover:text-white focus:outline-none focus:ring-2 focus:ring-boracay focus:ring-offset-2">
                        Reset deck
                    </button>
                </form>
            </div>
        </dialog>

        <div class="mx-auto max-w-6xl px-5 sm:px-8 lg:px-12">
        @php $assistResult = session('assist_result'); @endphp
        @if (($assistResult['status'] ?? '') === 'ok')
            {{--
                THE SUGGESTION PANEL, one POST round-trip later.

                Everything factual on it is FROM THE CATALOGUE: the
                destinations were re-read from the database by the
                service, so the name, the area and the fee are this
                app's data and never the model's arithmetic. The one
                thing that arrives from the model is the sentence, and
                it is labelled as the model's own reason.

                The rejected count is printed when it is not zero: an
                id the model invented was dropped server-side, and a
                traveller deserves to be able to see that it happened
                rather than wonder why only two of three picks arrived.
            --}}
            <section data-assist-panel class="tm-panel mb-10 px-6 py-8 sm:px-10">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.22em] text-philippine-gold">
                            Gemini's read
                        </p>
                        <h2 class="mt-3 font-display text-2xl font-semibold text-island-white sm:text-3xl">
                            Worth a look next.
                        </h2>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-island-white/65">
                            Chosen from the {{ $assistResult['asked_over'] }} places your deck is dealing,
                            then checked against the catalogue and your budget tier before anything
                            was shown.
                        </p>
                    </div>
                    <span class="rounded-full bg-white/10 px-3 py-1.5 text-xs font-medium text-island-white ring-1 ring-white/10">
                        One request · up to 3 picks
                    </span>
                </div>

                <ul class="mt-6 divide-y divide-white/10">
                    @foreach ($assistResult['suggestions'] as $suggestion)
                        <li class="py-4 first:pt-1 last:pb-1">
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                <a href="{{ route('destinations.show', $suggestion['destination']->slug) }}" class="font-display text-lg font-semibold text-island-white transition hover:text-boracay-light">
                                    {{ $suggestion['destination']->name }}
                                </a>
                                <span class="text-sm text-island-white/55">
                                    {{ $suggestion['destination']->municipality }}, {{ $suggestion['destination']->province }}
                                </span>
                            </div>
                            <p class="mt-1.5 max-w-2xl text-sm leading-6 text-island-white/75">
                                &ldquo;{{ $suggestion['why'] }}&rdquo;
                                <span class="text-island-white/45">— the model's own words, unchecked.</span>
                            </p>
                        </li>
                    @endforeach
                </ul>

                @if (($assistResult['rejected'] ?? 0) > 0)
                    <p class="mt-5 border-t border-white/10 pt-4 text-sm text-island-white/50">
                        {{ $assistResult['rejected'] }} of the picks it named {{ $assistResult['rejected'] === 1 ? 'was' : 'were' }} held back —
                        invented ids and repeat mentions are not displayed.
                    </p>
                @endif
            </section>
        @endif
        @if ($cards->isEmpty())
            {{--
                THE EMPTY DECK, AND EVERY BRANCH IS TRUE.

                The single "You are all caught up." was the one sentence for
                four different outcomes, including for a traveller who had
                swiped NOTHING — a headline reading as an achievement about a
                state they had never entered. The reasons come from
                `SwipeDeckService::emptyState()`, which names the FIRST filter
                that emptied the pool, in the order the deck applies them.

                NO FALLBACK CARDS. The honest answer under every branch is
                "why it is empty" plus TWO ways out — never a synthetic card
                pretending the deck still has work in it.
            --}}
            <section data-swipe-empty class="tm-panel mx-auto max-w-3xl px-6 py-14 text-center sm:px-12 sm:py-20">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-philippine-gold/15 text-2xl font-semibold text-philippine-gold" aria-hidden="true">
                    &#10022;
                </div>

                @switch($emptyState['reason'])
                    @case('no_interests')
                        <p class="mt-6 text-xs font-bold uppercase tracking-[0.22em] text-philippine-gold">
                            Nothing matched
                        </p>
                        <h2 class="mt-3 font-display text-3xl font-semibold text-island-white sm:text-4xl">
                            No {{ ucfirst($emptyState['tier']) }} place shares your interests.
                        </h2>
                        @if (($emptyState['elsewhere'] ?? 0) > 0)
                            <p class="mx-auto mt-3 max-w-md text-sm leading-6 text-island-white/65">
                                {{ $emptyState['elsewhere'] }} place(s) DO carry your interests — at another budget level.
                            </p>
                        @else
                            <p class="mx-auto mt-3 max-w-md text-sm leading-6 text-island-white/65">
                                The interests you picked match nothing in this catalogue yet.
                            </p>
                        @endif
                        <div class="mt-7 flex flex-wrap items-center justify-center gap-3">
                            <a href="{{ route('preferences.edit') }}" class="tm-primary-button">
                                Change my interests
                            </a>
                            <a href="{{ route('destinations.index') }}" class="inline-flex min-h-11 items-center rounded-full border border-white/30 px-5 py-3 text-sm font-semibold text-island-white transition hover:border-island-white hover:bg-white/10">
                                Browse every place
                            </a>
                        </div>
                    
                    @break

                    @case('region_nothing')
                        <p class="mt-6 text-xs font-bold uppercase tracking-[0.22em] text-philippine-gold">
                            Region empty
                        </p>
                        <h2 class="mt-3 font-display text-3xl font-semibold text-island-white sm:text-4xl">
                            {{ $emptyState['region'] }} has no {{ $emptyState['tier'] }} places.
                        </h2>
                        <p class="mx-auto mt-3 max-w-md text-sm leading-6 text-island-white/65">
                            There ARE {{ $emptyState['tier'] }} places in the catalogue — {{ $emptyState['tier_count'] }} of them. Your region excludes every one.
                        </p>
                        <div class="mt-7 flex flex-wrap items-center justify-center gap-3">
                            <a href="{{ route('preferences.edit') }}" class="tm-primary-button">
                                Widen my region
                            </a>
                            <a href="{{ route('destinations.index') }}" class="inline-flex min-h-11 items-center rounded-full border border-white/30 px-5 py-3 text-sm font-semibold text-island-white transition hover:border-island-white hover:bg-white/10">
                                Browse every place
                            </a>
                        </div>
                    
                    @break

                    @case('tier_nothing')
                        <p class="mt-6 text-xs font-bold uppercase tracking-[0.22em] text-philippine-gold">
                            Not here yet
                        </p>
                        <h2 class="mt-3 font-display text-3xl font-semibold text-island-white sm:text-4xl">
                            Nothing is curated for {{ ucfirst($emptyState['tier']) }} yet.
                        </h2>
                        <p class="mx-auto mt-3 max-w-md text-sm leading-6 text-island-white/65">
                            Coverage is owned per budget level. This tier is empty while the catalogue grows.
                        </p>
                        <div class="mt-7 flex flex-wrap items-center justify-center gap-3">
                            <a href="{{ route('preferences.edit') }}" class="tm-primary-button">
                                Try another budget
                            </a>
                            <a href="{{ route('destinations.index') }}" class="inline-flex min-h-11 items-center rounded-full border border-white/30 px-5 py-3 text-sm font-semibold text-island-white transition hover:border-island-white hover:bg-white/10">
                                Browse every place
                            </a>
                        </div>
                    
                    @break

                    @case('all_swiped')
                        <p class="mt-6 text-xs font-bold uppercase tracking-[0.22em] text-philippine-gold">
                            You have been through {{ $emptyState['matched'] }}
                        </p>
                        <h2 class="mt-3 font-display text-3xl font-semibold text-island-white sm:text-4xl">
                            You have swiped every place that matched.
                        </h2>
                        <p class="mx-auto mt-3 max-w-md text-sm leading-6 text-island-white/65">
                            Everything your interests and budget pointed at has been decided. Resetting shows the passed places again — your likes stay liked.
                        </p>
                        <div class="mt-7 flex flex-wrap items-center justify-center gap-3">
                            {{-- NOT behind its dialog here: reaching the end of the
                                 whole deck through ONE card after another is the
                                 arrival the reset dialog was confirming the way to. --}}
                            <form method="POST" action="{{ route('discover.reset') }}">
                                @csrf
                                <button type="submit" class="tm-primary-button">
                                    Show me the passed places again
                                </button>
                            </form>
                            @if (Route::has('recommendations.index'))
                                <a href="{{ route('recommendations.index') }}" class="inline-flex min-h-11 items-center rounded-full border border-white/30 px-5 py-3 text-sm font-semibold text-island-white transition hover:border-island-white hover:bg-white/10">
                                    View my liked places
                                </a>
                            @endif
                        </div>
                    
                    @break

                    @default
                        {{-- 'cards' can only appear up here if the deck emptied
                             between the logo and the render -- a state we do not
                             think is reachable. Honest wording for it: say what
                             happened, offer nothing invented. --}}
                        <p class="mt-6 text-sm text-island-white/65">
                            Refresh the page — the deck could not decide why it is empty.
                        </p>
                    @endswitch
            </section>
        @else
            {{--
                THE DECK. The frame is deliberately WIDER than the card
                in front of it -- the cards are inset from its edges --
                because that is the room the two cards behind the top
                one fan out into. It is the stage's own geometry: the
                flanking panels are visible because the stage is far
                wider than the centre panel, and a fan whose frame is
                exactly as wide as its front card crops its own fan to
                nothing.

                The deck deliberately does NOT clip. The fanned cards
                are meant to continue past the frame into the page's
                own padding, and a swipe has to FLY off the screen:
                `overflow-hidden` here would cut the dragged card off
                at the frame's edge -- an invisible border the gesture
                dies against. The page itself (`.tm-page`) carries the
                `overflow-hidden` that keeps a fanned card from
                scrolling the viewport sideways, and that is enough:
                a clipping ancestor is a clipping ancestor wherever
                it sits.
            --}}
            <div data-swipe-deck data-endpoint="{{ route('discover.swipes.store') }}" class="relative mx-auto h-[660px] w-full max-w-[44rem] touch-pan-y sm:h-[680px]">
                @foreach ($cards as $destination)
                    <article data-swipe-card data-destination-id="{{ $destination->id }}" class="tm-swipe-card absolute inset-y-0 inset-x-4 sm:inset-x-16 flex touch-pan-y select-none cursor-grab flex-col overflow-hidden rounded-2xl active:cursor-grabbing">
                        @if ($destination->image_url)
                            <div class="relative h-64 shrink-0 overflow-hidden sm:h-[18rem]">
                                <img src="{{ $destination->image_url }}" alt="{{ $destination->name }}" draggable="false" class="h-full w-full object-cover">
                                <div class="absolute inset-x-0 bottom-0 h-28 bg-gradient-to-t from-benguet-charcoal/65 to-transparent" aria-hidden="true"></div>
                                <p class="absolute bottom-4 left-5 max-w-[70%] text-sm font-medium text-white drop-shadow sm:left-7">
                                    {{ $destination->municipality }}, {{ $destination->province }}
                                </p>
                                {{-- THE STRETCH CARD SAYS SO. One card in the
                                     deck is chosen for sharing NONE of the
                                     traveller's interests, and it is labelled
                                     "outside your interests" in BOTH the
                                     photograph and the plate. A "0% match" badge
                                     would be honest arithmetic and useless
                                     copy: it reads as a place that failed,
                                     where the honest fact is that this one was
                                     picked precisely because it is unlike
                                     everything else. --}}
                                @if ($destination->is_novelty ?? false)
                                    <span class="tm-gold-badge tm-gold-badge--dark absolute right-5 top-5 sm:right-7">
                                        Outside your interests
                                    </span>
                                @else
                                    <span class="tm-gold-badge tm-gold-badge--dark absolute right-5 top-5 sm:right-7">
                                        {{ $destination->preference_score }}% match
                                    </span>
                                @endif
                            </div>
                        @else
                            {{--
                                A TYPOGRAPHIC PLATE, not a gradient. The
                                stage's rule is the one to keep: a flat
                                colour pretending to be a photograph behind
                                a scrim is a photograph the app does not
                                have. Province and municipality are set in
                                type on the card's own ground instead.
                            --}}
                            <div class="tm-swipe-plate relative flex h-64 shrink-0 flex-col items-start justify-end overflow-hidden p-5 sm:h-[18rem] sm:p-7">
                                @if ($destination->is_novelty ?? false)
                                    <span class="tm-gold-badge tm-gold-badge--dark absolute right-5 top-5 sm:right-7">
                                        Outside your interests
                                    </span>
                                @else
                                    <span class="tm-gold-badge tm-gold-badge--dark absolute right-5 top-5 sm:right-7">
                                        {{ $destination->preference_score }}% match
                                    </span>
                                @endif
                                <p class="font-display text-3xl font-semibold leading-tight text-island-white sm:text-4xl">
                                    {{ $destination->province }}
                                </p>
                                <p class="mt-1 text-sm text-island-white/60">
                                    {{ $destination->municipality }}
                                </p>
                            </div>
                        @endif

                        <div class="flex min-h-0 flex-1 flex-col p-5 sm:p-7">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.2em] text-philippine-gold">
                                    Destination
                                </p>
                                <h2 class="mt-2 font-display text-2xl font-semibold leading-tight text-island-white sm:text-3xl">
                                    {{ $destination->name }}
                                </h2>
                                @unless ($destination->image_url)
                                    <p class="mt-1 text-sm text-island-white/60">
                                        {{ $destination->municipality }}, {{ $destination->province }}
                                    </p>
                                @endunless
                            </div>

                            <p class="mt-4 line-clamp-4 text-sm leading-6 text-island-white/75">
                                {{ $destination->description }}
                            </p>

                            <div class="mt-4 flex flex-wrap gap-2">
                                @foreach ($destination->tags->take(4) as $tag)
                                    <span class="rounded-full bg-white/10 px-3 py-1.5 text-xs font-medium text-island-white ring-1 ring-white/10">
                                        {{ $tag->name }}
                                    </span>
                                @endforeach
                            </div>

                            <div class="mt-auto grid grid-cols-3 gap-2 border-t border-white/10 pt-5">
                                {{--
                                    THREE ACTIONS, NOT TWO. "Pass" is a no — it
                                    leaves the deck AND weighs its tags down —
                                    while "Decide later" ("skip") parks the
                                    card with no opinion at all: it leaves the
                                    deck but never scores a place down, and
                                    undoing it is a plain return. Before the
                                    third button existed, a "no" and a shrug
                                    looked identical in the data.
                                --}}
                                <button type="button" data-swipe-action="passed" class="min-h-12 rounded-md border border-white/20 px-3 py-3 text-sm font-semibold text-island-white transition hover:border-red-300/60 hover:bg-red-400/10 hover:text-red-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-300 active:scale-[0.98] active:duration-75">
                                    Pass
                                </button>
                                <button type="button" data-swipe-action="skip" class="min-h-12 rounded-md border border-white/20 px-3 py-3 text-sm font-medium text-island-white/70 transition hover:border-white/40 hover:bg-white/5 hover:text-island-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-boracay-light active:scale-[0.98] active:duration-75">
                                    Decide later
                                </button>
                                <button type="button" data-swipe-action="liked" class="tm-primary-button min-h-12 px-3 py-3 text-sm active:scale-[0.98]">
                                    Like
                                </button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
        </div>
    </div>
</x-app-layout>
