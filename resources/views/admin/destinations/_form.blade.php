@csrf

<div class="grid gap-5 md:grid-cols-2">
    <label class="block md:col-span-2"><span class="text-sm font-medium">Name</span><input name="name" value="{{ old('name', $destination->name ?? '') }}" required class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"></label>
    <label class="block md:col-span-2"><span class="text-sm font-medium">Description</span><textarea name="description" rows="5" required class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200">{{ old('description', $destination->description ?? '') }}</textarea></label>
    <label class="block"><span class="text-sm font-medium">Province</span><input name="province" list="destination-provinces" value="{{ old('province', $destination->province ?? '') }}" required class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"><datalist id="destination-provinces">@foreach ($provinces as $province)<option value="{{ $province }}"></option>@endforeach</datalist></label>
    <label class="block"><span class="text-sm font-medium">Municipality</span><input name="municipality" list="destination-municipalities" value="{{ old('municipality', $destination->municipality ?? '') }}" class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"><datalist id="destination-municipalities">@foreach ($municipalities as $municipality)<option value="{{ $municipality }}"></option>@endforeach</datalist></label>
    <label class="block"><span class="text-sm font-medium">Latitude</span><input name="latitude" type="number" step="any" value="{{ old('latitude', $destination->latitude ?? '') }}" required class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"></label>
    <label class="block"><span class="text-sm font-medium">Longitude</span><input name="longitude" type="number" step="any" value="{{ old('longitude', $destination->longitude ?? '') }}" required class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"></label>
    {{-- The tier boundaries live on the model so the guideline rendered here and
         the auto-fill in admin-budget-tier.js cannot disagree. Budget tier is an
         exact match against the traveller's profile, not a score, so a wrong tier
         hides the destination from two thirds of users rather than ranking it
         low. --}}
    <div class="block" data-budget-tier>
        <label class="block">
            <span class="text-sm font-medium">Budget</span>
            <select
                name="budget_level"
                data-budget-tier-select
                class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"
            >
                @foreach (\App\Models\Destination::BUDGET_TIERS as $budgetKey => $budgetTier)
                    <option value="{{ $budgetKey }}" @selected(old('budget_level', $destination->budget_level ?? 'economy') === $budgetKey)>{{ $budgetTier['label'] }}</option>
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
            Taken from Estimated cost. This is an exact match, not a ranking, so a destination
            filed under the wrong tier is invisible to travellers in the other two.
        </p>

        {{-- `hidden` the ATTRIBUTE, never a Tailwind `hidden` class. The class is
             display:none at author level and beats the browser's own [hidden] rule,
             so an element carrying both can never be shown by script. Pinned by
             InteractionMarkupTest::test_elements_the_script_toggles_carry_no_hidden_class. --}}
        <p
            data-budget-tier-warning
            hidden
            role="status"
            class="mt-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900"
        ></p>
    </div>
    <label class="block"><span class="text-sm font-medium">Entrance fee (PHP ₱)</span><input name="entrance_fee" type="number" step="0.01" min="0" value="{{ old('entrance_fee', $destination->entrance_fee ?? 0) }}" required class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"></label>
    <label class="block"><span class="text-sm font-medium">Estimated cost (PHP ₱)</span><input name="estimated_cost" type="number" step="0.01" min="0" value="{{ old('estimated_cost', $destination->estimated_cost ?? 0) }}" required data-budget-tier-cost class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"></label>
    <label class="block"><span class="text-sm font-medium">Recommended minutes</span><input name="recommended_minutes" type="number" min="15" value="{{ old('recommended_minutes', $destination->recommended_minutes ?? 120) }}" required class="mt-2 w-full rounded-lg border border-slate-300 outline-none focus:border-teal-700 focus:ring-2 focus:ring-teal-200"></label>
    <div class="block md:col-span-2">
        <span class="text-sm font-medium">Photo</span>

        {{-- Seeded rows carry an absolute URL from the CSV, and the admin form
             no longer has a field for it. Showing what is currently set matters
             because an admin editing only the description must be able to see
             that a photo exists and will survive the save. --}}
        @if ($destination->image_url ?? null)
            <div class="mt-2 flex flex-wrap items-center gap-4 rounded-lg border border-slate-200 p-3">
                <img
                    src="{{ $destination->image_url }}"
                    alt=""
                    class="h-20 w-32 shrink-0 rounded object-cover"
                >
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium">Current photo</p>
                    <p class="mt-1 break-all text-xs text-slate-500">{{ $destination->image_url }}</p>
                </div>
            </div>

            <label class="mt-3 flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remove_image" value="1" class="rounded border-slate-300">
                Remove the current photo
            </label>
        @else
            <p class="mt-2 text-xs text-slate-500">No photo set.</p>
        @endif

        <label class="mt-3 block">
            <span class="text-xs text-slate-500">
                {{ ($destination->image_url ?? null) ? 'Upload a replacement' : 'Upload a photo' }}
            </span>
            <input
                name="image"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                class="mt-2 block w-full text-sm text-slate-600 file:mr-4 file:rounded-lg file:border-0 file:bg-teal-700 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-teal-800"
            >
        </label>

        <span class="mt-2 block text-xs text-slate-500">
            JPEG, PNG or WebP, up to 4 MB. Leave empty to keep the current photo.
        </span>
    </div>
</div>

<p class="mt-5 text-xs text-slate-500">All amounts must be entered in Philippine pesos (PHP / ₱).</p>

