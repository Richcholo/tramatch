<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">My travel preferences</h2>
    </x-slot>

    <form method="POST" action="{{ route('preferences.update') }}" class="space-y-8">
        @csrf
        @method('PUT')

        <section class="rounded-2xl bg-white p-6 shadow-sm">
            <h1 class="text-2xl font-bold">Tell TraMatch what you enjoy</h1>
            <p class="mt-2 text-slate-600">These choices control the destinations shown in your recommendations.</p>

            <div class="mt-6 grid gap-5 md:grid-cols-3">
                <label class="block">
                    <span class="text-sm font-medium">Budget</span>
                    <select name="budget_level" class="mt-2 w-full rounded-lg border-slate-300">
                        @foreach (['economy', 'mid-range', 'premium'] as $budget)
                            <option value="{{ $budget }}" @selected(old('budget_level', $profile?->budget_level ?? 'economy') === $budget)>{{ ucfirst($budget) }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-sm font-medium">Group size</span>
                    <input type="number" name="group_size" min="1" max="50" value="{{ old('group_size', $profile?->group_size ?? 1) }}" class="mt-2 w-full rounded-lg border-slate-300">
                </label>

                <label class="block">
                    <span class="text-sm font-medium">Trip duration in days</span>
                    <input type="number" name="trip_duration_days" min="1" max="30" value="{{ old('trip_duration_days', $profile?->trip_duration_days ?? 1) }}" class="mt-2 w-full rounded-lg border-slate-300">
                </label>
            </div>

            <label class="mt-5 block">
                <span class="text-sm font-medium">Preferred region or province</span>
                <input name="preferred_region" value="{{ old('preferred_region', $profile?->preferred_region) }}" placeholder="Example: Batangas or Central Luzon" class="mt-2 w-full rounded-lg border-slate-300">
            </label>
        </section>

        <section class="rounded-2xl bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold">Travel interests</h2>
                    <p class="mt-1 text-sm text-slate-500">Choose an importance level for each interest.</p>
                </div>
                <p class="text-xs text-slate-500">1 low · 2 medium · 3 high</p>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($tags as $tag)
                    <label class="flex items-center justify-between gap-3 rounded-xl border p-4">
                        <span class="font-medium">{{ $tag->name }}</span>
                        <select name="weights[{{ $tag->id }}]" class="rounded-lg border-slate-300 text-sm">
                            <option value="0">Not selected</option>
                            @foreach ([1, 2, 3] as $weight)
                                <option value="{{ $weight }}" @selected((int) old('weights.' . $tag->id, $selectedWeights[$tag->id] ?? 0) === $weight)>{{ $weight }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
            </div>
        </section>

        <button class="rounded-lg bg-teal-700 px-6 py-3 font-medium text-white hover:bg-teal-800">Save preferences</button>
    </form>
</x-app-layout>