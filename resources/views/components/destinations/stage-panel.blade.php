{{--
    One stage panel: a photograph, as a link to the destination it was taken at.

    EVERY PANEL IS A LINK, INCLUDING THE ACTIVE ONE ON THE PAGE YOU ARE ALREADY
    ON. The obvious alternative -- rendering the current panel as a plain div, so
    its `href` is not a link to itself -- means two markup shapes in one loop, and
    two shapes is how the photograph markup ended up differing between them in an
    earlier version. A link to the page you are on is a harmless no-op; a second
    shape is a permanent source of drift. `aria-current="page"` is what tells a
    screen reader which one it is.

    THE OFFSET IS THE WHOLE MECHANISM. `data-offset` is SIGNED and RELATIVE TO THE
    ACTIVE PANEL: 0 is active, -1 the panel to its left, +1 to its right, and
    anything past the reach is off-stage behind `data-far`. Every geometric
    property -- position, size, rotation, stacking, dimming -- is derived from it in
    `app.css`, and the browser's transition between one attribute value and the
    next IS the animation.

    So the value written here is the STARTING STATE, which is what makes the page
    correct with JavaScript disabled. It is not re-rendered per advance and
    nothing in the view re-reads it: the Alpine binding below is what moves the
    fan. If you ever set an offset from PHP expecting it to survive an advance, it
    will not -- that inversion shipped once as a stage that looked frozen.

    @param  \App\Domain\Destinations\CarouselSlide  $slide
    @param  int  $index
    @param  int  $reach
    @param  bool  $inPreloadWindow
    @param  bool  $isCurrentPage
--}}

<li
    data-stage-panel
    data-offset="{{ $slide->offset }}"
    @if (abs($slide->offset) > $reach) data-far @endif
    {{-- Read by the script to build the live-region announcement. --}}
    data-stage-name="{{ $slide->name }}"
    data-stage-region="{{ $slide->province }}"
    {{--
        The server's offset, under a name the script NEVER writes.

        `data-offset` is the live attribute and Alpine overwrites it on every
        index change, so it cannot be read back as the starting state. This one
        is written once by PHP and never bound, and that is what lets the script
        fall back to exactly what the server rendered if it cannot read its own
        payload.

        A stage that cannot read its data must leave the stage as it found it.
        Without this, an unreadable island makes `index` clamp to 0 and the fan
        re-index itself onto the first panel in the DOM, which is a different
        photograph entirely, under the correct destination's caption.
    --}}
    data-stage-start="{{ $slide->offset }}"
    x-bind:data-offset="offsetOf({{ $index }})"
    
    x-bind:data-far="offsetOf({{ $index }}) > {{ $reach }} || offsetOf({{ $index }}) < -{{ $reach }} ? '' : null"
    x-bind:aria-hidden="offsetOf({{ $index }}) > {{ $reach }} || offsetOf({{ $index }}) < -{{ $reach }} ? 'true' : 'false'"
    x-bind:tabindex="offsetOf({{ $index }}) > {{ $reach }} || offsetOf({{ $index }}) < -{{ $reach }} ? -1 : 0"
    class="tm-fan-panel"
>
    <a
        href="{{ $slide->url }}"
        data-stage-link
        @if ($isCurrentPage) aria-current="page" @endif
        class="tm-fan-panel__link"
    >
        @include('components.destinations.stage-panel-inner', [
            'slide' => $slide,
            'inPreloadWindow' => $inPreloadWindow,
        ])
    </a>
</li>
