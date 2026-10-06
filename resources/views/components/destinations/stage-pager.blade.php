{{--
    The pager: a dot per photograph in reach, plus the pause control.

    BELOW THE STAGE, not on it. The stage is the loudest, darkest element on the
    page and the fan is five panels deep; a dot row sitting on the teal would sit
    on the photographs and on the caption, and 200-odd of them would not fit.

    DOTS SHOW A WINDOW AROUND THE ACTIVE INDEX -- the same reach as the fan -- so
    the control means "nearby photographs" rather than "here are every photograph
    in the catalogue". With a destination set that is 65 destinations of up to four
    photographs each, a dot per photograph is a texture, not a pager. Each dot's
    accessible name still carries its real position ("Photograph 42 of 187").

    THE PAUSE CONTROL IS MANDATORY, NOT A NICETY. Autoplay starts by itself and
    runs for longer than five seconds, which is WCAG 2.2.2: a visitor must be able
    to stop moving content that moves without being asked. It is always in the
    accessibility tree with `aria-pressed`, and it is rendered wherever autoplay is
    on.

    THE ICON SWAP IS CSS KEYED OFF `aria-pressed`, NOT `x-show`. `x-show` toggles
    the `hidden` ATTRIBUTE, and there is no `x-cloak` rule in `app.css`, so an
    `x-show` pair would paint both icons for a frame before Alpine initialises.
    Driving the swap from the state attribute the control already needs means the
    server-rendered markup is the correct icon from the first paint, with no
    JavaScript involved.

    The dots are buttons, not links: they move the fan in place and do not
    navigate. The panel itself is the link to that destination.

    @param  \App\Domain\Destinations\CarouselSlide[]  $stageSlides
    @param  int  $stageActiveIndex
    @param  int  $reach
    @param  int  $stageInterval
--}}

@php
    $count = count($stageSlides);

    /*
     * Which dots to show. A window the same size as the fan's reach, so the pager
     * and the fan always agree about what is on stage. Clamped at both ends.
     */
    $windowStart = max(0, min($stageActiveIndex - $reach, $count - ($reach * 2 + 1)));
    $windowEnd = min($windowStart + ($reach * 2), $count - 1);
@endphp

@if ($count > 1)
    <div
        data-stage-pager
        :class="{ 'is-swapping': ! settled }"
        class="tm-stage__pager"
        role="group"
        aria-label="Choose a photograph"
    >
        <div class="tm-stage__dots">
            @for ($i = $windowStart; $i <= $windowEnd; $i++)
                <button
                    type="button"
                    data-stage-dot="{{ $i }}"
                    aria-label="Photograph {{ $i + 1 }} of {{ $count }}"
                    x-on:click="go({{ $i }})"
                    :class="{ 'is-active': index === {{ $i }} }"
                    aria-current="{{ $i === $stageActiveIndex ? 'true' : 'false' }}"
                    x-bind:aria-current="index === {{ $i }} ? 'true' : 'false'"
                    class="tm-stage__dot"
                ></button>
            @endfor
        </div>

        {{--
            44px target, 24px glyph. The button is a ghost ring like the chevrons so
            the two controls read as a pair; the glyph alone is what is visible, and
            `aria-pressed` is what actually communicates the state.
        --}}
        <button
            type="button"
            data-stage-pause
            aria-label="Pause the slideshow"
            aria-pressed="false"
            x-on:click="toggle()"
            x-bind:aria-pressed="paused ? 'true' : 'false'"
            x-bind:aria-label="paused ? 'Resume the slideshow' : 'Pause the slideshow'"
            class="tm-stage__pause"
        >
            {{-- Pause: two bars. --}}
            <svg data-icon="pause" viewBox="0 0 16 16" aria-hidden="true" class="tm-stage__pause-glyph">
                <rect x="3" y="2" width="3.5" height="12" rx="1"></rect>
                <rect x="9.5" y="2" width="3.5" height="12" rx="1"></rect>
            </svg>

            {{-- Play: one triangle. --}}
            <svg data-icon="play" viewBox="0 0 16 16" aria-hidden="true" class="tm-stage__pause-glyph">
                <path d="M4 2.5v11a1 1 0 0 0 1.53.85l8.2-5.5a1 1 0 0 0 0-1.7l-8.2-5.5A1 1 0 0 0 4 2.5Z"></path>
            </svg>
        </button>
    </div>
@endif
