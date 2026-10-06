{{--
    The photo carousel.

    Rendered as a plain row of images that JavaScript then upgrades, so a JS
    failure cannot make a destination's photos disappear. Every image is in the
    HTML with a real src and alt from the start; the carousel script only hides
    all but the current one and wires up the controls.

    Only rendered at all when there is more than one photo. A single extra photo
    is not a carousel, and a hero plus one picture reads as a mistake rather than
    a feature.

    Lives directly under the hero at the full container width. It has been in
    four places: here, full-bleed across the page with the hero (two attempts, both
    abandoned -- see AGENTS.md), and in a narrow column beside the location map.
    The map column was tried last and left half the page empty on a wide screen,
    while shrinking the photos to 40% of the container.

    It is a partial rather than inlined so the markup and the gallery.js hooks stay
    in one file; when it was inline, the no-JS fallback and the script's selectors
    could drift apart silently.

    @param  \App\Models\Destination  $destination
--}}
@if ($destination->images->count() > 1)
    {{--
        Full container width, deliberately, with a known cost recorded here so it
        is not rediscovered as a bug.

        The container's content box is roughly 1504px on a 1600px viewport, while
        admin photos are stored verbatim by
        DestinationController::storeUploadedImage() with no resize and are typically
        1080-1170px phone shots. So the browser scales each photo up by about
        1.3-1.4x and it renders soft on a wide screen. A narrower version of this
        was implemented and reverted more than once, each time sharp and each time
        judged too small. The real fix is resizing the uploads, which needs GD or
        Imagick -- absent from both the CLI and XAMPP.

        NO `max-w`, and NO `100vw`. Full-bleed was tried twice: `width: 100vw`
        landed off-centre by half a scrollbar (100vw includes the vertical
        scrollbar, and the 50vw centring split the excess across both edges while
        the clip took only one), and a negative-margin version was aligned but too
        wide for the page. The container width needs no such arithmetic.

        object-cover is correct and must stay: it crops without distorting.
        object-fill would stretch the aspect ratio; object-contain would letterbox
        a strip this wide.

        2rem radius, matching the hero directly above it, so the two read as one
        media block rather than two unrelated cards.
    --}}
    <section
        data-gallery
        aria-roledescription="carousel"
        aria-label="More photos of {{ $destination->name }}"
        class="relative overflow-hidden rounded-[2rem] bg-island-white shadow-sm ring-1 ring-boracay-light"
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
                        class="h-80 w-full object-cover sm:h-96 lg:h-[30rem]"
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
