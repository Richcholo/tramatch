{{--
    The inside of a carousel panel: the photograph, the scrim and the region pill.

    Kept as its own file so the link case and the current-page case in
    carousel-panel.blade.php cannot drift -- they are the only difference between
    those two, and duplicating the image markup is how they would end up with
    different lazy-loading or different alt text.

    ALT TEXT IS EMPTY ON PURPOSE. The panel is a link whose accessible name is the
    destination name, and the caption states the same name directly above it. An
    `alt` repeating the destination name announces it twice. The photograph itself
    is decorative here; per-destination photographic alt text is worth having and
    is a content task, not something to fake by echoing the name.

    `loading` and `decoding` matter: the active panel is the LCP candidate, the rest
    are not. `fetchpriority` is only meaningful on the active one.
--}}

<div class="relative h-full w-full overflow-hidden rounded-lg bg-volcanic-teal shadow-xl ring-1 ring-white/15 lg:rounded-xl">
    @if ($slide->hasImage())
        <img
            src="{{ $slide->imageUrl }}"
            alt=""
            width="320"
            height="570"
            class="h-full w-full object-cover"
            loading="{{ $slide->offset === 0 ? 'eager' : 'lazy' }}"
            decoding="async"
            @if ($slide->offset === 0) fetchpriority="high" @endif
        >
    @else
        {{-- A destination with no photo is an ordinary row, not a broken one. The
             gradient is the same palette the old hero used, so a photoless row
             still reads as the brand. --}}
        <span aria-hidden="true" class="absolute inset-0 bg-gradient-to-br from-boracay via-cyan-500 to-volcanic-teal"></span>
    @endif

    {{-- The scrim. A sibling of the image rather than a modifier on it, so it
         covers the rounded corners and the panel reads as one plate. Only the
         non-active panels need it: the caption sits over the active one and has a
         stage-level gradient scrim of its own to sit against. --}}
    @unless ($slide->offset === 0)
        <span aria-hidden="true" class="pointer-events-none absolute inset-0 bg-volcanic-teal/35"></span>
    @endunless

    {{-- The region pill. The reference labels the active panel with its REGION,
         not with its position in the sequence -- an earlier version said "Photo 1
         of 4", which is what the dots already say. --}}
    <span class="absolute left-1/2 top-3 -translate-x-1/2 whitespace-nowrap rounded-full bg-volcanic-teal/70 px-2.5 py-1 text-[0.6rem] font-bold uppercase tracking-[0.14em] text-boracay-light backdrop-blur">
        {{ $slide->regionLabel() }}
    </span>
</div>