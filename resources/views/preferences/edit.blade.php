<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                01 / Set a direction
            </p>

            <h1 class="mt-3 font-display text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal sm:text-6xl">
                Tell us what feels like you.
            </h1>
        </div>
    </x-slot>

    <form method="POST" action="{{ route('preferences.update') }}" class="space-y-8">
        @csrf
        @method('PUT')

        <section class="rounded-[2rem] bg-volcanic-teal p-8 text-white shadow-xl sm:p-12">
            <p class="text-sm leading-7 text-white/70">
                Your choices shape the destination deck and reset your discovery experience when changed.
            </p>

            <div class="mt-8 grid gap-5 md:grid-cols-3">
                <label class="block">
                    <span class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-light">
                        Budget
                    </span>

                    <select
                        name="budget_level"
                        class="mt-3 w-full rounded-xl border-white/20 bg-white/10 text-white focus:border-philippine-gold focus:ring-philippine-gold"
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
                        class="mt-3 w-full rounded-xl border-white/20 bg-white/10 text-white placeholder:text-white/40 focus:border-philippine-gold focus:ring-philippine-gold"
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
                        class="mt-3 w-full rounded-xl border-white/20 bg-white/10 text-white placeholder:text-white/40 focus:border-philippine-gold focus:ring-philippine-gold"
                    >
                </label>
            </div>

            <label class="mt-6 block">
                <span class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-light">
                    Preferred region
                </span>

                <input
                    name="preferred_region"
                    value="{{ old('preferred_region', $profile?->preferred_region) }}"
                    placeholder="Example: Batangas or Central Luzon"
                    class="mt-3 w-full rounded-xl border-white/20 bg-white/10 text-white placeholder:text-white/40 focus:border-philippine-gold focus:ring-philippine-gold"
                >
            </label>
        </section>

        <section class="rounded-[2rem] bg-island-white p-8 shadow-sm ring-1 ring-boracay-light sm:p-12">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.25em] text-boracay-dark">
                        02 / Your signals
                    </p>

                    <h2 class="mt-3 font-display text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal">
                        What do you naturally look for?
                    </h2>

                    <p class="mt-3 max-w-2xl text-sm leading-6 text-benguet-charcoal/65">
                        Mark the interests that matter. Higher numbers mean stronger preference.
                    </p>
                </div>

                <span class="tm-gold-badge">
                    1 low · 2 medium · 3 high
                </span>
            </div>

            <div class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($tags as $tag)
                    <label class="flex items-center justify-between gap-3 rounded-2xl border border-boracay-light bg-palawan-sand p-4 transition hover:border-boracay">
                        <span class="font-semibold text-volcanic-teal">
                            {{ $tag->name }}
                        </span>

                        <select
                            name="weights[{{ $tag->id }}]"
                            class="rounded-lg border-boracay-light bg-white text-sm focus:border-boracay focus:ring-boracay"
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