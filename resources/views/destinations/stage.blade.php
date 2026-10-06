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
        // the stage needs one of its own. Without this the h1 sits over an empty
        // band with nothing behind it.
        'min-h-[24rem]' => $photoCount === 0,
    ])
>
    {{-- The ambient backdrop. Every photo gets a layer, and only the active one
         is unhidden, so the wash transitions with the slide. `hidden` rather than
         opacity 0, so the inactive ones are genuinely out of the a11y tree and
         out of the compositor.

         Scaled up 125% because `blur-3xl` samples past the element edge and a
         1:1 image leaves a transparent rim -- which showed as a hard edge inside
         the stage. --}}
    @foreach ($stagePhotos as $photo)
        <img
            data-stage-backdrop
            src="{{ $photo }}"
            alt=""
            aria-hidden="true"
            @if (! $loop->first) hidden @endif
            class="absolute inset-0 h-full w-full scale-125 object-cover opacity-70 blur-3xl"
        >
    @endforeach

    {{-- The stand-in for a destination with no photo at all. Same palette the
         old hero used, so a photoless row still reads as the brand rather than as
         a broken panel. --}}
    @if ($photoCount === 0)
        <div aria-hidden="true" class="absolute inset-0 bg-gradient-to-br from-boracay via-cyan-500 to-volcanic-teal"></div>
    @endif

    {{--
        The scrim over the backdrop. A GRADIENT, not a flat fill.

        This was `bg-volcanic-teal/70` across the whole stage, which flattened
        the backdrop to near-solid dark teal and threw away the blurred photo
        entirely -- the reference's backdrop is a legible, heavily blurred image of
        the place, and it is most of what makes the stage feel photographic
        rather than like a coloured box.

        The gradient darkens the middle band where the title sits and lightens
        toward the top and bottom, so the copy stays legible over arbitrary
        photography without dimming the whole frame to nothing.
    --}}
    <div aria-hidden="true" class="absolute inset-0 bg-gradient-to-b from-volcanic-teal/75 via-volcanic-teal/55 to-volcanic-teal/85"></div>

    {{-- The fan. Perspective lives here so all five cards share one vanishing
         point; the cards only rotate within it.

         Skipped entirely when there is no photo. Guarding the LOOP, not just the
         modulo, and that is not belt-and-braces: `($slot + 2) % 0` is a fatal
         DivisionByZero in PHP 8, and a destination with neither a hero nor an
         upload is an ordinary row rather than an edge case. --}}
    {{--
        The fan and the title share one relatively-positioned wrapper, because the
        title is centred on the FAN, not on the stage. The stage itself is a flex
        column so the dots sit under the fan rather than at the bottom of whatever
        height the cards happened to take.

        `min-h` on this inner wrapper is what a destination with no photo relies on
        to give the title somewhere to sit -- with no fan it has no height of its
        own, and the h1 would centre on a zero-height box at the very top.
    --}}
    <div
        class="relative flex flex-col pt-16 sm:pt-20 lg:pt-24"
        @class(['min-h-[16rem]' => $photoCount === 0])
    >
    <div class="relative">
    @if ($photoCount > 0)
        <div data-stage-fan class="tm-stage-fan">
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
                 * shorter, rotated and dimmed, so the eye reads five distinct
                 * planes before it reads content.
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
                    'tm-stage-slot',
                    'z-30' => $isActive,
                    // A LIGHT dim only. This was `bg-volcanic-teal/55` over the
                    // image plus `opacity-70 saturate-[0.7]` on the slot, and the
                    // reference shows the outer cards at close to full strength --
                    // they are separated by size, rotation and drop, not by being
                    // blacked out. Dimming them that hard made the fan read as one
                    // photo with shadows.
                    'z-20 opacity-90' => ! $isActive,
                ])
            >
                {{--
                    aspect-[3/5], NOT 2/5. Measured off the reference capture: the
                    centre card is 135x230px, which is 1:1.7. The 2/5 shipped
                    first made every card 1:2.4 -- so tall the fan stopped reading as
                    a row of photos and started reading as five vertical slivers.

                    One ratio on all five, so the fan is perspective rather than a
                    scale distortion: the outer cards are smaller because they are
                    further away, not because they were squashed.
                --}}
                <div class="relative h-full aspect-[3/5] overflow-hidden rounded-lg shadow-xl ring-1 ring-white/15 lg:rounded-xl">
                    <img
                        src="{{ $stagePhotos[$photoIndex] }}"
                        alt=""
                        class="h-full w-full object-cover"
                        loading="{{ $isActive ? 'eager' : 'lazy' }}"
                    >

                    {{-- The scrim, a sibling of the <img> rather than a modifier on
                         it, so it covers the rounded corners and the card reads as
                         one plate. Lighter than the old 55% -- see above. --}}
                    @unless ($isActive)
                        <div class="pointer-events-none absolute inset-0 bg-volcanic-teal/25"></div>
                    @endunless

                    {{--
                        The active card's chip. The reference labels the active card
                        with its REGION ("Central America"), not with its position in
                        the sequence -- it was previously "Photo 1 of 4", which is
                        what the dots below already say, and repeating it here put a
                        counter in the middle of a photograph.
                    --}}
                    @if ($isActive)
                        <span class="absolute left-1/2 top-3 -translate-x-1/2 whitespace-nowrap rounded-full bg-volcanic-teal/70 px-3 py-1 text-[0.6rem] font-bold uppercase tracking-[0.2em] text-boracay-light backdrop-blur">
                            {{ $destination->province }}
                        </span>
                    @endif
                </div>
            </div>
        @endfor
        </div>
    @endif

    {{--
        The title block. A STAGE-LEVEL overlay, deliberately not a child of any
        card, so copy can cross card edges without a card's overflow clipping it.
        z-40 against the fan's z-30 guarantees a card can never occlude it.
        `pointer-events-none` so the cards stay hoverable through the text area.

        SIZE IS THE WHOLE FIX HERE. This was `text-4xl sm:text-6xl lg:text-7xl
        xl:text-8xl` with no width constraint, which put an ~90px headline across
        a 1500px stage -- it read as a page-wide banner laid over the fan rather
        than as a caption on the photograph, and it was the single loudest thing
        wrong with the layout. The reference's title is about 5% of the stage
        height and ~33% of its width: small, and constrained.

        Measured off the reference: cap height 20px in a 409px stage, so ~21px of
        cap in our 26rem fan. Playfair's cap runs around 0.7 of its font size, so
        that is roughly a 30px font -- `text-3xl`, not `text-8xl`. The clamp keeps
        it proportional between the 16rem mobile fan and the 26rem desktop one.

        `max-w-3xl` is the other half. Without it a long name wraps across the full
        stage width and the effect is lost even at a smaller font size.
    --}}
    <div class="pointer-events-none absolute inset-0 z-40 flex items-center justify-center px-6 text-center">
        <div class="max-w-3xl">
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.32em] text-boracay-light sm:text-xs">
                {{ $destination->municipality }},
                {{ $destination->province }}
            </p>

            <h1 class="mt-3 font-display text-[clamp(1.75rem,4.2vw,3rem)] font-semibold uppercase leading-[1.05] tracking-[-0.02em] text-white">
                {{ $destination->name }}
            </h1>

            {{-- The reference's short accent rule, flush-left with the text block
                 rather than centred under it. Centring it reads as a divider; the
                 reference uses it as a mark. --}}
            <span aria-hidden="true" class="mt-4 block h-0.5 w-8 bg-white/60"></span>

            {{--
                NO DESCRIPTION HERE, deliberately.

                The stage carried `Str::limit($destination->description, 90)` and
                section 01 below renders the same field in full, so the opening
                sentence appeared twice within one screenful -- once over the
                photograph and again a few hundred pixels down. It read as a
                mistake rather than as emphasis.

                The reference carries a short tagline, but we have no tagline field
                and inventing one means writing copy per destination that nothing
                else on the page agrees with. The description belongs once, in the
                prose block that is actually for reading.
            --}}

            <div class="mt-4 flex flex-wrap justify-center gap-2">
                @foreach ($destination->tags as $tag)
                    <span class="rounded-full bg-white/15 px-2.5 py-0.5 text-[0.7rem] font-semibold text-white backdrop-blur">
                        {{ $tag->name }}
                    </span>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Chevrons, on the fan's midline at the stage edges.

         INSIDE the fan wrapper, which is what makes `top-1/2` land on the fan's
         centre rather than the stage's. The wrapper carries the stage's top padding
         for the nav gap, so centring on the stage put the arrows visibly above the
         cards.

         BARE, not a filled circle. The reference draws plain arrows with no plate
         behind them; the earlier `rounded-full bg-volcanic-teal/70 backdrop-blur`
         put two dark discs on the fan that competed with the photographs for
         attention. They keep a 44px hit area -- a transparent padding box rather
         than a visible circle.

         Gated on two photos or more, for the same reason the dots are: a control
         that cannot change anything is a dead control. --}}
    @if ($photoCount > 1)
        <button
            type="button"
            data-stage-prev
            aria-label="Previous photo"
            class="absolute left-0 top-1/2 z-40 flex h-11 w-11 -translate-y-1/2 items-center justify-center text-3xl leading-none text-white/70 transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-philippine-gold disabled:pointer-events-none disabled:opacity-25"
        >
            <span aria-hidden="true">&lsaquo;</span>
        </button>

        <button
            type="button"
            data-stage-next
            aria-label="Next photo"
            class="absolute right-0 top-1/2 z-40 flex h-11 w-11 -translate-y-1/2 items-center justify-center text-3xl leading-none text-white/70 transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-philippine-gold disabled:pointer-events-none disabled:opacity-25"
        >
            <span aria-hidden="true">&rsaquo;</span>
        </button>
    @endif
    </div>

    {{-- One dot per photo. The reference showed a single lit dot and could not
         resolve whether the inactive ones were hidden; a lit/inactive pair per
         photo is the honest reading of "you are on photo n of m". --}}
    @if ($photoCount > 1)
        <div class="relative z-40 -mt-2 flex justify-center gap-2 pb-10" role="group" aria-label="Choose a photo">
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
    </div>

    {{-- The entrance wash. The reference flashes white between slides; without a
         client-side route swap there is nothing to mask, so this is a short lift
         on load instead. `animation-fill-mode: forwards` plus the reduced-motion
         override in app.css means it never leaves the title stuck at opacity 0.

         Deliberately a SIBLING of the flow wrapper rather than a child, so
         `inset-0` covers the whole stage -- including the area above the fan that
         the wrapper's nav padding adds -- rather than stopping short of it. --}}
    <div data-stage-wash aria-hidden="true" class="pointer-events-none absolute inset-0 z-30 bg-white"></div>

    <p data-stage-status class="sr-only" role="status" aria-live="polite"></p>
</section>
