{{--
    One carousel panel: a destination, as a real link.

    Every panel is an anchor to that destination's page. The active panel is NOT a
    link to the page you are already on -- `href="#"` on the show page would be a
    dead control, so the active panel renders as a plain element there. The script
    reads `href` off every panel to build the announcement, so the active one keeps
    a `data-href` even when it is not clickable.

    THE OFFSET. `data-offset` is written here ONCE, from PHP, and that is correct
    and necessary: it is what makes the page correct with JavaScript disabled. It
    is also what makes the fan ANIMATE. Alpine rebinds this attribute as the active
    index moves, and the CSS rules keyed on it supply position, size, rotation,
    stacking order and opacity. The browser's transition between the old value and
    the new one is the animation.

    So the server value is the starting state, not the final one. It is NOT
    re-rendered per advance, and nothing in the view re-reads it.

    @param  \App\Domain\Destinations\CarouselSlide  $slide
    @param  int  $index
    @param  int  $count
    @param  int  $reach
--}}

@php
    $offset = $slide->offset;
    $isFar = abs($offset) > $reach;
    $isCurrent = request()->routeIs('destinations.show')
        && $slide->slug === request()->route('destination')?->slug;
@endphp

<li
    data-stage-panel
    data-offset="{{ $offset }}"
    @if ($isFar) data-far @endif
    data-href="{{ $slide->url }}"
    {{-- Read by the script to build the live-region announcement. --}}
    data-stage-name="{{ $slide->name }}"
    data-stage-region="{{ $slide->regionLabel() }}"
    x-bind:data-offset="offsetOf({{ $index }})"
    x-bind:data-far="Math.abs(offsetOf({{ $index }})) > {{ $reach }} ? '' : null"
    x-bind:aria-hidden="Math.abs(offsetOf({{ $index }})) > {{ $reach }} ? 'true' : 'false'"
    x-bind:tabindex="Math.abs(offsetOf({{ $index }})) > {{ $reach }} ? -1 : 0"
    @class([
        'tm-fan-panel',
        // The element that stands in for the link when this is the current page.
        'tm-fan-panel--current' => $isCurrent,
    ])
>
    @if ($isCurrent)
        <div class="block h-full w-full" aria-current="page">
            @include('components.destinations.carousel-panel-inner', ['slide' => $slide, 'index' => $index, 'count' => $count])
        </div>
    @else
        <a
            href="{{ $slide->url }}"
            data-stage-link
            class="block h-full w-full rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-philippine-gold"
        >
            @include('components.destinations.carousel-panel-inner', ['slide' => $slide, 'index' => $index, 'count' => $count])
        </a>
    @endif
</li>