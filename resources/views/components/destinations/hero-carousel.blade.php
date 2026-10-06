{{--
    The destinations stage: one shared fanned carousel.

    THE SPINE. Rendered by `/destinations` and by every `/destinations/{slug}`,
    from the same presenter and the same order, differing only in which slide is
    active. That is the entire continuity feature: the fan on a destination page
    IS the index fan with the index shifted, so the two pages cannot drift.

    THE FAN DOES NOT MOVE BECAUSE OF PHP. Read the offset note in app.css first.
    `data-offset` is signed and relative to the active panel, and every geometric
    property is derived from it in CSS. The server renders each slide's INITIAL
    offset so the page is correct with JavaScript disabled; Alpine then keeps
    rewriting that attribute as the index moves, and the CSS transition
    interpolates. If you ever set an offset from PHP expecting it to survive an
    advance, it will not.

    @param  \App\Domain\Destinations\CarouselSlide[]  $slides
    @param  int  $activeIndex
    @param  array{prev: ?\App\Domain\Destinations\CarouselSlide, next: ?\App\Domain\Destinations\CarouselSlide}  $neighbours
    @param  bool  $autoplay
    @param  int  $interval
    @param  string  $headingTag
--}}

@php
    $count = count($slides);
    $headingTag = $headingTag ?? 'h1';

    /*
     * Autoplay is OFF on a destination page.
     *
     * On the index it is a browse surface: the visitor is looking through
     * destinations and a slow advance is a suggestion to keep looking. On a
     * destination page they have arrived somewhere, and a timer that keeps
     * swapping the backdrop out from under someone reading the description is
     * worse than no timer. It also fights the page's own scroll.
     *
     * Where autoplay DOES run, the pause control is always present, because a
     * loop longer than five seconds without one fails WCAG 2.2.2.
     */
    $label = $activeIndex + 1;
@endphp

{{-- No carousel at all, and not an empty arc either. --}}
@if ($count === 0)
    <section
        data-destination-stage
        aria-label="Destinations"
        class="relative isolate overflow-hidden bg-volcanic-teal"
    >
        <div class="px-6 py-20 text-center">
            <h1 class="font-display text-3xl font-semibold text-white">
                No destinations to show yet
            </h1>

            <p class="mx-auto mt-3 max-w-md text-sm text-white/70">
                Nothing has been published, so there is nothing to browse. Everything
                appears here as soon as a destination is published.
            </p>
        </div>
    </section>
@else
    <section
        data-destination-stage
        data-stage-count="{{ $count }}"
        data-stage-active="{{ $activeIndex }}"
        data-stage-interval="{{ $interval }}"
        data-stage-autoplay="{{ $autoplay ? '1' : '0' }}"
        aria-roledescription="carousel"
        aria-label="Destinations"
        x-data="heroCarousel()"
        @keydown.arrow-left.prevent="prev()"
        @keydown.arrow-right.prevent="next()"
        @keydown.home.prevent="go(0)"
        @keydown.end.prevent="go(count - 1)"
        @mouseenter="pause('hover')"
        @mouseleave="resume('hover')"
        @focusin="pause('focus')"
        @focusout="resume('focus')"
        class="relative isolate overflow-hidden bg-volcanic-teal"
    >
        {{--
            The ambient backdrop. One layer per slide, and the active one is the
            only one unhidden, so the wash tracks the fan.

            Scaled past the edges because `blur-3xl` samples outside the element box
            and a 1:1 image leaves a transparent rim, which showed as a hard edge
            inside the stage.
        --}}
        @foreach ($slides as $slide)
            @if ($slide->hasImage())
                <img
                    data-stage-backdrop
                    src="{{ $slide->imageUrl }}"
                    alt=""
                    aria-hidden="true"
                    @if (! $slide->isActive) hidden @endif
                    class="absolute inset-0 h-full w-full scale-125 object-cover opacity-70 blur-3xl"
                >
            @endif
        @endforeach

        @if ($activeSlideHasNoImage = ! collect($slides)->firstWhere('isActive', true)?->hasImage())
            <div aria-hidden="true" class="absolute inset-0 bg-gradient-to-br from-boracay via-cyan-500 to-volcanic-teal"></div>
        @endif

        {{--
            A GRADIENT scrim, not a flat fill. A flat `bg-volcanic-teal/70` across
            the stage flattened the backdrop to near-solid dark teal and threw away
            the blurred photograph, which is most of what makes the stage feel
            photographic rather than like a coloured box.
        --}}
        <div aria-hidden="true" class="absolute inset-0 bg-gradient-to-b from-volcanic-teal/75 via-volcanic-teal/55 to-volcanic-teal/85"></div>

        {{-- The fan. `list` semantics: it is an ordered set of destinations. --}}
        <ul
            data-stage-fan
            class="tm-fan mt-16 list-none sm:mt-20 lg:mt-24"
        >
            @foreach ($slides as $index => $slide)
                @include('components.destinations.carousel-panel', [
                    'slide' => $slide,
                    'index' => $index,
                    'count' => $count,
                    'reach' => \App\Domain\Destinations\DestinationCarousel::REACH,
                ])
            @endforeach
        </ul>

        {{-- Chevrons. Links, because they navigate to a real destination. --}}
        @include('components.destinations.carousel-chevrons', [
            'neighbours' => $neighbours,
        ])

        {{-- The caption. Stage-level, never a child of a panel. --}}
        @include('components.destinations.carousel-caption', [
            'activeSlide' => $slides[$activeIndex],
            'activeIndex' => $activeIndex,
            'count' => $count,
            'headingTag' => $headingTag,
        ])

        {{-- Dots plus the mandated pause control. --}}
        @include('components.destinations.carousel-pager', [
            'slides' => $slides,
            'activeIndex' => $activeIndex,
            'autoplay' => $autoplay,
        ])

        {{-- The entrance wash. --}}
        <div data-wash aria-hidden="true" class="pointer-events-none absolute inset-0 z-30 bg-white"></div>

        <p
            data-stage-status
            class="sr-only"
            role="status"
            aria-live="polite"
            aria-atomic="true"
            x-text="announcement"
        ></p>
    </section>
@endif