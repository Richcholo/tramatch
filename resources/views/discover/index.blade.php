<x-app-layout>
    <div class="mx-auto max-w-md">
        <div class="mb-4 flex items-center justify-between gap-4">
            <p class="text-sm font-semibold text-boracay-dark"><span data-swipe-count>{{ $cards->count() }}</span> places left</p>
            <form method="POST" action="{{ route('discover.reset') }}" onsubmit="return confirm('Start the discovery deck again?')">
                @csrf
                <button class="text-sm font-medium text-boracay-dark hover:underline">Reset deck</button>
            </form>
        </div>

        @if ($cards->isEmpty())
            <div data-swipe-empty class="tm-card p-8 text-center">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-philippine-gold/25 text-3xl text-benguet-charcoal">★</div>
                <h1 class="mt-5 text-2xl font-bold text-volcanic-teal">You are all caught up.</h1>
                <p class="mt-3 text-benguet-charcoal/75">Reset the deck to discover these places again.</p>
                @if (Route::has('recommendations.index'))
                    <a href="{{ route('recommendations.index') }}" class="tm-primary-button mt-6">View liked places</a>
                @endif
            </div>
        @else
            <div data-swipe-deck data-endpoint="{{ route('discover.swipes.store') }}" class="relative h-[600px] w-full">
                @foreach ($cards as $destination)
                    <article data-swipe-card data-destination-id="{{ $destination->id }}" class="absolute inset-0 overflow-hidden rounded-3xl bg-island-white shadow-xl ring-1 ring-boracay-light">
                        @if ($destination->image_url)
                            <img src="{{ $destination->image_url }}" alt="{{ $destination->name }}" class="h-60 w-full object-cover">
                        @else
                            <div class="flex h-60 items-center justify-center bg-boracay-light text-2xl font-bold text-boracay-dark">{{ $destination->province }}</div>
                        @endif

                        <div class="flex h-[calc(100%-15rem)] flex-col p-6">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm text-slate-500">{{ $destination->municipality }}, {{ $destination->province }}</p>
                                    <h2 class="mt-1 text-2xl font-bold text-volcanic-teal">{{ $destination->name }}</h2>
                                </div>
                                <span class="tm-gold-badge">{{ $destination->preference_score }}%</span>
                            </div>

                            <p class="mt-4 line-clamp-5 text-sm leading-6 text-benguet-charcoal/75">{{ $destination->description }}</p>

                            <div class="mt-4 flex flex-wrap gap-2">
                                @foreach ($destination->tags->take(5) as $tag)
                                    <span class="rounded-full bg-boracay-light px-2.5 py-1 text-xs text-boracay-dark">{{ $tag->name }}</span>
                                @endforeach
                            </div>

                            <div class="mt-auto flex items-center gap-3 pt-6">
                                <button type="button" data-swipe-action="passed" class="flex-1 rounded-xl border border-red-200 px-4 py-3 font-semibold text-red-600 hover:bg-red-50">Pass</button>
                                <button type="button" data-swipe-action="liked" class="flex-1 rounded-xl bg-boracay px-4 py-3 font-semibold text-benguet-charcoal hover:bg-boracay-dark hover:text-white">Like</button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>