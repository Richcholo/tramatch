@csrf

<div class="grid gap-5 md:grid-cols-2">
    <label class="block md:col-span-2"><span class="text-sm font-medium">Name</span><input name="name" value="{{ old('name', $place->name ?? '') }}" required class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"></label>

    <div class="block">
        <label class="block">
            <span class="text-sm font-medium">Kind</span>
            <select
                name="type"
                class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"
            >
                <option value="food" @selected(old('type', $place->type ?? 'food') === 'food')>Food — where to eat</option>
                <option value="lodging" @selected(old('type', $place->type ?? '') === 'lodging')>Lodging — where to stay</option>
            </select>
        </label>

        <p class="mt-1 text-xs text-slate-500">
            A hotel has a room rate and a check-in time, not a visit length. Filing one as food would schedule it as a one-hour stop.
        </p>
    </div>

    <label class="block">
        <span class="text-sm font-medium">Pilot cluster</span>
        <select
            name="cluster"
            class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"
        >
            <option value="">No cluster — usable anywhere</option>
            @foreach (\App\Models\Place::CLUSTERS as $clusterKey => $clusterDefinition)
                <option value="{{ $clusterKey }}" @selected(old('cluster', $place->cluster ?? '') === $clusterKey)>{{ $clusterDefinition['label'] }}</option>
            @endforeach
        </select>
    </label>

    <label class="block"><span class="text-sm font-medium">Province</span><input name="province" list="place-provinces" value="{{ old('province', $place->province ?? '') }}" required class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"><datalist id="place-provinces">@foreach ($provinces as $province)<option value="{{ $province }}"></option>@endforeach</datalist></label>
    <label class="block"><span class="text-sm font-medium">Municipality</span><input name="municipality" list="place-municipalities" value="{{ old('municipality', $place->municipality ?? '') }}" class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"><datalist id="place-municipalities">@foreach ($municipalities as $municipality)<option value="{{ $municipality }}"></option>@endforeach</datalist></label>
    <label class="block md:col-span-2"><span class="text-sm font-medium">Address</span><input name="address" value="{{ old('address', $place->address ?? '') }}" placeholder="Street, barangay, or landmark" class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"></label>
    <label class="block"><span class="text-sm font-medium">Latitude</span><input name="latitude" type="number" step="any" value="{{ old('latitude', $place->latitude ?? '') }}" required class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"></label>
    <label class="block"><span class="text-sm font-medium">Longitude</span><input name="longitude" type="number" step="any" value="{{ old('longitude', $place->longitude ?? '') }}" required class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"></label>

    {{-- The tier boundaries live on the model so the guideline rendered here and
         the auto-fill in admin-budget-tier.js cannot disagree. Budget tier is an
         exact match against the traveller's profile, not a score, so a wrong tier
         hides the place from two thirds of users rather than ranking it low. --}}
    <div class="block" data-budget-tier>
        <label class="block">
            <span class="text-sm font-medium">Budget</span>
            <select
                name="budget_level"
                data-budget-tier-select
                class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"
            >
                @foreach (\App\Models\Destination::BUDGET_TIERS as $budgetKey => $budgetTier)
                    <option value="{{ $budgetKey }}" @selected(old('budget_level', $place->budget_level ?? 'economy') === $budgetKey)>{{ $budgetTier['label'] }}</option>
                @endforeach
            </select>
        </label>

        <dl class="mt-2 space-y-0.5 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
            @foreach (\App\Models\Destination::budgetTierRanges() as $budgetKey => $budgetRange)
                <div class="flex justify-between gap-3">
                    <dt class="font-medium text-slate-700">{{ \App\Models\Destination::BUDGET_TIERS[$budgetKey]['label'] }}</dt>
                    <dd>{{ $budgetRange }}</dd>
                </div>
            @endforeach
        </dl>

        <p class="mt-1 text-xs text-slate-500">
            Taken from the lowest price below. A hotel room will almost always land in Premium — that is the cost band talking, not a judgment on the hotel.
        </p>

        <p
            data-budget-tier-warning
            hidden
            role="status"
            class="mt-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900"
        ></p>
    </div>

    <label class="block"><span class="text-sm font-medium">Cuisine <span class="text-slate-400">(food only)</span></span><input name="cuisine" value="{{ old('cuisine', $place->cuisine ?? '') }}" placeholder="Filipino, cafe, Ilocano…" class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"></label>

    {{-- A price is a band with a unit, never a bare number. ₱4,500 means nothing
         without "per room per night" beside it — and a band with no figure is
         left blank, which the catalogue reads as "Price not listed" rather than
         free. Both halves travel together or not at all. --}}
    <div class="block md:col-span-2 rounded-lg border border-slate-200 p-4">
        <p class="text-sm font-medium">Price</p>

        <div class="mt-3 grid gap-5 md:grid-cols-3">
            <label class="block"><span class="text-sm font-medium">Lowest (PHP ₱)</span><input name="price_min" type="number" step="0.01" min="0" value="{{ old('price_min', $place->price_min ?? '') }}" data-budget-tier-cost class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"></label>
            <label class="block"><span class="text-sm font-medium">Highest (PHP ₱)</span><input name="price_max" type="number" step="0.01" min="0" value="{{ old('price_max', $place->price_max ?? '') }}" class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"></label>
            <label class="block"><span class="text-sm font-medium">Per</span>
                <select name="price_basis" class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200">
                    <option value="">Not recorded</option>
                    <option value="per_person" @selected(old('price_basis', $place->price_basis ?? '') === 'per_person')>Person (admission, ticket)</option>
                    <option value="per_person_meal" @selected(old('price_basis', $place->price_basis ?? '') === 'per_person_meal')>Person per meal</option>
                    <option value="per_room_night" @selected(old('price_basis', $place->price_basis ?? '') === 'per_room_night')>Room per night</option>
                </select>
            </label>
        </div>

        <p class="mt-2 text-xs text-slate-500">
            Leave all three empty when no price is published. A room rate is never multiplied by group size — six people in one room is still one room.
        </p>
    </div>

    <label class="block"><span class="text-sm font-medium">Minutes to hold <span class="text-slate-400">(0 for a hotel check-in)</span></span><input name="recommended_minutes" type="number" min="0" max="1440" value="{{ old('recommended_minutes', $place->recommended_minutes ?? 60) }}" required class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"></label>
