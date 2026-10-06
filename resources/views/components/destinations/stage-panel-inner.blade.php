{{--
    The inside of a stage panel: the photograph, the scrim, the province pill.

    KEPT IN ITS OWN FILE so there is exactly one description of what a panel looks
    like. The link case and the current-page case used to be two branches in the
    parent, which meant the photograph markup could drift between them -- two
    panels on the same stage with different lazy-loading and different alt text,
    from one destination's page.

    EVERY ACTIVE/DIMMED DECISION HERE IS CSS, NOT JAVASCRIPT. The pill, the gold
    hairline and the depth scrim are all keyed off the PANEL's own `data-offset`,
    which the stage script rewrites as the fan moves. None of them is a class
    toggled from script and none of it is rendered conditionally by PHP.

    That is the whole reason they live here rather than in the parent partial: the
    province pill reads its own province from its own panel, so it cannot ever show
    one destination's province over another destination's photograph -- the failure
    mode a stage-level caption has to work hard to avoid, and a per-panel one
    cannot have.

    ALT TEXT IS EMPTY, DELIBERATELY. The panel is a link whose accessible name is
    the destination name, and the caption states that same name directly over it.
    An `alt` echoing the name announces it twice. The photograph is decorative
    here; per-destination photographic alt text is worth having and is a content
    task, not something to fake by repeating the name.

    `loading` and `fetchpriority` are set by the server for the opening slide only,
    because that one is the LCP candidate. The panels the script brings on-stage
    later are preloaded by `src`, not by `loading`.
--}}

<div class="tm-fan-card">
    {{--
        `data-src` rather than `src` for every panel outside the preload window.

        A destination set is up to 65 destinations of up to four photographs, so
        rendering 200-odd eager `src` attributes would have the browser fetch
        every photograph in the catalogue on the first page load. The stage script
        promotes `data-src` to `src` as a panel approaches, one slide ahead of the
        fan's reach, and drops it again once the panel is far off-stage.

        The window is wider than the reach on purpose: a panel that only got its
        `src` at the moment it became visible would decode from nothing and flash.
    --}}
    <img
        data-src="{{ $slide->imageUrl }}"
        @if ($inPreloadWindow) src="{{ $slide->imageUrl }}" @endif
        alt=""
        width="320"
        height="570"
        class="tm-fan-card__image"
        @if ($slide->offset === 0)
            loading="eager"
            fetchpriority="high"
        @else
            loading="lazy"
        @endif
        decoding="async"
    >

    {{--
        The bottom-up scrim. A SIBLING of the image rather than a modifier on it,
        so it covers the rounded corners and the panel reads as one plate.

        It exists because the caption sits over the lower third of the fan, and
        text over raw photograph is text nobody can read. Its top end is fully
        transparent so it darkens nothing above the lower third.
    --}}
    <span aria-hidden="true" class="tm-fan-card__scrim"></span>

    {{-- The province, on the ACTIVE panel only. See the header note. --}}
    <span class="tm-fan-card__pill">{{ $slide->province }}</span>
</div>
