{{--
    The days grid, shared between the editor and the public share page.

    The owner's page includes this inside its own editor section, so the
    compiler's toggle still works; the share page includes it inside a plain
    section of its own, where the attribute is inert. The grid itself is
    identical on both: same times, same leg labels, same free-time sentence.
    Never fork it.
--}}

            <div data-editor-read>
                <div class="grid gap-6 lg:grid-cols-2">
                    @foreach ($itinerary->days as $day)
                        <article class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-8">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <h3 class="text-2xl font-bold text-volcanic-teal">
                                    Day {{ $day->day_number }}
                                </h3>

                                @if ($day->date)
                                    <span class="text-sm text-benguet-charcoal/60">
                                        {{ $day->date->format('M d, Y') }}
                                    </span>
                                @endif
                            </div>

                            <div class="mt-7 space-y-6">
                                {{-- THE STOP BEFORE THIS ONE, FOR THE DRIVE INTO IT.
                                     Reset per day, because the leg labels below are
                                     per day too: the first stop of a day has no
                                     drive before it, and its directions link asks
                                     the map app where the traveller actually is. --}}
                                @php($previousItem = null)
                                @forelse ($day->items as $item)
                                    <div class="relative border-l-2 border-boracay pl-5">
                                        <span class="absolute -left-[0.45rem] top-0 h-3 w-3 rounded-full bg-philippine-gold"></span>

                                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-boracay-dark">
                                            {{ $item->kindLabel() }}
                                        </p>

                                        <p class="mt-1 text-xs font-bold uppercase tracking-[0.18em] text-boracay-dark/70">
                                            @if ($item->start_time && $item->end_time)
                                                {{-- One instant, not a range. A lodging stop has no visit
                                                     duration, and printing "16:45 - 16:45"
                                                     reads as a bug rather than as the
                                                     truthful statement that there is no
                                                     duration to print. --}}
                                                @if (substr($item->start_time, 0, 5) === substr($item->end_time, 0, 5))
                                                    {{ substr($item->start_time, 0, 5) }}
                                                @else
                                                    {{ substr($item->start_time, 0, 5) }}
                                                    –
                                                    {{ substr($item->end_time, 0, 5) }}
                                                @endif
                                            @else
                                                Flexible time
                                            @endif
                                        </p>

                                        {{-- Attractions link to their page. A meal,
                                             a hotel or a custom entry has no page
                                             here, so its name is text — a link
                                             needs a destination, and inventing
                                             one would be a dead control. --}}
                                        @if ($item->isAttraction() && $item->destination)
                                            <a
                                                href="{{ route('destinations.show', $item->destination) }}"
                                                class="mt-2 block text-xl font-bold text-volcanic-teal hover:text-boracay-dark"
                                            >
                                                {{ $item->placeName() }}
                                            </a>
                                        @else
                                            <span class="mt-2 block text-xl font-bold text-volcanic-teal">
                                                {{ $item->placeName() }}
                                            </span>
                                        @endif

                                        <p class="mt-2 text-sm text-benguet-charcoal/60">
                                            @if ($item->isCustom())
                                                {{ $item->custom_address ?: 'Your own stop' }}
                                            @elseif ($item->place)
                                                {{ $item->place->municipality }}, {{ $item->place->province }}
                                            @elseif ($item->destination)
                                                {{ $item->destination->municipality }}, {{ $item->destination->province }}
                                            @endif
                                        </p>

                                        <div class="mt-3 flex flex-wrap gap-3 text-xs font-semibold text-benguet-charcoal/60">
                                            {{-- costLabel(), not a bare number: an unpriced
                                                 meal stores 0, and ₱0.00 read as "free".
                                                 A place quotes its verified band or says
                                                 "Price not listed". --}}
                                            <span>{{ $item->costLabel() }}</span>

                                            {{--
                                                DIRECTIONS FOR THE DRIVE INTO THIS STOP.
                                                `directionsUrl()` returns null for a stop
                                                without both coordinates, and a stop
                                                without a pin already says it cannot be
                                                routed to — a link to a search box is not
                                                directions, so nothing is rendered.

                                                The label is "Directions" and the title
                                                says it is a DRIVING route: this hands
                                                the traveller to a map app's live
                                                estimate under live traffic, which is a
                                                different kind of claim from the
                                                measured leg printed below, and the two
                                                must never read as the same number.
                                            --}}
                                            @php($directions = $item->directionsUrl($previousItem))
                                            @if ($directions)
                                                <a
                                                    href="{{ $directions }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    title="Driving directions from the previous stop — opens Google Maps"
                                                    class="inline-flex items-center gap-1 text-boracay-dark underline decoration-boracay-light underline-offset-2 hover:text-volcanic-teal"
                                                >
                                                    Directions
                                                </a>
                                            @endif

                                            {{-- The road first, the timetable gap second.
                                                 routeSummary() is a MEASURED
                                                 leg. When there is no measured
                                                 leg the gap is still worth
                                                 printing -- it is the space
                                                 between two times on this plan --
                                                 but it is labelled, because the
                                                 number in this slot is the flat
                                                 45 estimate on a trip that has
                                                 never been edited, and an
                                                 unlabelled "45 min travel"
                                                 beside a measured "9.9 km · 28
                                                 min by road" reads as the same
                                                 kind of number. It is not, and
                                                 that difference was the panel's
                                                 complaint. --}}
                                            @if ($item->routeSummary())
                                                <span>
                                                    {{ $item->routeSummary() }} by road
                                                </span>
                                            @elseif ($item->travel_minutes_from_previous > 0)
                                                <span>
                                                    {{ $item->travel_minutes_from_previous }} min gap
                                                    &middot; drive not measured
                                                </span>
                                            @endif
                                        </div>

                                        @if ($item->note)
                                            <p class="mt-3 text-sm italic text-benguet-charcoal/60">
                                                {{ $item->note }}
                                            </p>
                                        @endif

                                        @php($previousItem = $item)
                                    </div>
                                @empty
                                    <div class="rounded-2xl bg-palawan-sand p-5">
                                        <p class="text-sm text-benguet-charcoal/60">
                                            Nothing planned for this day yet. Edit the itinerary and add a stop.
                                        </p>
                                    </div>
                                @endforelse

                                {{-- What is left of the day, in words.
                                     A sentence, deliberately, and never a
                                     utilisation bar: the number only means
                                     something next to a sentence saying what
                                     it is. `freeTimeSentence()` returns null
                                     for a full day and for a day with
                                     nothing planned, so this prints nothing
                                     in both of those cases rather than
                                     claiming a day is tight. --}}
                                @if ($freeTime = $day->freeTimeSentence())
                                    <p data-free-time class="mt-6 text-sm font-semibold text-boracay-dark">
                                        {{ $freeTime }}
                                    </p>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