</div>

<div class="mt-6">
    <p class="text-sm font-medium">Meals this place suits <span class="text-slate-400">(food only)</span></p>
    <p class="text-xs text-slate-500">Used to slot a lunch rather than hand a traveler a 10pm dinner stop. Leave all unticked when unknown — an unlisted kitchen can still feed anyone.</p>
    <div class="mt-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
        @foreach (\App\Models\Place::MEAL_SLOTS as $slot)
            {{-- isset(), not ??. A method call on a missing $place (the create
                 page passes no model) throws where a property fetch would
                 quietly fall back — ?? suppresses undefined properties, not
                 undefined method calls. --}}
            <label class="flex items-center gap-2 rounded-lg border p-3"><input type="checkbox" name="meal_slots[]" value="{{ $slot }}" @checked(in_array($slot, old('meal_slots', isset($place) ? $place->normalisedMealSlots() : [])))><span class="capitalize">{{ $slot }}</span></label>
        @endforeach
    </div>
</div>

<p class="mt-5 text-xs text-slate-500">All amounts must be entered in Philippine pesos (PHP / ₱).</p>

<fieldset class="mt-6 rounded-lg border border-slate-200 p-4">
    <legend class="px-2 text-sm font-medium">Opening hours</legend>

    <p class="text-xs text-slate-500">
        Leave both times empty if the hours are not known. A wrong time is worse than
        no time — the catalogue says so honestly rather than guessing.
    </p>

    <div class="mt-3 grid gap-5 md:grid-cols-3">
        <label class="block"><span class="text-sm font-medium">Opens</span><input name="opening_time" type="time" value="{{ old('opening_time', \App\Models\Place::formatTime($place->opening_time ?? null) ?? '') }}" class="mt-2 w-full rounded-lg border-slate-300"></label>
        <label class="block"><span class="text-sm font-medium">Closes</span><input name="closing_time" type="time" value="{{ old('closing_time', \App\Models\Place::formatTime($place->closing_time ?? null) ?? '') }}" class="mt-2 w-full rounded-lg border-slate-300"></label>
        <label class="block"><span class="text-sm font-medium">Operating status</span><select name="operating_status" class="mt-2 w-full rounded-lg border-slate-300">@foreach (['unknown' => 'Unknown', 'open' => 'Open', 'temporarily_closed' => 'Temporarily closed', 'permanently_closed' => 'Permanently closed'] as $value => $label)<option value="{{ $value }}" @selected(old('operating_status', $place->operating_status ?? 'unknown') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label class="block"><span class="text-sm font-medium">Kind of window</span><select name="hours_kind" class="mt-2 w-full rounded-lg border-slate-300">@foreach (['' => 'Normal opening hours', 'always_open' => 'Open 24 hours — ungated', 'per_day' => 'Differs by day', 'registration_window' => 'Registration window, not opening hours', 'reservation_required' => 'Reservation required', 'alert_dependent' => 'Depends on a hazard alert level'] as $value => $text)<option value="{{ $value }}" @selected(old('hours_kind', $place->hours_kind ?? '') === $value)>{{ $text }}</option>@endforeach</select>
        </label>
    </div>

    <p class="mt-5 text-sm font-medium">Closed on</p>
    <div class="mt-2 grid gap-2 sm:grid-cols-4 lg:grid-cols-7">
        @php $checkedDays = old('closed_days', $closedDays ?? []); @endphp
        @foreach (($daySlugs ?? \App\Models\Place::daySlugs()) as $day)
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="closed_days[]" value="{{ $day }}" @checked(in_array($day, (array) $checkedDays, true))><span>{{ ucfirst($day) }}</span></label>
        @endforeach
    </div>

    <div class="mt-6 border-t border-slate-200 pt-4">
        <p class="text-sm font-medium">Different hours per day</p>
        <p class="text-xs text-slate-500">
            Leave a day blank to use the general hours above. Tick
            <em>Closed</em> for a day that is shut.
        </p>

        <div class="mt-3 space-y-2">
            @php $daily = old('daily_hours', $dailyHours ?? []); @endphp
            @foreach (($daySlugs ?? \App\Models\Place::daySlugs()) as $day)
                @php
                    $window = is_array($daily[$day] ?? null) ? $daily[$day] : [];
                    $isClosed = ($window['closed'] ?? false) === true;
                @endphp
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="w-20 font-medium">{{ ucfirst($day) }}</span>
                    <input
                        type="time"
                        name="daily_hours[{{ $day }}][open]"
                        value="{{ \App\Models\Place::formatTime($window['open'] ?? null) ?? '' }}"
                        @disabled($isClosed)
                        class="rounded-lg border-slate-300"
                    >
                    <span class="text-benguet-charcoal/50">to</span>
                    <input
                        type="time"
                        name="daily_hours[{{ $day }}][close]"
                        value="{{ \App\Models\Place::formatTime($window['close'] ?? null) ?? '' }}"
                        @disabled($isClosed)
                        class="rounded-lg border-slate-300"
                    >
                    <label class="flex items-center gap-1 text-xs">
                        <input type="checkbox" name="daily_hours[{{ $day }}][closed]" value="1" @checked($isClosed)>
                        <span>Closed</span>
                    </label>
                </div>
            @endforeach
        </div>
    </div>

    <div class="mt-6 grid gap-5 border-t border-slate-200 pt-4 md:grid-cols-2">
        <label class="block">
            <span class="text-sm font-medium">Source page <span class="text-red-600">*</span></span>
            <input
                name="source_url"
                type="url"
                required
                value="{{ old('source_url', $place->source_url ?? '') }}"
                placeholder="https://example.com/menu-or-rooms"
                class="mt-2 w-full rounded-lg border-slate-300"
            >
            <span class="mt-1 block text-xs text-slate-500">
                Required. A place with no traceable page behind it does not get an entry — nothing else here can vouch for the hours or the price.
            </span>
        </label>

        <label class="block">
            <span class="text-sm font-medium">Source name</span>
            <input
                name="source_label"
                type="text"
                value="{{ old('source_label', $place->source_label ?? '') }}"
                placeholder="Official site, Facebook page…"
                class="mt-2 w-full rounded-lg border-slate-300"
            >
        </label>

        <label class="block md:col-span-2">
            <span class="text-sm font-medium">Note about these hours</span>
            <textarea
                name="hours_note"
                rows="2"
                placeholder="Menu prices from a 2025 review — re-verify."
                class="mt-2 w-full rounded-lg border-slate-300"
            >{{ old('hours_note', $place->hours_note ?? '') }}</textarea>
        </label>
    </div>
</fieldset>

<label class="mt-6 flex items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $place->is_active ?? true))><span class="text-sm">Active and usable in planning</span></label>

<button class="mt-6 rounded-lg bg-teal-700 px-5 py-3 font-medium text-white">Save place</button>
