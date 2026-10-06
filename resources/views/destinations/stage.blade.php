{{--
    The hero stage.

    A dark full-bleed panel holding a 3D fanned carousel of this destination's own
    photos, with the page's title floating over it as a stage-level overlay.

    Built from a written reference for a different product, adapted to TraMatch's
    identity rather than copied: our volcanic-teal/boracay/gold palette and
    Playfair Display instead of that page's cream-and-orange, and no "BOOK NOW"
    CTA, because this app books nothing.

    WHAT WAS KEPT, because it is structural rather than decorative:

      - Five slots on a shallow arc: the active card full size, a symmetric pair
        flanking it, a further pair behind those, each further pair rotated
        more, scaled down, pushed down and scrimmed. Depth reads immediately.
      - All five share ONE aspect ratio, so the fan is perspective rather than a
        scale distortion. Every card is aspect-[2/5] and nothing is stretched.
      - The title is NOT a child of a card. It is a stage-level overlay centred on
        the stage, so copy can cross card edges without a card's overflow clipping
        it.
      - The active card never sits above the title. z-order is fan, scrim, title,
        controls -- so a card cannot occlude the copy.
      - The backdrop is the active photo, blurred and darkened, and it transitions
        with the slide rather than being one static image.
      - Cards are clipped at the bottom of the stage. No card ever shows a bottom
        edge, which is what makes them read as posters on a stage rather than
        images in a list.
      - Real controls: chevrons, keyboard, dots, focus rings, >=44px targets, and
        a reduced-motion path that never hard-cuts.

    THE GEOMETRY LIVES IN app.css, not here. See `.tm-stage-slot` and the
    `[data-stage-offset]` rules. It was inline `style` attributes first and that
    was worse in two specific ways: the per-side rotation sign has to be computed
    per slot, and inline transform strings fight the Tailwind scale/translate
    utilities on the same element. Keying off `data-stage-offset` keeps the markup
    declarative and lets `the_stage_carousel_has_five_slots` assert the hook
    exists.

    WHAT WAS DROPPED, deliberately:

      - The ~400ms full-white flash between slides. It needs the route to swap
        without a page load, which this app does not do -- every destination is
        server-rendered and the controls are ordinary links. Chasing it means
        fetching and swapping a fragment by hand, leaving two code paths rendering
        this same view and a half-swapped page whenever the fetch failed. The
        trade is the flash for a page that cannot break. There is a short
        light-wash on load instead.
      - Auto-advance. It fights the page's own scroll, and it moves the backdrop
        of a page someone may be reading. This is a detail page that scrolls; the
        carousel should wait to be asked.
      - The nav bar and CTA, which belong to a homepage rather than here. This
        stage sits inside the existing layout, which already has the nav.

    @param  \App\Models\Destination  $destination
--}}

@php
    /*
     * The photos that fill the fan: the hero first, then the admin-uploaded extras
     * in their stored order.
     *
     * A single image cannot make a carousel, so with one photo this renders the
     * hero alone -- the stage still works, it is just not a carousel. That mirrors
     * the old rule that one extra photo is not a carousel, and it means a
     * destination with no uploads gets the full hero treatment rather than an
     * empty frame.
     */
    $stagePhotos = collect([$destination->image_url])
        ->filter()
        ->merge($destination->images->pluck('path')->filter())
        ->unique()
        ->values();

    $photoCount = $stagePhotos->count();
    $slotCount = 5;
@endphp

<section
    data-destination-stage
    data-stage-photo-count="{{ $photoCount }}"
    aria-roledescription="carousel"
    aria-label="Photos of {{ $destination->name }}"
    @class([
        'relative isolate overflow-hidden bg-volcanic-teal',
        // With no photo at all there is no fan to give the stage its height, so
        // the title needs one of its own. Without this the h1 sits over an empty
        // band with nothing behind it.
        'min-h-[26rem]' => $photoCount === 0,
    ])
