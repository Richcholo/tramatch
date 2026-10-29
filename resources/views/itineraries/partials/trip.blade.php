{{--
    The SHARED half of an itinerary page.

    The hero, the total, the unpriced-total notice, THE PESO VERDICT, the
    "not scheduled" warnings block, the map, and THAT IS IT. It is included by
    both the owner's editor page (itineraries/show.blade.php) and the public
    share page (itineraries/shared.blade.php), so the two views cannot drift:
    a fix to a total, a label or the map here is a fix to both.

    ONE EXCEPTION TO "IT RENDERS NOTHING THAT WRITES", and it is worth
    naming rather than discovering later: the budget field is a real form in
    here, `@auth`-guarded, because the ceiling it edits is a money fact that
    belongs beside the total it is compared against — and the guard is what
    keeps the shared page's zero-forms invariant true. If you add a second
    write to this partial, it needs the same guard and the same reasoning.

    The days grid is NOT here -- it is `partials/days.blade.php`, a separate
    include, because the owner's editor reads and writes around its own
    section header that this file deliberately does not carry.
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
                        Trip duration: {{ $itinerary->trip_duration_days }} {{ $itinerary->trip_duration_days === 1 ? 'day' : 'days' }}
                        · {{ $itinerary->match_score }}% average match
                        · ₱{{ number_format($itinerary->total_estimated_cost, 2) }}
                    </p>

                    {{-- The total sums stored figures, and an unpriced stop stores
                         0. Without this line ₱8,375 reads as a complete answer
                         when three of the stops were counted at zero. --}}
                    @if ($unpricedStops > 0)
                        <p class="mt-2 text-sm font-semibold text-philippine-gold">
                            {{ $unpricedStops }} stop(s) without a verified price — the total above is incomplete.
                        </p>
                    @endif

                    {{-- THE PESO VERDICT, or its absence. `budgetCheck()` returns
                         null until the traveller states a ceiling, and null
                         renders NOTHING — not "₱0 of ₱0" — because "no budget
                         set" is a real state and a budget of zero is not a
                         budget. It lives here, in the SHARED hero, so the
                         owner and every guest of the public link read the same
                         sentence about the same trip. The wording says "in
                         verified prices so far" precisely because the total
                         above counts unpriced stops as zero; the notice right
                         above this line is where the count is printed, so the
                         number is not restated here. --}}
                    @php($budget = $itinerary->budgetCheck())
                    @if ($budget)
                        <p @class([
                            'mt-3 text-sm font-semibold',
                            'text-red-300' => $budget['over'],
                            'text-white/80' => ! $budget['over'],
                        ])>
                            @if ($budget['over'])
                                Against your ₱{{ number_format($budget['amount'], 2) }} budget:
                                ₱{{ number_format($budget['known'], 2) }} in verified prices so far —
                                already ₱{{ number_format(abs($budget['remaining']), 2) }} over.
                            @else
                                Against your ₱{{ number_format($budget['amount'], 2) }} budget:
                                ₱{{ number_format($budget['known'], 2) }} in verified prices so far,
                                leaving ₱{{ number_format($budget['remaining'], 2) }}.
                            @endif
                        </p>
                    @endif

                    {{-- WHAT THE AVERAGE MATCH NUMBER IS. A bare "62.3% average
                         match" is the black box this sentence opens; the stops'
                         own scores are NOT stored, so it explains the SCALE and
                         refuses to invent a per-stop breakdown.

                         OWNER ONLY, and the guard is load-bearing for privacy
                         rather than tidiness: the percentage itself stays
                         visible to a share guest (it is already on this shared
                         hero), but the sentence describes the traveller's own
                         interest weights, which are nobody else's to read. --}}
                    @auth
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-white/60">
                            {{ app(\App\Domain\Matches\MatchScore::class)->sentenceForAverage($itinerary) }}
                        </p>
                    @endauth

                    {{-- OWNER ONLY, and the guard is load-bearing: this partial
                         renders for guests on the public share link, and the
                         shared page contributes ZERO forms (ItineraryShareTest
                         pins that), so a budget field here without @auth would
                         both leak a control to strangers and break that
                         invariant.

                         IT RENDERS WHETHER OR NOT A BUDGET IS SET, which is the
                         whole point of the control: it is how a ceiling gets
                         set in the first place. The first version wrapped the
                         form in `@if ($budget)` alongside the verdict and the
                         only way to reach a budget became already having one.
                         The verdict is conditional; the FIELD IS NOT. --}}
                    @auth
                        <form method="POST" action="{{ route('itineraries.budget.update', $itinerary) }}" class="mt-4 flex flex-wrap items-end gap-2">
                            @csrf
                            @method('PATCH')

                            <div>
                                <label for="budget_amount" class="block text-xs font-bold uppercase tracking-[0.2em] text-white/60">
                                    Budget for this trip (₱)
                                </label>
                                <input
                                    id="budget_amount"
                                    name="budget_amount"
                                    type="number"
                                    step="0.01"
                                    min="1"
                                    max="10000000"
                                    inputmode="decimal"
                                    value="{{ old('budget_amount', $itinerary->budget_amount) }}"
                                    placeholder="e.g. 15000"
                                    class="mt-2 min-h-11 w-44 rounded-lg border border-white/25 bg-white/10 px-3 py-2 text-base text-white placeholder:text-white/40 focus:border-boracay-light focus:outline-none focus:ring-2 focus:ring-boracay"
                                >
                            </div>

                            <button
                                type="submit"
                                class="inline-flex min-h-11 items-center justify-center rounded-lg border border-boracay-light px-5 py-3 text-sm font-semibold text-white transition hover:bg-white/10"
                            >
                                Save budget
                            </button>

                            <p class="w-full text-xs text-white/55">
                                Clear the field and save to remove the ceiling. It is your figure —
                                this app does not guess one from the trip's budget level.
                            </p>
                        </form>
                    @endauth
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
