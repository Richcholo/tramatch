{{--
    The pager: a dot per nearby destination.

    DOTS. With 65 destinations, 65 dots is not a pager, it is a texture. So the dots
    show a WINDOW around the active index -- the same reach as the fan -- which is
    what makes the control mean "nearby slides" rather than "here are 65 things".

    No pause control here. The carousel is on a destination page now and does not
    autoplay, so a pause button would be a dead control -- and WCAG 2.2.2 only
    applies to content that starts moving by itself.

    The dots are buttons, not links: they move the fan in place. The panel itself is
    the link to that destination.

    @param  \App\Domain\Destinations\CarouselSlide[]  $slides
    @param  int  $activeIndex
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
        class="relative z-40 mt-8 flex items-center justify-center gap-2"
        role="group"
        aria-label="Choose a destination"
    >
        @for ($i = $windowStart; $i <= min($windowEnd, $count - 1); $i++)
            <button
                type="button"
                data-stage-dot="{{ $i }}"
                aria-label="Show {{ $slides[$i]->name }}"
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
@endif