>
    {{-- The ambient backdrop. Every photo gets a layer, and only the active one
         is unhidden, so the wash transitions with the slide. `hidden` rather than
         opacity 0, so the inactive ones are genuinely out of the a11y tree and
         out of the compositor. --}}
    @foreach ($stagePhotos as $photo)
        <img
            data-stage-backdrop
            src="{{ $photo }}"
            alt=""
            aria-hidden="true"
            @if (! $loop->first) hidden @endif
            class="absolute inset-0 h-full w-full scale-110 object-cover opacity-40 blur-3xl"
        >
    @endforeach

    {{-- The stand-in for a destination with no photo at all. Same palette the
         old hero used, so a photoless row still reads as the brand rather than as
         a broken panel. --}}
    @if ($photoCount === 0)
        <div aria-hidden="true" class="absolute inset-0 bg-gradient-to-br from-boracay via-cyan-500 to-volcanic-teal"></div>
    @endif

    <div class="absolute inset-0 bg-volcanic-teal/70"></div>

    {{-- The fan. Perspective lives here so all five cards share one vanishing
         point; the cards only rotate and scale within it. Clipped at the bottom by
         the stage, so no card shows a bottom edge.

         Skipped entirely when there is no photo. Guarding the LOOP, not just the
         modulo, and that is not belt-and-braces: `($slot + 2) % 0` is a fatal
         DivisionByZero in PHP 8, and a destination with neither a hero nor an
         upload is an ordinary row rather than an edge case. --}}
    @if ($photoCount > 0)
        <div
            data-stage-fan
            style="perspective: 1200px"
            class="relative flex items-center justify-center gap-2 overflow-hidden px-3 pt-14 sm:gap-4 sm:pt-16 lg:min-h-[34rem] lg:gap-6 lg:pt-20"
        >
        @for ($slot = -2; $slot <= 2; $slot++)
            @php
                /*
                 * Which photo this slot shows.
                 *
                 * The reference fans across neighbouring DESTINATIONS, each slot
                 * drawing from its own record. Rejected here: this fan is a photo
                 * viewer for ONE place, so a slot repeats this destination's photos
                 * when there are fewer than five. A fan with two empty slots reads
                 * as broken, and padding it with other places' photos would be
                 * worse -- a traveller would see a different destination's picture
                 * under this one's title.
                 *
                 * The modulo means two photos alternate A, B, A, B around the
                 * centre. Duplicates stay legible because the off-centre cards are
                 * rotated, scaled, dimmed and pushed down, so the eye reads five
                 * distinct planes before it reads content.
                 */
                $offset = abs($slot);
                $isActive = $slot === 0;
                $photoIndex = ($slot + 2) % $photoCount;
            @endphp

            <div
                data-stage-slot
                data-stage-offset="{{ $offset }}"
                data-stage-side="{{ $slot < 0 ? 'left' : 'right' }}"
                data-stage-photo="{{ $photoIndex }}"
                @class([
                    'tm-stage-slot relative shrink-0',
                    'z-30' => $isActive,
                    'z-20 opacity-70 saturate-[0.7]' => ! $isActive,
                ])
            >
                {{-- aspect-[2/5] on EVERY card at every size. This is the
                     reference's "uniform aspect ratio confirms it is not a scale
                     distortion" note: the far cards are smaller because they are
                     further away, not because they were squashed. --}}
                <div class="relative aspect-[2/5] overflow-hidden rounded-[1.25rem] shadow-2xl ring-1 ring-white/15 lg:rounded-[1.5rem]">
                    <img
                        src="{{ $stagePhotos[$photoIndex] }}"
                        alt=""
                        class="h-full w-full object-cover"
                        loading="{{ $isActive ? 'eager' : 'lazy' }}"
                    >

                    {{-- The scrim, a sibling of the <img> rather than a
                         modifier on it, so it covers the rounded corners and the
                         card reads as one plate. --}}
                    @unless ($isActive)
                        <div class="pointer-events-none absolute inset-0 bg-volcanic-teal/55"></div>
                    @endunless

                    @if ($isActive && $photoCount > 1)
                        <span class="absolute left-1/2 top-3 -translate-x-1/2 whitespace-nowrap rounded-full bg-volcanic-teal/75 px-3 py-1 text-[0.6rem] font-bold uppercase tracking-[0.2em] text-boracay-light backdrop-blur">
                            Photo 1 of {{ $photoCount }}
                        </span>
                    @endif
                </div>
            </div>
        @endfor
        </div>
    @endif

    {{-- Chevrons, vertically on the title's midline at the stage edges. Real
         buttons with accessible names, so a screen reader announces an action
         rather than a glyph.

         Gated on two photos or more, for the same reason the dots are: a chevron
         that cannot change anything is a dead control, and stage.js would leave it
         rendered-but-inert rather than absent. One photo is not a carousel. --}}
    @if ($photoCount > 1)
        <button
            type="button"
            data-stage-prev
            aria-label="Previous photo"
            class="absolute left-1 top-1/2 z-40 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-volcanic-teal/70 text-2xl leading-none text-white/80 backdrop-blur transition hover:bg-volcanic-teal hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-philippine-gold disabled:cursor-not-allowed disabled:opacity-30 sm:left-4"
        >
            <span aria-hidden="true">&lsaquo;</span>
        </button>

        <button
            type="button"
            data-stage-next
            aria-label="Next photo"
            class="absolute right-1 top-1/2 z-40 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-volcanic-teal/70 text-2xl leading-none text-white/80 backdrop-blur transition hover:bg-volcanic-teal hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-philippine-gold disabled:cursor-not-allowed disabled:opacity-30 sm:right-4"
        >
            <span aria-hidden="true">&rsaquo;</span>
        </button>
    @endif

    {{-- The title block. A STAGE-LEVEL overlay, deliberately not a child of any
         card. z-40 against the fan's z-30 is what guarantees a card can never
         occlude the copy. `pointer-events-none` so the cards stay hoverable
         through the text area. --}}
    <div class="pointer-events-none absolute inset-x-0 top-1/2 z-40 -translate-y-1/2 px-6 text-center">
        <p class="text-xs font-bold uppercase tracking-[0.3em] text-boracay-light">
            {{ $destination->municipality }},
            {{ $destination->province }}
        </p>

        <h1 class="mt-4 font-display text-4xl font-semibold uppercase leading-[0.92] tracking-[-0.03em] text-white sm:text-6xl lg:text-7xl xl:text-8xl">
            {{ $destination->name }}
        </h1>

        <p class="mx-auto mt-5 max-w-xl text-sm leading-6 text-white/70 sm:text-base sm:leading-7">
            {{ \Illuminate\Support\Str::limit($destination->description, 130) }}
        </p>

        <div class="mt-5 flex flex-wrap justify-center gap-2">
            @foreach ($destination->tags as $tag)
                <span class="rounded-full bg-white/15 px-3 py-1 text-xs font-semibold text-white backdrop-blur">
                    {{ $tag->name }}
                </span>
            @endforeach
        </div>
    </div>

    {{-- One dot per photo. The reference showed a single lit dot and could not
         resolve whether the inactive ones were hidden; a lit/inactive pair per
         photo is the honest reading of "you are on photo n of m". --}}
    @if ($photoCount > 1)
        <div class="relative z-40 flex justify-center gap-2 pb-8 pt-4" role="group" aria-label="Choose a photo">
            @foreach ($stagePhotos as $index => $photo)
                <button
                    type="button"
                    data-stage-dot="{{ $index }}"
                    aria-label="Photo {{ $index + 1 }}"
                    aria-current="{{ $index === 0 ? 'true' : 'false' }}"
                    @class([
                        'h-2 w-2 rounded-full transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-philippine-gold',
                        'bg-white' => $index === 0,
                        'bg-white/30 hover:bg-white/60' => $index !== 0,
                    ])
                ></button>
            @endforeach
        </div>
    @endif

    {{-- The entrance wash. The reference flashes white between slides; without a
         client-side route swap there is nothing to mask, so this is a short lift
         on load instead. `animation-fill-mode: forwards` plus the reduced-motion
         override in app.css means it never leaves the title stuck at opacity 0. --}}
    <div data-stage-wash aria-hidden="true" class="pointer-events-none absolute inset-0 z-30 bg-white"></div>

    <p data-stage-status class="sr-only" role="status" aria-live="polite"></p>
</section>
