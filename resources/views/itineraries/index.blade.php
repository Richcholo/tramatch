<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">My itineraries</h2>
    </x-slot>

    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm uppercase tracking-wide text-teal-600">Saved travel plans</p>
                <h1 class="mt-1 text-3xl font-bold">My itineraries</h1>
            </div>
            <a href="{{ route('itineraries.create') }}" class="rounded-lg bg-teal-700 px-5 py-3 font-medium text-white">Generate new</a>
        </div>

        <div class="space-y-4">
            @forelse ($itineraries as $itinerary)
                <article class="flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-white p-5 shadow-sm">
                    <div>
                        <h2 class="text-xl font-semibold">{{ $itinerary->title }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $itinerary->trip_duration_days }} days · {{ ucfirst($itinerary->budget_level) }} · {{ $itinerary->match_score }}% average match</p>
                    </div>
                    <a href="{{ route('itineraries.show', $itinerary) }}" class="font-medium text-teal-700">Open itinerary →</a>
                </article>
            @empty
                <div class="rounded-2xl bg-white p-6 shadow-sm"><p class="text-slate-600">You have no saved itineraries yet.</p></div>
            @endforelse
        </div>

        {{ $itineraries->links() }}
    </div>
</x-app-layout>