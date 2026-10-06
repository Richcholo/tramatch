{{--
    The pager: a dot per destination, plus the pause control.

    DOTS. With 65 destinations, 65 dots is not a pager, it is a texture. So the dots
    show a WINDOW around the active index -- the same reach as the fan -- which is
    what makes the control mean "nearby slides" rather than "here are 65 things".

    THE PAUSE CONTROL IS MANDATORY, not a nicety. Autoplay runs on a loop longer
    than five seconds, and WCAG 2.2.2 requires a way to pause moving content that
    starts by itself. It is always in the accessibility tree with `aria-pressed`,
    and it is rendered whenever autoplay is on.

    The pause icon is two bars and the play icon a triangle, swapped by `x-show` on
    the paused state. Both are inline SVG rather than characters so they can be
    styled consistently and so a font cannot substitute a different glyph.

    The dots are buttons, not links: they move the fan in place and do not
    navigate. The panel itself is the link to that destination.

    @param  \App\Domain\Destinations\CarouselSlide[]  $slides
    @param  int  $activeIndex
    @param  bool  $autoplay
--}}

@php
    $count = count($slides);

    /*
     * Which dots to show. A window the same size as the fan's reach, so the pager
     * and the fan always agree about what is on stage. Clamped at both ends.
     */
    $windowStart = max(0, min($activeIndex - 2, $count - 5));
    $windowEnd = $windowStart + 4;
@endphp

@if ($count > 1)
    <div
        data-stage-pager
        class="relative z-40 flex items-center justify-center gap-4 pb-10 pt-6"
        role="group"
        aria-label="Choose a destination"
    >
        <div class="flex items-center gap-2">
            @for ($i = $windowStart; $i <= min($windowEnd, $count - 1); $i++)
                <button
                    type="button"
                    data-stage-dot="{{ $i }}"
                    aria-label="Go to destination {{ $i + 1 }}"
                    aria-current="{{ $i === $activeIndex ? 'true' : 'false' }}"
                    x-bind:aria-current="index === {{ $i }} ? 'true' : 'false'"
                    x-on:click="go({{ $i }})"
                    class="h-2 w-2 rounded-full transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-philippine-gold"
                    @class([
                        'bg-white' => $i === $activeIndex,
                        'bg-white/30 hover:bg-white/60' => $i !== $activeIndex,
                    ])
                ></button>
            @endfor
        </div>

        @if ($autoplay)
            {{--
                44px target, 16px glyph. The button is transparent; only the glyph
                is visible, which is why the `aria-pressed` below is what actually
                communicates the state rather than the icon alone.
            --}}
            <button
                type="button"
                data-stage-pause
                aria-label="Pause automatic slideshow"
                x-bind:aria-label="paused ? 'Resume automatic slideshow' : 'Pause automatic slideshow'"
                aria-pressed="false"
                x-bind:aria-pressed="paused ? 'true' : 'false'"
                x-on:click="toggle()"
                class="flex h-11 w-11 items-center justify-center rounded-full text-white/70 transition hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-philippine-gold"
            >
                {{-- Pause: two bars. --}}
                <svg
                    data-icon="pause"
                    x-show="!paused"
                    viewBox="0 0 16 16"
                    class="h-4 w-4 fill-current"
                    aria-hidden="true"
                >
                    <rect x="3" y="2" width="3.5" height="12" rx="1"></rect>
                    <rect x="9.5" y="2" width="3.5" height="12" rx="1"></rect>
                </svg>

                {{-- Play: one triangle. --}}
                <svg
                    data-icon="play"
                    x-cloak
                    x-show="paused"
                    viewBox="0 0 16 16"
                    class="h-4 w-4 fill-current"
                    aria-hidden="true"
                >
                    <path d="M4 2.5v11a1 1 0 0 0 1.53.85l8.2-5.5a1 1 0 0 0 0-1.7l-8.2-5.5A1 1 0 0 0 4 2.5Z"></path>
                </svg>
            </button>
        @endif
    </div>
@endif