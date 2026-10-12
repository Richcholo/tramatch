<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-[0.65rem] font-bold uppercase tracking-[0.24em] text-boracay-dark">
                01 / Set a direction
            </p>

            <h1 class="mt-2 font-display text-3xl font-semibold text-volcanic-teal sm:text-4xl">
                Tell us what feels like you.
            </h1>
        </div>
    </x-slot>

    <form method="POST" action="{{ route('preferences.update') }}" class="space-y-5">
        @csrf
        @method('PUT')

        <section class="rounded-xl bg-volcanic-teal p-5 text-white shadow-md sm:p-6">
            <p class="text-sm leading-6 text-white/70">
                Your choices shape the destination deck and reset your discovery experience when changed.
            </p>

            <div class="mt-5 grid gap-4 md:grid-cols-3">
                <label class="block">
                    <span class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-light">
                        Budget
                    </span>

                    <select
                        name="budget_level"
                        class="mt-2 w-full rounded-lg border-white/20 bg-white/10 px-3 py-2 text-sm text-white focus:border-philippine-gold focus:ring-philippine-gold"
                    >
                        @foreach (['economy', 'mid-range', 'premium'] as $budget)
                            <option
                                value="{{ $budget }}"
                                class="text-benguet-charcoal"
                                @selected(old('budget_level', $profile?->budget_level ?? 'economy') === $budget)
                            >
                                {{ ucfirst($budget) }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-light">
                        Group size
                    </span>

                    <input
                        type="number"
                        name="group_size"
                        min="1"
                        max="50"
                        value="{{ old('group_size', $profile?->group_size ?? 1) }}"
                        class="mt-2 w-full rounded-lg border-white/20 bg-white/10 px-3 py-2 text-sm text-white placeholder:text-white/40 focus:border-philippine-gold focus:ring-philippine-gold"
                    >
                </label>

                <label class="block">
                    <span class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-light">
                        Trip duration
                    </span>

                    <input
                        type="number"
                        name="trip_duration_days"
                        min="1"
                        max="30"
                        value="{{ old('trip_duration_days', $profile?->trip_duration_days ?? 1) }}"
                        class="mt-2 w-full rounded-lg border-white/20 bg-white/10 px-3 py-2 text-sm text-white placeholder:text-white/40 focus:border-philippine-gold focus:ring-philippine-gold"
                    >
                </label>
            </div>

            <label class="mt-4 block">
                <span class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-light">
                    Preferred region
                </span>

                <input
                    id="preferred_region"
                    name="preferred_region"
                    list="preferred-region-options"
                    value="{{ old('preferred_region', $profile?->preferred_region) }}"
                    placeholder="Search or enter a province or municipality"
                    aria-describedby="preferred-region-hint"
                    class="mt-2 w-full rounded-lg border-white/20 bg-white/10 px-3 py-2 text-sm text-white placeholder:text-white/40 focus:border-philippine-gold focus:ring-philippine-gold"
                >
                <datalist id="preferred-region-options">
                    @foreach ($regions as $region)
                        <option value="{{ $region }}"></option>
                    @endforeach
                </datalist>
                <span id="preferred-region-hint" class="mt-2 block text-xs text-white/60">
                    Choose a suggestion or type any specific place.
                </span>
            </label>
        </section>

        <section
            x-data="{
                selectedInterests: [],
                refreshSelectedInterests() {
                    this.selectedInterests = Array.from($el.querySelectorAll('select[name^=weights]'))
                        .filter((select) => Number(select.value) > 0)
                        .map((select) => ({
                            name: select.closest('label').querySelector('span').textContent.trim(),
                            level: ['', 'Low', 'Medium', 'High'][Number(select.value)],
                        }));
                },
            }"
            x-init="refreshSelectedInterests()"
            x-on:change="if ($event.target.matches('select[name^=weights]')) refreshSelectedInterests()"
            class="rounded-xl bg-island-white p-5 shadow-sm ring-1 ring-boracay-light sm:p-6"
        >
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-[0.65rem] font-bold uppercase tracking-[0.2em] text-boracay-dark">
                        02 / Your signals
                    </p>

                    <h2 class="mt-2 font-display text-2xl font-semibold text-volcanic-teal">
                        What do you naturally look for?
                    </h2>

                    <p class="mt-2 max-w-2xl text-sm leading-5 text-benguet-charcoal/65">
                        Mark the interests that matter. Higher numbers mean stronger preference.
                    </p>
                </div>

                <span class="tm-gold-badge">
                    1 low · 2 medium · 3 high
                </span>
            </div>

            <div class="mt-5 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($tags as $tag)
                    <label class="flex items-center justify-between gap-2 rounded-lg border border-boracay-light bg-palawan-sand px-3 py-2 transition hover:border-boracay">
                        <span class="text-sm font-semibold leading-tight text-volcanic-teal">
                            {{ $tag->name }}
                        </span>

                        <select
                            name="weights[{{ $tag->id }}]"
                            class="w-24 shrink-0 rounded-md border-boracay-light bg-white px-2 py-1.5 text-xs focus:border-boracay focus:ring-boracay"
                        >
                            <option value="0">
                                Not selected
                            </option>

                            @foreach ([1, 2, 3] as $weight)
                                <option
                                    value="{{ $weight }}"
                                    @selected((int) old('weights.' . $tag->id, $selectedWeights[$tag->id] ?? 0) === $weight)
                                >
                                    {{ $weight }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
            </div>

            <div class="mt-5 border-t border-boracay-light pt-4" aria-live="polite">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-volcanic-teal">
                        Selected interests
                    </h3>
                    <p class="text-sm text-benguet-charcoal/60">
                        <span x-text="selectedInterests.length">0</span>
                        selected
                    </p>
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    <template x-for="interest in selectedInterests" :key="interest.name">
                        <span class="inline-flex items-center gap-1 rounded-full bg-boracay-light px-2.5 py-1 text-xs text-boracay-dark">
                            <span x-text="interest.name"></span>
                            <span aria-hidden="true" class="text-boracay-dark/50">·</span>
                            <span class="font-semibold" x-text="interest.level"></span>
                        </span>
                    </template>

                    <template x-if="selectedInterests.length === 0">
                        <p class="text-sm text-benguet-charcoal/55">
                            No interests selected yet.
                        </p>
                    </template>
                </div>
            </div>
        </section>

        <div class="flex flex-wrap items-center justify-between gap-4">
            <p class="text-sm text-benguet-charcoal/60">
                Saving new preferences refreshes your discovery deck.
            </p>

            <button
                type="submit"
                class="tm-primary-button rounded-full"
            >
                Save and discover →
            </button>
        </div>
    </form>
</x-app-layout>