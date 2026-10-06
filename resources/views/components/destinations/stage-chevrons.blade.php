{{--
    The stage chevrons: the "go there" controls, on the stage's own edges.

    LINKS, NOT BUTTONS. They navigate to a neighbouring DESTINATION's page, which
    is why they are anchors: they work with JavaScript disabled, they are
    middle-clickable and openable in a new tab, and the browser shows the
    destination in the status bar -- none of which is true of a button.

    NEIGHBOURS, NOT NEIGHBOURING SLIDES. From a destination with three uploaded
    photographs these skip its own remaining two and offer the next destination.
    The dots are the control for moving through photographs in place; having both
    is the point, because they answer different questions -- "take me to the next
    place" and "show me the next picture of this one".

    OMITTED AT EACH END RATHER THAN DISABLED. There is no wrap-around, so at the
    first destination there is no previous one, and a permanently disabled control
    is a dead control. Omitting it also keeps the caption clear of an arrow.

    Pinned to the stage's left and right EDGES at its vertical middle, which is
    where a thumb expects them and keeps them off the photographs. The 44px box
    is the hit area and the glyph is smaller, so the control does not crowd a
    320px panel.

    @param  array{prev: ?array{slug: string, name: string, url: string}, next: ?array{slug: string, name: string, url: string}}  $stageNeighbours
--}}

@if ($stageNeighbours['prev'] || $stageNeighbours['next'])
    <div class="tm-stage__chevrons">
        @foreach (['prev' => 'Previous', 'next' => 'Next'] as $side => $word)
            @if ($stageNeighbours[$side])
                <a
                    href="{{ $stageNeighbours[$side]['url'] }}"
                    rel="{{ $side }}"
                    data-stage-chevron="{{ $side }}"
                    aria-label="{{ $word }} destination: {{ $stageNeighbours[$side]['name'] }}"
                    class="tm-stage__chevron tm-stage__chevron--{{ $side }}"
                >
                    <svg viewBox="0 0 24 24" aria-hidden="true" class="tm-stage__chevron-glyph">
                        <path
                            d="{{ $side === 'prev' ? 'M15 5l-7 7 7 7' : 'M9 5l7 7-7 7' }}"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.75"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        ></path>
                    </svg>
                </a>
            @endif
        @endforeach
    </div>
@endif