<fieldset class="mt-6 rounded-lg border border-slate-200 p-4">
    <legend class="px-2 text-sm font-medium">Opening hours</legend>

    <p class="text-xs text-slate-500">
        Leave both times empty if the hours are not known. A wrong time is worse than
        no time — the public page says so honestly rather than guessing.
    </p>

    <div class="mt-3 grid gap-5 md:grid-cols-3">
        {{-- formatTime(), not the raw attribute. These are MySQL TIME columns, so
             $destination->opening_time is "09:00:00" and the browser would post
             that back untouched, which date_format:H:i rejects. Every edit to a
             destination with hours failed because of it. --}}
        <label class="block"><span class="text-sm font-medium">Opens</span><input name="opening_time" type="time" value="{{ old('opening_time', \App\Models\Destination::formatTime($destination->opening_time ?? null) ?? '') }}" class="mt-2 w-full rounded-lg border-slate-300"></label>
        <label class="block"><span class="text-sm font-medium">Closes</span><input name="closing_time" type="time" value="{{ old('closing_time', \App\Models\Destination::formatTime($destination->closing_time ?? null) ?? '') }}" class="mt-2 w-full rounded-lg border-slate-300"></label>
        <label class="block"><span class="text-sm font-medium">Operating status</span><select name="operating_status" class="mt-2 w-full rounded-lg border-slate-300">@foreach (['unknown' => 'Unknown', 'open' => 'Open', 'temporarily_closed' => 'Temporarily closed', 'permanently_closed' => 'Permanently closed'] as $value => $label)<option value="{{ $value }}" @selected(old('operating_status', $destination->operating_status ?? 'unknown') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label class="block"><span class="text-sm font-medium">Kind of window</span><select name="hours_kind" class="mt-2 w-full rounded-lg border-slate-300">@foreach (['' => 'Normal opening hours', 'always_open' => 'Open 24 hours — ungated', 'per_day' => 'Differs by day', 'registration_window' => 'Registration window, not opening hours', 'reservation_required' => 'Reservation required', 'alert_dependent' => 'Depends on a hazard alert level'] as $value => $text)<option value="{{ $value }}" @selected(old('hours_kind', $destination->hours_kind ?? '') === $value)>{{ $text }}</option>@endforeach</select>
            <span class="mt-1 block text-xs text-slate-500">Shown under the hours on the public page, so a registration window is never read as "you can walk in at 5am".</span>
        </label>
    </div>

    <p class="mt-5 text-sm font-medium">Closed on</p>
    <div class="mt-2 grid gap-2 sm:grid-cols-4 lg:grid-cols-7">
        @php $checkedDays = old('closed_days', $closedDays ?? []); @endphp
        @foreach (($daySlugs ?? \App\Models\Destination::daySlugs()) as $day)
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="closed_days[]" value="{{ $day }}" @checked(in_array($day, (array) $checkedDays, true))><span>{{ ucfirst($day) }}</span></label>
        @endforeach
    </div>

    <div class="mt-6 border-t border-slate-200 pt-4">
        <p class="text-sm font-medium">Different hours per day</p>
        <p class="text-xs text-slate-500">
            Leave a day blank to use the general hours above. Tick
            <em>Closed</em> for a day that is shut. Needed when weekdays and
            weekends differ, which is common for Intramuros sites.
        </p>

        <div class="mt-3 space-y-2">
            @php $daily = old('daily_hours', $dailyHours ?? []); @endphp
            @foreach (($daySlugs ?? \App\Models\Destination::daySlugs()) as $day)
                @php
                    $window = is_array($daily[$day] ?? null) ? $daily[$day] : [];
                    $isClosed = ($window['closed'] ?? false) === true;
                @endphp
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="w-20 font-medium">{{ ucfirst($day) }}</span>
                    <input
                        type="time"
                        name="daily_hours[{{ $day }}][open]"
                        value="{{ \App\Models\Destination::formatTime($window['open'] ?? null) ?? '' }}"
                        @disabled($isClosed)
                        class="rounded-lg border-slate-300"
                    >
                    <span class="text-benguet-charcoal/50">to</span>
                    <input
                        type="time"
                        name="daily_hours[{{ $day }}][close]"
                        value="{{ \App\Models\Destination::formatTime($window['close'] ?? null) ?? '' }}"
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
            <span class="text-sm font-medium">Where these hours came from</span>
            <input
                name="hours_source_url"
                type="url"
                value="{{ old('hours_source_url', $destination->hours_source_url ?? '') }}"
                placeholder="https://example.gov.ph/visiting-hours"
                class="mt-2 w-full rounded-lg border-slate-300"
            >
            <span class="mt-1 block text-xs text-slate-500">
                Shown to travelers under the hours so they can check it.
            </span>
        </label>

        <label class="block">
            <span class="text-sm font-medium">Source name</span>
            <input
                name="hours_source_label"
                type="text"
                value="{{ old('hours_source_label', $destination->hours_source_label ?? '') }}"
                placeholder="Official city tourism page"
                class="mt-2 w-full rounded-lg border-slate-300"
            >
        </label>

        <label class="block md:col-span-2">
            <span class="text-sm font-medium">Note about these hours</span>
            <textarea
                name="hours_note"
                rows="2"
                placeholder="Last entry is one hour before closing. Holiday hours differ."
                class="mt-2 w-full rounded-lg border-slate-300"
            >{{ old('hours_note', $destination->hours_note ?? '') }}</textarea>
        </label>
    </div>
</fieldset>

<div class="mt-6">
    <p class="text-sm font-medium">Tags</p>
    <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($tags as $tag)
            <label class="flex items-center gap-2 rounded-lg border p-3"><input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked(in_array($tag->id, old('tags', $selectedTags ?? [])))><span>{{ $tag->name }}</span></label>
        @endforeach
    </div>
</div>

<label class="mt-6 flex items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $destination->is_active ?? true))><span class="text-sm">Active and visible to travelers</span></label>

<button class="mt-6 rounded-lg bg-teal-700 px-5 py-3 font-medium text-white">Save destination</button>