{{--
    The photo carousel.

    Rendered as a plain row of images that JavaScript then upgrades, so a JS
    failure cannot make a destination's photos disappear. Every image is in the
    HTML with a real src and alt from the start; the carousel script only hides
    all but the current one and wires up the controls.

    Only rendered at all when there is more than one photo. A single extra photo
    is not a carousel, and a hero plus one picture reads as a mistake rather than
    a feature.

    Lives beside the location map rather than under the hero. It has been in
    three places: under the hero, then full-bleed across the page with the hero,
    and now in a narrow column next to the map. What ruled the full-bleed versions
    out was not taste -- the `100vw` one was off-centre by half a scrollbar
    (see AGENTS.md), and even the corrected version left a short photo band under
    a tall hero, which read as a strip rather than as the place itself. Beside the
    map it is a supporting element at the size it actually wants.

    It is a partial purely because of that move: it is now referenced from inside
    the location card's grid, and keeping the markup in one file is what stops the
    no-JS fallback and the script's hooks from drifting apart.

    @param  \App\Models\Destination  $destination
--}}
@if ($destination->images->count() > 1)
    {{--
        No max-width, and that is deliberate with a known cost. This column is
        roughly 40-45% of a 1600px container on a wide screen, while admin photos
        are stored verbatim by DestinationController::storeUploadedImage() with no
        resize and are typically 1080-1170px phone shots. It is narrower than the
        photo, so this box downscales and stays sharp -- the opposite of the
        full-width versions, which upscaled.

        object-cover is correct and must stay: it crops without distorting.
        object-fill would stretch the aspect ratio, and object-contain would
        letterbox a tall strip.

        The 1.5rem radius matches the map card beside it, so the two columns read
        as a pair rather than as an unrelated strip dropped next to a panel.
    --}}
    <section
        data-gallery
        aria-roledescription="carousel"
        aria-label="More photos of {{ $destination->name }}"
        class="relative overflow-hidden rounded-[1.5rem] bg-island-white shadow-sm ring-1 ring-boracay-light"
    >
        <ul
            data-gallery-track
            class="tm-no-scrollbar flex snap-x snap-mandatory overflow-x-auto scroll-smooth"
        >
            @foreach ($destination->images as $index => $galleryImage)
                <li
                    data-gallery-slide
                    class="w-full shrink-0 snap-center"
                    {{-- aria-hidden on the ones JS is hiding, so a screen
                         reader is not offered four copies of the same
                         place. Removed entirely by the script when it
                         takes over. --}}
                    @if ($index > 0) aria-hidden="true" @endif
                    role="group"
                    aria-roledescription="slide"
                    aria-label="{{ $index + 1 }} of {{ $destination->images->count() }}"
                >
                    <img
                        src="{{ $galleryImage->path }}"
                        alt="{{ $index === 0 ? $destination->name : $destination->name.' — photo '.($index + 1) }}"
                        class="h-64 w-full object-cover sm:h-80"
                        loading="lazy"
                    >
                </li>
            @endforeach
        </ul>

        {{-- Controls are absent without JS, and that is deliberate: a button
             that does nothing is worse than no button. The script inserts them
             once it is running.

             bottom-2.5 rather than bottom-0: flush against the crop's bottom edge
             looked cramped, and 0.625rem is the 10px lift. The container keeps p-4,
             so the buttons sit 10px up with their own padding still around them. --}}
        <div data-gallery-controls class="absolute inset-x-0 bottom-2.5 flex items-center justify-center gap-2 p-4"></div>
    </section>
@endif
