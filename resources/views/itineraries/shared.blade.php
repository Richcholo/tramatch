{{--
    THE PUBLIC, READ-ONLY ITINERARY.

    Reached through `/itineraries/{itinerary}/shared/{token}` with NO
    authentication: the token in the URL is the whole access control, and it
    is checked beside the record in `sharedPreview()` before this file runs.

    Everything below is an INCLUDE of the owner's own read surface — the
    same hero, the same map, the same days grid, and nothing editable. There
    is deliberately no editor markup here at all: `itinerary-editor.js` is
    not loaded, the layout's script never touches any of this page's
    elements, and a recipient who opens the page's source finds no form to
    submit. The link is read-only because the page is, not because a hidden
    form is hard to find.
--}}

<x-app-layout>
    <x-slot name="header">
        <a
            href="{{ route('destinations.index') }}"
            class="text-sm font-semibold text-boracay-dark hover:text-volcanic-teal"
        >
            Plan your own trip on TraMatch →
        </a>
    </x-slot>

    <div class="mx-auto mb-6 max-w-7xl lg:px-2">
        <div class="rounded-[2rem] border border-boracay-light bg-island-white px-6 py-5 shadow-sm sm:px-8">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    {{-- What this page is, in one line for the recipient. --}}
                    <h2 class="font-display text-xl font-semibold text-volcanic-teal">
                        A shared trip, read-only.
                    </h2>

                    <p class="mt-1 text-sm text-benguet-charcoal/65">
                        {{ ucfirst($itinerary->user->name ?? 'The traveller') }} shared this plan with you.
                        It is a read-shaped view of a live trip: nothing here can be changed, and
                        anything the traveller saves shows up the next time the link is opened.
                    </p>
                </div>
            </div>
        </div>
    </div>

    @include('itineraries.partials.trip')

    <div class="mt-10">
        <section class="rounded-[2rem] bg-island-white p-4 shadow-sm ring-1 ring-boracay-light sm:p-6">
            <div class="mb-6 px-2 pt-2">
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                    The route
                </p>

                <h3 class="mt-2 font-display text-3xl font-semibold tracking-[-0.04em] text-volcanic-teal">
                    {{ $itinerary->title }} — day by day.
                </h3>
            </div>

            @include('itineraries.partials.days')
        </section>
    </div>
</x-app-layout>
