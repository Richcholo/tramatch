{{--
    The SHARED half of an itinerary page.

    The hero, the unpriced-total notice, the "not scheduled" warnings block,
    the map, and THAT IS IT. It is included by both the owner's editor page
    (itineraries/show.blade.php) and the public share page
    (itineraries/shared.blade.php), so the two views cannot drift: a fix to a
    total, a label or the map here is a fix on both.

    It renders nothing that writes, posts or toggles. The days grid is NOT
    here -- it is `partials/days.blade.php`, a separate include, because the
    owner's editor reads and writes around its own section header that this
    file deliberately does not carry.
--}}

    @php
        $mapPoints = $itinerary->days
            ->flatMap(function ($day) {
                return $day->items
                    ->filter(fn ($item) => $item->hasKnownLocation())
                    ->map(function ($item) use ($day) {
                        return [
                            'day' => $day->day_number,
                            'name' => $item->placeName(),
                            'lat' => $item->latitude(),
                            'lng' => $item->longitude(),
                        ];
                    });
            })
            ->values()
            ->toArray();
    @endphp

        <section class="relative overflow-hidden rounded-[2rem] bg-volcanic-teal p-8 text-white shadow-xl sm:p-12">
            <div class="absolute -right-24 -top-24 h-96 w-96 rounded-full bg-boracay/25 blur-3xl"></div>
            <div class="absolute -bottom-32 left-1/3 h-80 w-80 rounded-full bg-philippine-gold/15 blur-3xl"></div>

            <div class="relative flex flex-wrap items-end justify-between gap-8">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.3em] text-boracay-light">
                        {{ ucfirst($itinerary->budget_level) }} trip
                    </p>

                    @if ($itinerary->area)
                        <p class="mt-3 text-sm font-semibold uppercase tracking-[0.22em] text-philippine-gold">
                            {{ $itinerary->area }}
                        </p>
                    @endif

                    <h1 class="mt-5 max-w-4xl font-display text-5xl font-semibold leading-[0.95] tracking-[-0.05em] sm:text-7xl">
                        {{ $itinerary->title }}
                    </h1>

                    <p class="mt-6 text-white/65">
                        {{ $itinerary->trip_duration_days }} day(s)
                        · {{ $itinerary->match_score }}% average match
                        · ₱{{ number_format($itinerary->total_estimated_cost, 2) }}
                    </p>

                    {{-- The total sums stored figures, and an unpriced stop stores
                         0. Without this line ₱8,375 reads as a complete answer
                         when three of the stops were counted at zero. {{--
    The SHARED half of an itinerary page.

    The hero, the unpriced-total notice, the "not scheduled" warnings block,
    the map, and THAT IS IT. It is included by both the owner's editor page
    (itineraries/show.blade.php) and the public share page
    (itineraries/shared.blade.php), so the two views cannot drift: a fix to a
    total, a label or the map here is a fix on both.

    It renders nothing that writes, posts or toggles. The days grid is NOT
    here -- it is `partials/days.blade.php`, a separate include, because the
    owner's editor reads and writes around its own section header that this
    file deliberately does not carry.
--}}
                    @if ($unpricedStops > 0)
                        <p class="mt-2 text-sm font-semibold text-philippine-gold">
                            {{ $unpricedStops }} stop(s) without a verified price — the total above is incomplete.
                        </p>
                    @endif
                </div>

                {{-- OWNER ACTIONS, and that is precisely why they are guarded
                     here: this partial renders for guests on the share page
                     too, and a share recipient has no business seeing — let
                     alone rendering —— controls that complete or delete a
                     trip they cannot prove they own. The owner's editor page
                     gets the identical block because it is the same file. --}}
                @auth
                    <div class="flex flex-wrap gap-3">
                    <form
                        method="POST"
                        action="{{ route('itineraries.complete', $itinerary) }}"
                    >
                        @csrf
                        @method('PATCH')

                        <button
                            type="submit"
                            class="rounded-full border border-white/35 px-5 py-3 text-sm font-semibold text-white transition hover:bg-white hover:text-volcanic-teal"
                        >
                            {{ $itinerary->is_completed ? 'Mark active' : 'Mark completed' }}
                        </button>
                    </form>

                    <button
                        type="button"
                        onclick="document.getElementById('delete-itinerary-confirmation').showModal()"
                        class="rounded-full bg-red-500/15 px-5 py-3 text-sm font-semibold text-red-200 transition hover:bg-red-500 hover:text-white"
                    >
                        Delete
                    </button>
                    </div>
                @endauth
            </div>
        </section>

        @if (session('itinerary_warnings'))
            <section class="rounded-[2rem] border border-philippine-gold/50 bg-philippine-gold/15 p-6 text-benguet-charcoal sm:p-8">
                <p class="text-xs font-bold uppercase tracking-[0.25em] text-boracay-dark">
                    Planning notes
                </p>

                <h2 class="mt-4 font-display text-3xl font-semibold text-volcanic-teal">
                    Some selected places were not scheduled.
                </h2>

                <p class="mt-3 text-sm leading-6 text-benguet-charcoal/70">
                    The itinerary only saved destinations that fit the daily time window.
                </p>

                <ul class="mt-5 space-y-3">
                    @foreach (session('itinerary_warnings') as $warning)
                        <li class="rounded-xl bg-white/60 p-4 text-sm">
                            <strong class="text-volcanic-teal">
                                {{ $warning['name'] }}
                            </strong>

                            <span class="text-benguet-charcoal/70">
                                — {{ $warning['reason'] }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="rounded-[2rem] bg-island-white p-4 shadow-sm ring-1 ring-boracay-light sm:p-6">
            <div
                id="itinerary-map"
                data-itinerary-points="{{ json_encode($mapPoints) }}"
                class="h-[28rem] rounded-[1.5rem] bg-boracay-light"
            ></div>
        </section>
