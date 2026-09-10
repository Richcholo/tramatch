<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Admin dashboard</h2>
    </x-slot>

    <div class="space-y-6">
        <div>
            <p class="text-sm uppercase tracking-wide text-teal-600">TraMatch management</p>
            <h1 class="mt-1 text-3xl font-bold">System overview</h1>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">Destinations</p><p class="mt-2 text-3xl font-bold">{{ $destinationCount }}</p></div>
            <div class="rounded-2xl bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">Tags</p><p class="mt-2 text-3xl font-bold">{{ $tagCount }}</p></div>
            <div class="rounded-2xl bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">Reviews</p><p class="mt-2 text-3xl font-bold">{{ $reviewCount }}</p></div>
            <div class="rounded-2xl bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">Pending reviews</p><p class="mt-2 text-3xl font-bold">{{ $pendingReviewCount }}</p></div>
        </div>

        <a href="{{ route('admin.destinations.index') }}" class="inline-flex rounded-lg bg-teal-700 px-5 py-3 font-medium text-white">Manage destinations</a>
    </div>
</x-app-layout>