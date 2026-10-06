{{--
    The destinations hero on a destination page: the fan, the page's heading, and
    the controls.

    Shown on a destination page only. `/destinations` is a search-and-grid listing
    again and does not use this.

    THE FAN DOES NOT MOVE BECAUSE OF PHP. Read the offset note in app.css first.
    `data-offset` is signed and relative to the active panel, and every geometric
    property is derived from it in CSS. The server renders each panel's INITIAL
    offset so the page is right with JavaScript disabled; the script then keeps
    rewriting that attribute as the active index moves, and the CSS transition
    interpolates. If you ever set an offset from PHP expecting it to survive an
    advance, it will not.

    THE HEADING BELONGS TO THE PAGE, NOT THE CAROUSEL, and it is rendered here for
    POSITION rather than for ownership -- it is fed `$destination` directly, never
    a slide.

    That distinction is load-bearing twice over. It used to be derived from the
    active slide, which put a different destination's name above prose, hours and
    fees that were all still about the first one, and moved the document heading out
    from under the reader. Worse, because it lived only here, a destination with no
    featured destinations rendered a page with NO `h1` AT ALL -- the section was
    skipped as empty and took the heading with it. Rendering it unconditionally and
    from the destination fixes both.

    Three further things this no longer does, all deliberate:

    It renders no backdrop. It used to put a blurred layer of every slide behind
    the fan. The page now paints the destination's own photo as a whole-page
    background behind everything, so a second blurred layer would double-darken the
    hero and fight it for the same job.

    It does not autoplay, and has no pause control. This is a page someone has
    arrived at; the copy beneath is all about one place, and a timer that keeps
    shifting the fan under someone reading it is worse than no timer. With nothing
    moving by itself, WCAG 2.2.2 does not apply and a pause button would be a dead
    control.

    @param  \App\Models\Destination  $destination
    @param  \App\Domain\Destinations\CarouselSlide[]  $slides
    @param  int  $activeIndex
    @param  array{prev: ?\App\Domain\Destinations\CarouselSlide, next: ?\App\Domain\Destinations\CarouselSlide}  $neighbours
--}}

@php
    $count = count($slides);
    $reach = \App\Domain\Destinations\DestinationCarousel::REACH;
@endphp

<section
    data-destination-stage
    data-stage-count="{{ $count }}"
    data-stage-active="{{ $activeIndex }}"
    @if ($count > 0)
        aria-roledescription="carousel"
    @endif
    aria-label="{{ $destination->name }}"
    @if ($count > 0)
        x-data="heroCarousel()"
        @keydown.arrow-left.prevent="prev()"
        @keydown.arrow-right.prevent="next()"
        @keydown.home.prevent="go(0)"
        @keydown.end.prevent="go(count - 1)"
    @endif
    class="relative"
>
    {{--
        The fan and the heading share one relatively-positioned wrapper, because the
        heading is centred on the FAN. Centring it on the section would drop it
        below the cards, because the pager sits under them.
    --}}
    <div class="relative">
        @if ($count > 0)
            {{-- `list` semantics: it is an ordered set of destinations. --}}
            <ul data-stage-fan class="tm-fan list-none">
                @foreach ($slides as $index => $slide)
                    @include('components.destinations.carousel-panel', [
                        'slide' => $slide,
                        'index' => $index,
                        'count' => $count,
                        'reach' => $reach,
                    ])
                @endforeach
            </ul>
        @else
            {{--
                No featured destinations. The fan is skipped and the heading sits
                alone over the photograph -- which is the whole point of feeding it
                from `$destination`. An earlier version skipped the whole section,
                so this page rendered with no `h1` at all.
            --}}
            <div class="flex min-h-[16rem] items-center justify-center px-6 py-16">
                <div class="max-w-3xl text-center">
                    <p class="text-xs font-bold uppercase tracking-[0.32em] text-boracay-light">
                        {{ trim($destination->municipality.', '.$destination->province, ', ') }}
                    </p>

                    <h1 class="mt-3 font-display text-[clamp(2rem,5.5vw,4rem)] font-semibold uppercase leading-[1.02] tracking-[-0.02em] text-white drop-shadow-[0_2px_18px_rgba(11,37,43,0.75)]">
                        {{ $destination->name }}
                    </h1>

                    <span aria-hidden="true" class="mx-auto mt-5 block h-0.5 w-10 bg-white/60"></span>
                </div>
            </div>
        @endif

        {{--
            The heading, over the fan. Stage-level rather than a child of a panel,
            so a long name is never clipped by a panel's `overflow`, and z-40
            against the fan's z-30 guarantees no panel can be painted over it.
            `pointer-events-none` keeps the panels clickable through the text.

            SIZE IS LOAD-BEARING. An earlier version was `lg:text-7xl xl:text-8xl`
            with no width constraint, which put an ~90px headline across the page and
            read as a banner laid over the photographs rather than a caption on them.
        --}}
        @if ($count > 0)
            <div class="pointer-events-none absolute inset-0 z-40 flex items-center justify-center px-6 text-center">
                <div class="max-w-3xl">
                    <p class="text-xs font-bold uppercase tracking-[0.32em] text-boracay-light">
                        {{ trim($destination->municipality.', '.$destination->province, ', ') }}
                    </p>

                    <h1
                        data-stage-title
                        class="mt-3 font-display text-[clamp(2rem,5.5vw,4rem)] font-semibold uppercase leading-[1.02] tracking-[-0.02em] text-white drop-shadow-[0_2px_18px_rgba(11,37,43,0.75)]"
                    >{{ $destination->name }}</h1>

                    {{-- The reference's short accent rule, flush-left with the text
                         block rather than centred under it. --}}
                    <span aria-hidden="true" class="mt-5 block h-0.5 w-10 bg-white/60"></span>

                    <p class="mt-5 text-[0.7rem] uppercase tracking-[0.2em] text-white/55">
                        {{ $activeIndex + 1 }} / {{ $count }}
                    </p>
                </div>
            </div>
        @endif
    </div>

    @if ($count > 1)
        @include('components.destinations.carousel-chevrons', [
            'neighbours' => $neighbours,
        ])

        @include('components.destinations.carousel-pager', [
            'slides' => $slides,
            'activeIndex' => $activeIndex,
        ])
    @endif

    <p
        data-stage-status
        class="sr-only"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        x-text="announcement"
    ></p>
</section>