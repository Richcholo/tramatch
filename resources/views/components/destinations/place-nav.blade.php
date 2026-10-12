{{--
    THE PLACE STRIP: previous, next, and a jump box.

    IT SITS BELOW THE STAGE, IN FLOW, AND THAT IS THE WHOLE DESIGN PROBLEM.

    The stage's own chevrons move the FAN -- they step through one destination's
    photographs and do not navigate. These links do the opposite: they change
    which destination the page is about. Two controls that point in different
    directions must not wear the same clothes, and the stage already spent a
    lesson on that: its chevrons used to navigate to a neighbouring destination
    while looking exactly like the ones that moved the carousel, and the whole
    "controls move the fan" promise went with it.

    So this is text, not arrows. It reads "Baguio" and "Vigan" because those are
    the names of the places you are about to land on, which is the information a
    "next" control owes you and an arrow cannot carry.

    THE POSITION IS IN THE MIDDLE, and it is the reason this strip earns its
    space: "place 3 of 65" is the answer to "how much of Luzon is left", which
    is the question a visitor browsing a catalogue is actually asking.

    NO WRAP-AROUND. The first place has no previous and the last has no next, and
    a link that quietly returned you to the first place would be a carousel's
    trick -- here it would be a lie about there being somewhere after this.

    QUICKHOP IS A PLAIN GET FORM TO ITS OWN ROUTE. No JavaScript, no fetch, no
    results dropdown to keep in step with the catalogue: the form submits, the
    controller resolves the term, the browser lands on that place's own URL. It
    works with scripting off, it cannot drift from the listing's search, and
    Back/Forward and a shared link replay the place instead of re-running a
    query. The `<datalist>` is the platform's own autocomplete, so the
    suggestions cost nothing either.

    @param  array{previous: ?\App\Models\Destination, next: ?\App\Models\Destination, position: int, total: int}  $neighbours
    @param  array<int, string>  $quickhopPlaces
--}}
<nav
    aria-label="Browse destinations"
    class="tm-place-nav mt-8 border-t border-white/10 pt-6"
>
    <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
        {{-- Previous. Rendered even when there is none, as an inert placeholder,
             so "Next" does not jump to the far edge of the row on the first
             place and then back again on the second. --}}
        <div class="flex-1">
            @if ($neighbours['previous'])
                <a
                    href="{{ route('destinations.show', $neighbours['previous']) }}"
                    rel="prev"
                    class="tm-place-nav__link group inline-flex max-w-full items-center gap-3"
                >
                    <span class="tm-place-nav__arrow" aria-hidden="true">&larr;</span>
                    <span class="min-w-0">
                        <span class="tm-place-nav__label">Previous place</span>
                        <span class="tm-place-nav__name">{{ $neighbours['previous']->name }}</span>
                    </span>
                </a>
            @else
                <span class="tm-place-nav__empty" aria-hidden="true">First place in Luzon</span>
            @endif
        </div>

        <p class="tm-place-nav__position">
            <span class="sr-only">You are viewing place</span>
            {{ $neighbours['position'] }} of {{ $neighbours['total'] }}
        </p>

        <div class="flex flex-1 justify-start sm:justify-end">
            @if ($neighbours['next'])
                <a
                    href="{{ route('destinations.show', $neighbours['next']) }}"
                    rel="next"
                    class="tm-place-nav__link group inline-flex max-w-full items-center gap-3 sm:flex-row-reverse sm:text-right"
                >
                    <span class="tm-place-nav__arrow" aria-hidden="true">&rarr;</span>
                    <span class="min-w-0">
                        <span class="tm-place-nav__label">Next place</span>
                        <span class="tm-place-nav__name">{{ $neighbours['next']->name }}</span>
                    </span>
                </a>
            @else
                <span class="tm-place-nav__empty sm:text-right" aria-hidden="true">Last place in Luzon</span>
            @endif
        </div>
    </div>

    {{--
        QUICKHOP. A `<form method="get">` rather than a button with a click
        handler, so it submits without JavaScript -- and `autocomplete="off"`
        because the browser's own history is a worse suggestion list than the
        catalogue.
    --}}
    <form
        method="GET"
        action="{{ route('destinations.quickhop') }}"
        role="search"
        class="mt-6 flex flex-col gap-2 sm:flex-row sm:items-center"
    >
        <label for="quickhop-q" class="tm-place-nav__label sm:w-32 sm:shrink-0">
            Quickhop
        </label>

        <div class="flex flex-1 flex-col gap-2 sm:flex-row">
            <input
                type="search"
                id="quickhop-q"
                name="q"
                list="quickhop-places"
                autocomplete="off"
                placeholder="Jump to a place or province"
                class="tm-place-nav__input min-w-0 flex-1"
            >

            <datalist id="quickhop-places">
                @foreach ($quickhopPlaces as $place)
                    <option value="{{ $place }}"></option>
                @endforeach
            </datalist>

            <button type="submit" class="tm-place-nav__go">
                Go
            </button>
        </div>
    </form>
</nav>