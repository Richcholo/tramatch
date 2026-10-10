{{--
    The stage chevrons: the arrow controls, on the stage's own edges.

    BUTTONS NOW, AND THEY MOVE THE FAN. They used to be links to a neighbouring
    DESTINATION's page, which was right while the stage fanned across the whole
    catalogue and wrong the moment it stopped. A chevron that navigates off the
    page you are reading, in a control shaped exactly like the ones that move the
    pictures in front of you, is a control that lies about what it does.

    So they are the same action as the dots, the drag and the arrow keys, with
    three real advantages over all of them: they are the biggest target on the
    stage, they are reachable without touching the photographs, and they are
    visible to someone who has not worked out that the fan can be dragged at all.

    RENDERED UNCONDITIONALLY, and hidden from assistive technology at the ends
    rather than omitted. This is the opposite of what they used to do, and the
    reason is the drag: a control that vanishes at each end makes the stage look
    different as it moves, and a keyboard user's focus can be sitting on a button
    that is about to stop existing. They stay mounted, keep their position, and
    carry `disabled` plus `aria-hidden` when there is nowhere to go. `inert` is
    not used because it is not universal enough to rely on alone.

    Pinned to the stage's left and right EDGES at its vertical middle, which is
    where a thumb expects them and keeps them off the photographs. The 44px box
    is the hit area and the glyph is smaller, so the control does not crowd a
    320px panel.

    @param  int  $stageCount
--}}

@if (($stageCount ?? 0) > 1)
    <div class="tm-stage__chevrons">
@foreach (['prev' => 'Previous', 'next' => 'Next'] as $side => $word)
    @php
        /*
         * The disabled state and the aria-hidden state are the same
         * question -- is there anywhere to go -- so it is answered
         * once and used for both. The previous chevron runs out at
         * the first photograph and the next one at the last.
         *
         * This is a plain boolean expression on purpose. It was once
         * written as `index === 0 ? true : count - 1`, with its
         * next-side twin as `index >= 0 ? true : count`, and BOTH
         * chevrons were permanently disabled: `index >= 0` is always
         * true, and `count - 1` is a positive NUMBER, which Alpine
         * treats as truthy when it is bound to `disabled`. A truthy
         * non-boolean disables a button. The stage rendered two
         * arrows and neither could ever be clicked.
         */
        $atEnd = $side === 'prev' ? 'index === 0' : 'index === count - 1';
    @endphp
    <button
        type="button"
        data-stage-chevron="{{ $side }}"
        x-on:click="{{ $side === 'prev' ? 'prev()' : 'next()' }}"
        x-bind:disabled="{{ $atEnd }}"
        x-bind:aria-hidden="{{ $atEnd }}"
        aria-label="{{ $word }} photograph"
        class="tm-stage__chevron tm-stage__chevron--{{ $side }} tm-stage__chevron--{{ $side }}-on"
    >
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
            </button>
        @endforeach
    </div>
@endif
