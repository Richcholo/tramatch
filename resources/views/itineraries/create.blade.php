<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Generate itinerary</h2>
    </x-slot>

    <div class="mx-auto max-w-2xl">
        <form method="POST" action="{{ route('itineraries.store') }}" class="rounded-2xl bg-white p-6 shadow-sm">
            @csrf
            <p class="text-sm uppercase tracking-wide text-teal-600">One more step</p>
            <h1 class="mt-1 text-3xl font-bold">Build your {{ $profile->trip_duration_days }}-day trip</h1>
            <p class="mt-3 text-slate-600">TraMatch will select destinations that match your {{ $profile->budget_level }} budget and saved interests.</p>

            <label class="mt-6 block">
                <span class="text-sm font-medium">Itinerary title</span>
                <input name="title" value="{{ old('title', 'My TraMatch trip') }}" required class="mt-2 w-full rounded-lg border-slate-300">
            </label>

            <label class="mt-5 block">
                <span class="text-sm font-medium">Start date</span>
                <input type="date" name="start_date" value="{{ old('start_date') }}" min="{{ now()->toDateString() }}" class="mt-2 w-full rounded-lg border-slate-300">
            </label>

            <div class="mt-6 grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs text-slate-500">Budget</p><p class="mt-1 font-semibold capitalize">{{ $profile->budget_level }}</p></div>
                <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs text-slate-500">Group size</p><p class="mt-1 font-semibold">{{ $profile->group_size }}</p></div>
                <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs text-slate-500">Duration</p><p class="mt-1 font-semibold">{{ $profile->trip_duration_days }} day(s)</p></div>
            </div>

            <button class="mt-6 rounded-lg bg-teal-700 px-5 py-3 font-medium text-white hover:bg-teal-800">Generate itinerary</button>
        </form>
    </div>
</x-app-layout>