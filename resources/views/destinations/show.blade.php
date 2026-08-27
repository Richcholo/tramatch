<x-app-layout>
    <x-slot name="header">
        <a href="{{ route('destinations.index') }}" class="text-sm text-teal-700">← Back to destinations</a>
    </x-slot>

    <div class="grid gap-8 lg:grid-cols-[1.3fr_0.7fr]">
        <section class="rounded-2xl bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-sm text-slate-500">{{ $destination->municipality }}, {{ $destination->province }}</p>
                    <h1 class="mt-1 text-3xl font-bold">{{ $destination->name }}</h1>
                </div>
                <span class="rounded-full bg-teal-50 px-3 py-1 text-sm capitalize text-teal-700">{{ $destination->budget_level }}</span>
            </div>

            <p class="mt-6 leading-7 text-slate-600">{{ $destination->description }}</p>

            <div class="mt-6 grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs text-slate-500">Entrance fee</p><p class="mt-1 font-semibold">₱{{ number_format($destination->entrance_fee, 2) }}</p></div>
                <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs text-slate-500">Estimated cost</p><p class="mt-1 font-semibold">₱{{ number_format($destination->estimated_cost, 2) }}</p></div>
                <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs text-slate-500">Recommended stay</p><p class="mt-1 font-semibold">{{ $destination->recommended_minutes }} minutes</p></div>
            </div>

            <div class="mt-6 flex flex-wrap gap-2">
                @foreach ($destination->tags as $tag)
                    <span class="rounded-full bg-teal-50 px-3 py-1 text-sm text-teal-700">{{ $tag->name }}</span>
                @endforeach
            </div>
        </section>

        <aside class="space-y-5">
            <div class="rounded-2xl bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold">Location</h2>
                <div class="mt-4 h-64 rounded-xl bg-slate-100" data-destination-map data-lat="{{ $destination->latitude }}" data-lng="{{ $destination->longitude }}" data-name="{{ $destination->name }}"></div>
                <p class="mt-3 text-xs text-slate-500">{{ $destination->latitude }}, {{ $destination->longitude }}</p>
            </div>

            <div class="rounded-2xl bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold">Reviews</h2>
                <p class="mt-1 text-sm text-slate-500">{{ $destination->reviews->count() }} published review(s)</p>
                <div class="mt-4 space-y-4">
                    @forelse ($destination->reviews->take(3) as $review)
                        <div class="border-t pt-4">
                            <div class="flex justify-between gap-3"><strong>{{ $review->user->name }}</strong><span>{{ $review->rating }}/5</span></div>
                            <p class="mt-1 text-sm text-slate-600">{{ $review->comment }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">No reviews yet.</p>
                    @endforelse
                </div>
            </div>
        </aside>
    </div>
</x-app-layout>