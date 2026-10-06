{{--
    The stage chevrons.

    LINKS, not buttons. They navigate to the neighbouring destination's page, which
    is why they are anchors: they are destinations, not slide controls. That also
    means they work with JavaScript disabled, are middle-clickable and openable in
    a new tab, and the browser shows the destination in the status bar -- none of
    which is true of a button.

    The in-page advance lives on the arrow KEYS and on the dots; the chevrons are
    the "go there" affordance.

    OMITTED at each end rather than disabled. There is no wrap-around, so at the
    first destination there is no previous one, and a permanently disabled control
    is a dead control -- better to not render it. That also keeps the caption clear
    of an arrow at the edges.

    BARE, not a filled circle: the reference draws plain arrows with no plate behind
    them. An earlier version had `rounded-full bg-volcanic-teal/70 backdrop-blur`,
    which put two dark discs on the fan competing with the photographs. The 44px hit
    area is a transparent box around the glyph.

    @param  array{prev: ?\App\Domain\Destinations\CarouselSlide, next: ?\App\Domain\Destinations\CarouselSlide}  $neighbours
--}}

@if ($neighbours['prev'] || $neighbours['next'])
    <div class="absolute inset-0 z-40" aria-hidden="false">
        @if ($neighbours['prev'])
            <a
                href="{{ $neighbours['prev']->url }}"
                rel="prev"
                aria-label="Previous destination: {{ $neighbours['prev']->name }}"
                data-stage-chevron="prev"
                class="absolute left-0 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center text-3xl leading-none text-white/70 transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-philippine-gold"
            >
                <span aria-hidden="true">&lsaquo;</span>
            </a>
        @endif

        @if ($neighbours['next'])
            <a
                href="{{ $neighbours['next']->url }}"
                rel="next"
                aria-label="Next destination: {{ $neighbours['next']->name }}"
                data-stage-chevron="next"
                class="absolute right-0 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center text-3xl leading-none text-white/70 transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-philippine-gold"
            >
                <span aria-hidden="true">&rsaquo;</span>
            </a>
        @endif
    </div>
@endif