{{--
    The stage caption: which photograph is currently centred, and of what.

    STAGE-LEVEL, never a child of a panel. Two reasons, both learned here: a
    caption inside a panel is clipped by that panel's own `overflow: hidden`, so a
    long name loses its ends; and a panel painted above the copy occludes it. Its
    z-index against the fan is what guarantees no panel ever covers this.

    BELOW THE FAN, NOT ON TOP OF IT. The caption is in
    flow between the fan and the pager, so every word sits on the
    theatre's own ground rather than over a photograph. It used to
    be absolutely positioned over the lower third of the panels,
    which was the reported "text clashes with the images", and no
    scrim carries a headline over a full-brightness photograph
    without reading as a band across it. The `pointer-events-none`
    that went with the overlap went with it: nothing is under the
    caption any more, so there is nothing to click through.

    =========================================================================
    EVERY FIELD IS READ OFF ONE SLIDE. NEVER TWO.
    =========================================================================

    This is the reason the caption exists at stage level rather than inside a
    panel, and it is the one thing here that must not be refactored away.

    Slides are PHOTOGRAPHS GROUPED BY DESTINATION, so two adjacent slides can be
    two different places -- and the fan can be sitting on any of them, because the
    dots move it in place and autoplay walks it on its own. A caption assembled
    field-by-field from "the active panel" and "the previous active panel" would
    eventually show one destination's name over another destination's peso figure,
    with both rendered correctly and both tests passing. Every binding below
    therefore goes through `active()`, which is one index into one array.

    DRIVEN BY THE ACTIVE INDEX, with the server-rendered slide as the no-JS
    fallback. Rendering the name from PHP alone would leave the caption frozen on
    the first slide's text while the fan moved on -- the same class of bug as the
    frozen fan itself, one level up.

    ALWAYS AN `h2`, NEVER AN `h1`. On a destination page the document heading is
    the page heading, on the sand, ABOVE this stage; on `/destinations` it is
    "Destinations" in the page header. This is a caption under photographs. An `h1`
    here would either duplicate the page's heading or, worse, become the page's
    heading and describe a photograph of somewhere the reader is not.

    NO DESCRIPTION IN FULL. The one-sentence standfirst here is the FIRST SENTENCE
    of the description and `Destination::bodyDescription()` prints what is left in
    the prose block below. They are a pair. Printing the description in both
    places puts the same opening sentence on screen twice, which is what an earlier
    version of this stage did.

    THE HEADING BREAKS ONTO EXACTLY TWO LINES by construction: the name and the
    province are two separate block-level spans rather than one string the browser
    wraps where it likes. A name long enough to wrap anyway will push the province
    to a third line -- the sizes are generous and the stage is wide, so this is the
    rare case, and it degrades to a taller caption rather than to a truncated name.
--}}

@php
    $active = $stageSlides[$stageActiveIndex] ?? null;
    $matchScore = $active ? ($stageMatchScores[$active->destinationId] ?? null) : null;
@endphp

<div
    data-stage-caption
    :class="{ 'is-settling': ! settled }"
    class="tm-stage__caption"
>
    <div class="tm-stage__caption-inner">
        {{--
            The gold kicker. An interest tag, not a region: this app has no region
            column, and the province already appears twice in this caption. See
            `Destination::kicker()`.
        --}}
        <p
            data-stage-kicker
            class="tm-stage__kicker"
            x-show="active().kicker !== ''"
        >{{ $active?->kicker }}</p>

        <h2 data-stage-title class="tm-stage__name">
            <span data-stage-name class="tm-stage__name-line" x-text="active().name">{{ $active?->name }}</span>
            <span data-stage-province class="tm-stage__name-line" x-text="'— ' + active().province">— {{ $active?->province }}</span>
        </h2>

        <span aria-hidden="true" class="tm-stage__divider"></span>

        <p
            data-stage-place
            class="tm-stage__place"
            x-text="place()"
        >{{ $active?->municipality }} · {{ $active?->province }}</p>

        <p
            data-stage-standfirst
            class="tm-stage__standfirst"
            x-show="active().standfirst !== ''"
        >{{ $active?->standfirst }}</p>

        {{--
            The micro-label row.

            SEPARATORS ARE INSIDE THE PAIR THEY FOLLOW. Hiding a metric has to hide
            the dot after it too, or a visitor with no match score is left reading
            "· COST ₱1,250". Which is why each dot is a sibling of the metric it
            follows rather than of the row, and why the two are hidden by the same
            expression.

            NO `x-text` ON THE TWO NUMBERS. The script owns their textContent
            because it animates them; an `x-text` would overwrite the count on
            every re-evaluation and the two writers would fight. The server-rendered
            value is the correct no-JS fallback either way.
        --}}
        <p data-stage-metrics class="tm-stage__metrics">
            @if ($matchScore !== null)
                <span data-stage-metric="match" class="tm-stage__metric">
                    <span class="tm-stage__metric-label">Match</span>
                    <span
                        data-stage-number="match"
                        data-stage-value="{{ number_format($matchScore, 0) }}"
                        class="tm-stage__metric-value tm-stage__metric-value--gold"
                    >{{ number_format($matchScore, 0) }}</span>
                </span>

                <span data-stage-separator="match" aria-hidden="true" class="tm-stage__metric-dot"></span>
            @endif

            <span data-stage-metric="cost" class="tm-stage__metric">
                <span class="tm-stage__metric-label">Est. cost</span>
                <span
                    data-stage-number="cost"
                    data-stage-value="{{ $active?->estimatedCost ?? '' }}"
                    data-stage-prefix="₱"
                    class="tm-stage__metric-value"
                >{{ $active?->estimatedCostDisplay }}</span>
            </span>

            <span data-stage-separator="cost" aria-hidden="true" class="tm-stage__metric-dot"></span>

            {{--
                The tier as a WORD plus a three-step indicator. The word is what
                carries the meaning and the indicator only ranks it -- so the tier is
                never communicated by position or colour alone, and the indicator
                is `aria-hidden` because the word beside it is the same fact.
            --}}
            <span data-stage-metric="budget" class="tm-stage__metric">
                <span class="tm-stage__metric-label">Budget</span>
                <span data-stage-budget class="tm-stage__metric-value" x-text="active().budgetLevel">{{ $active?->budgetLevel }}</span>

                <span data-stage-budget-steps aria-hidden="true" class="tm-stage__steps">
                    @for ($step = 1; $step <= 3; $step++)
                        <span
                            data-stage-budget-step="{{ $step }}"
                            x-bind:data-on="active().budgetStep >= {{ $step }} ? '' : null"
                        ></span>
                    @endfor
                </span>
            </span>
        </p>
    </div>
</div>
