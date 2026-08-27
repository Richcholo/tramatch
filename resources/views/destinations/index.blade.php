<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Destinations</h2>
    </x-slot>

    <div class="space-y-6">
        <div>
            <p class="text-sm uppercase tracking-wide text-teal-600">Explore Luzon</p>
            <h1 class="mt-1 text-3xl font-bold">Find places worth adding to your trip</h1>
        </div>

        <form method="GET" class="grid gap-3 rounded-2xl bg-white p-4 shadow-sm md:grid-cols-[1fr_180px_auto]">
            <input name="search" value="{{ request('search') }}" placeholder="Search destinations or provinces" class="rounded-lg border-slate-300">
            <select name="budget_level" class="rounded-lg border-slate-300">
                <option value="">All budgets</option>
                @foreach (['economy', 'mid-range', 'premium'] as $budget)
                    <option value="{{ $budget }}" @selected(request('budget_level') === $budget)>{{ ucfirst($budget) }}</option>
                @endforeach
            </select>
            <button class="rounded-lg bg-teal-700 px-5 py-2 font-medium text-white">Search</button>
        </form>

        <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
            @forelse ($destinations as $destination)
                <article class="overflow-hidden rounded-2xl bg-white shadow-sm">
                    @if ($destination->image_url)
                        <img src="{{ $destination->image_url }}" alt="{{ $destination->name }}" class="h-44 w-full object-cover">
                    @else
                        <div class="flex h-44 items-center justify-center bg-teal-50 text-teal-700">{{ $destination->province }}</div>
                    @endif
                    <div class="p-5">
                        <div class="flex items-start justify-between gap-3">
                            <h2 class="text-xl font-semibold">{{ $destination->name }}</h2>
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs capitalize">{{ $destination->budget_level }}</span>
                        </div>
                        <p class="mt-1 text-sm text-slate-500">{{ $destination->municipality }}, {{ $destination->province }}</p>
                        <p class="mt-3 line-clamp-3 text-sm text-slate-600">{{ $destination->description }}</p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach ($destination->tags->take(4) as $tag)
                                <span class="rounded-full bg-teal-50 px-2.5 py-1 text-xs text-teal-700">{{ $tag->name }}</span>
                            @endforeach
                        </div>
                        <a href="{{ route('destinations.show', $destination) }}" class="mt-5 inline-flex font-medium text-teal-700 hover:text-teal-900">View details →</a>
                    </div>
                </article>
            @empty
                <p class="text-slate-600">No destinations match your search.</p>
            @endforelse
        </div>

        {{ $destinations->links() }}
    </div>
</x-app-layout>