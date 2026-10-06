{{--
    The destinations stage: one fanned carousel over photographs.

    THE STAGE. The opening of the destination page, and nothing else.

    ONE ROUTE ONLY: `/destinations/{slug}`. It is deliberately NOT on
    `/destinations`. The stage opens on the destination you have ARRIVED at, with
    that destination's own photographs in the fan, so putting it on the listing
    would march 65 destinations' photographs past above a grid of 9 of the same
    destinations -- and its chevrons navigate away from the one page whose whole
    purpose is to let you choose between them. There is no `?slide=` parameter
    either, because there is no second route to centre.

    A DARK SLAB ON A DARK GROUND, not a dark slab on paper. The destination page
    is one continuous Deep Volcanic Teal surface from the nav bar down and this
    sits on it, so every colour here is a light value on teal: Island White and
    Boracay Light for text, gold as an accent (8.7:1 here, against 1.8:1 on the
    sand surface this replaced). The slab separates from its own ground by depth
    -- its blurred photographic backdrop, its radial vignette and its shadow --
    rather than by hue.

    THE FAN DOES NOT MOVE BECAUSE OF PHP. Read the offset note in app.css first.
    `data-offset` is signed and relative to the active panel, and every geometric
    property is derived from it in CSS. The server renders each photograph's
    INITIAL offset so the page is correct with JavaScript disabled; Alpine then
    rewrites the attribute as the active index moves, and the CSS transition
    interpolates.

    THE PAYLOAD IS A JSON ISLAND, not a pile of `data-*` attributes. A caption
    needs eleven fields per photograph, and a destination set is up to 65
    destinations of four photographs each -- eleven attributes across 260 elements
    is 2,860 attributes to render and parse for data the script only needs when
    the fan arrives somewhere. One island, read once.

    @json, not a hand-written json_encode: it applies JSON_HEX_TAG, which is what
    keeps a destination name containing `</script>` from closing this element.

    @param  \App\Domain\Destinations\CarouselSlide[]  $stageSlides
    @param  int  $stageActiveIndex
    @param  array  $stageNeighbours
    @param  array<int, float>  $stageMatchScores
    @param  int  $stageInterval
--}}

@php
    $count = count($stageSlides);
    $reach = \App\Domain\Destinations\DestinationCarousel::REACH;

    /*
     * `(object)` on the scores so an empty map serialises as `{}` rather than `[]`.
     * As an array, `scores[id]` is undefined either way -- but `{}` is also what
     * the shape check in the script expects, and one representation is easier to
     * reason about than two.
     */
    $stagePayload = [
        'slides' => $stageSlides->map(fn ($slide) => $slide->toArray())->all(),
        'matchScores' => (object) $stageMatchScores,
    ];
@endphp

<div class="tm-stage-wrap">
    @if ($count === 0)
        {{--
            No destinations to show. An honest empty state rather than an empty
            arc -- five panels of nothing is a broken-looking stage, and the page
            heading above this is rendered either way, so the page still has its
            h1 when the stage has no photographs.
        --}}
        <section
            data-stage
            aria-label="Destinations"
            class="tm-stage"
        >
            <p class="tm-stage__empty">Nothing to show yet.</p>
        </section>
    @else
        <section
            data-stage
            data-stage-count="{{ $count }}"
            data-stage-active="{{ $stageActiveIndex }}"
            data-stage-interval="{{ $stageInterval }}"
            data-stage-reach="{{ $reach }}"
            aria-roledescription="carousel"
            aria-label="Photographs of Luzon destinations"
            x-data="destinationStage"
            @keydown.arrow-left.prevent="prev()"
            @keydown.arrow-right.prevent="next()"
            @keydown.home.prevent="go(0)"
            @keydown.end.prevent="go(count - 1)"
            @mouseenter="hold('hover')"
            @mouseleave="release('hover')"
            @focusin="hold('focus')"
            @focusout="release('focus')"
            class="tm-stage"
        >
            {{--
                THE BACKDROP. Two layers, because a cross-fade between two
                photographs needs two photographs: a single <img> whose `src` is
                swapped can only be replaced, not cross-faded, and the vision is a
                cross-fade of 700-900ms.

                Both are empty server-side except the opening one. The script fills
                the incoming layer and fades it in, so a page with 260 photographs
                in the stage loads at most two of them at full size -- a backdrop
                per slide would be 260 full-bleed images, which is the single
                heaviest thing this design could do and the easiest to miss.

                `aria-hidden` and empty `alt`: it is a blurred wash behind the
                panels and carries no information the caption does not.
            --}}
            <div data-stage-backdrops aria-hidden="true" class="tm-stage__backdrops">
                <img data-stage-backdrop class="tm-stage__backdrop is-shown" src="{{ $stageSlides[$stageActiveIndex]->imageUrl }}" alt="" decoding="async" fetchpriority="low">
                <img data-stage-backdrop class="tm-stage__backdrop" alt="" decoding="async">
            </div>

            {{--
                The radial vignette: about a 15% lift at the centre, falling away
                to darker at the edges. It is what stops the slab reading as a flat
                rectangle of teal and gives the panels somewhere to sit.
            --}}
            <div aria-hidden="true" class="tm-stage__vignette"></div>

            {{-- The fan. `list` semantics: an ordered set of photographs. --}}
            <ul data-stage-fan class="tm-fan">
                @foreach ($stageSlides as $index => $slide)
                    @include('components.destinations.stage-panel', [
                        'slide' => $slide,
                        'index' => $index,
                        'reach' => $reach,
                        'inPreloadWindow' => abs($slide->offset) <= $reach + 1,
                        'isCurrentPage' => $slide->slug === request()->route('destination')?->slug,
                    ])
                @endforeach
            </ul>

            {{-- The caption. Stage-level, never a child of a panel. --}}
            @include('components.destinations.stage-caption')

            {{-- The chevrons, pinned to the stage's own edges. --}}
            @include('components.destinations.stage-chevrons', ['stageNeighbours' => $stageNeighbours])

            {{--
                Dots plus the mandated pause control.

                INSIDE the stage slab, not below it on the sand. The dots are Island
                White and Island White on Palawan Sand is invisible -- so a pager on
                the paper surface cannot be the one the composition asks for, and the
                slab simply extends to include the control strip. That is also the
                ordinary editorial arrangement: the controls belong to the stage.
            --}}
            @include('components.destinations.stage-pager', [
                'stageSlides' => $stageSlides,
                'stageActiveIndex' => $stageActiveIndex,
                'stageInterval' => $stageInterval,
                'reach' => $reach,
            ])

            {{--
                The live region. The panels carry NO alt text, deliberately (see
                stage-panel-inner), so this is the only thing a screen reader hears
                on a slide change and it has to be complete on its own.
            --}}
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

    {{-- Read once by the script. Outside the empty branch: no stage, no data. --}}
    @if ($count > 0)
        <script type="application/json" data-stage-data>@json($stagePayload)</script>
    @endif
</div>
