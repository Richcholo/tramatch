{{--
    The stage caption: which destination is currently centred.

    STAGE-LEVEL, never a child of a panel. Two reasons, both from the reference: a
    caption inside a panel is clipped by that panel's overflow, so a long name loses
    its ends; and a panel painted above the copy can occlude it. z-40 against the
    fan's z-30 is what guarantees no panel ever covers this.

    `pointer-events-none` so the panels stay hoverable and clickable through the
    text area.

    DRIVEN BY THE ACTIVE INDEX, with the server-rendered slide as the no-JS
    fallback. Rendering the name from PHP alone would leave the caption frozen on
    the first destination's name while the fan moved on -- the same class of bug as
    the frozen fan itself, one level up. `x-text` means the caption and the panel
    can never disagree about which slide is active.

    The panels carry NO alt text, deliberately (see carousel-panel-inner), so this
    block is the only thing a screen reader reads on a slide change. It has to be
    complete on its own.

    SIZE IS LOAD-BEARING. An earlier version was `lg:text-7xl xl:text-8xl` with no
    width constraint, which put an ~90px headline across a 1500px stage and read as
    a page-wide banner laid over the photographs rather than a caption on them. The
    reference's heading is about 5% of the stage height and ~33% of its width. So a
    clamp() sized against the fan, and a max-width.

    NO DESCRIPTION HERE. It carried `Str::limit($destination->description, 90)` while
    the section below printed the same field in full, so the opening sentence
    appeared twice within one screenful. The reference has a one-line tagline; we
    have no tagline field, and inventing 65 of them would be fabricated copy. The
    description belongs once, in the prose block that is actually for reading.

    THE HEADING TAG IS A PARAMETER, not a hardcoded h1. On a destination page this
    IS the page heading, so it is an h1. On `/destinations` the header slot already
    carries "Destinations" as the h1 and this is the name of the slide currently
    centred -- subordinate to it, so the index passes h2. Hardcoding h1 put two h1s
    on the index page, which is a real document-outline defect and not something a
    screenshot would show.

    @param  \App\Domain\Destinations\CarouselSlide  $activeSlide
    @param  int  $activeIndex
    @param  int  $count
    @param  string  $headingTag
--}}

<div
    data-stage-caption
    class="pointer-events-none absolute inset-0 z-40 flex items-center justify-center px-6 text-center"
>
    <div class="max-w-3xl">
        <p
            data-stage-chip
            class="text-[0.65rem] font-bold uppercase tracking-[0.32em] text-boracay-light sm:text-xs"
            x-text="activeSlide().regionLabel"
        >{{ $activeSlide->regionLabel() }}</p>

        <{{ $headingTag }}
            data-stage-title
            class="mt-3 font-display text-[clamp(1.75rem,4.2vw,3rem)] font-semibold uppercase leading-[1.05] tracking-[-0.02em] text-white"
            x-text="activeSlide().name"
        >{{ $activeSlide->name }}</{{ $headingTag }}>

        {{-- The reference's short accent rule, flush-left with the text block
             rather than centred under it. Centred it reads as a divider; the
             reference uses it as a mark. --}}
        <span aria-hidden="true" class="mt-4 block h-0.5 w-8 bg-white/60"></span>

        <p
            data-stage-position
            class="mt-4 text-[0.7rem] uppercase tracking-[0.2em] text-white/55"
            x-text="(index + 1) + ' / ' + count"
        >{{ $activeIndex + 1 }} / {{ $count }}</p>
    </div>
</div>