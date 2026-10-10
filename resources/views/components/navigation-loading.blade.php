{{--
    Loading overlay shown while the next page is fetched.

    Timing is the whole design here. A user on a weak connection clicks a link
    and, with no feedback at all, reasonably concludes the click was ignored and
    clicks again. So:

      ~0ms    the button they pressed shows its :active state, which lands
              inside a single frame
      350ms   this overlay fades in, covering every fast navigation
      arrive  200ms fade out (see page-transitions.js)

    A gradient rather than a skeleton because it should read as "working", not
    as "here is a different page you are not looking at". The gradient sits on
    the brand colours so it feels like the app rather than a system dialog.
--}}
<div
    data-navigation-loading
    role="status"
    aria-live="polite"
    class="tm-loader pointer-events-none fixed inset-0 z-[100000] hidden"
>
    <span class="sr-only">Loading page...</span>

    <div aria-hidden="true" class="tm-loader__gradient"></div>

    <div aria-hidden="true" class="tm-loader__mark">
        <svg class="h-12 w-12" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
            {{-- An arc with a gap, spinning. Stroke-dasharray carves the gap so
                 no pathLength math is needed to keep the ends square. --}}
            <circle
                cx="20"
                cy="20"
                r="16"
                stroke="currentColor"
                stroke-width="3"
                stroke-linecap="round"
                stroke-dasharray="26 74"
                class="tm-loader__arc"
            />
        </svg>
    </div>

    <div aria-hidden="true" class="tm-loader__bar">
        <span></span>
    </div>
</div>