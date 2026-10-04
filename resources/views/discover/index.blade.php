<x-app-layout>
    <div class="relative isolate overflow-hidden py-10 sm:py-12">
        <header class="mb-8 border-b border-boracay-light px-5 pb-6 sm:px-8 lg:px-12">
            <div class="mx-auto flex max-w-6xl flex-col justify-between gap-6 sm:flex-row sm:items-end">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.25em] text-boracay-dark">
                    Your next escape
                </p>
                <h1 class="mt-3 max-w-2xl font-display text-4xl font-semibold leading-tight text-volcanic-teal sm:text-5xl">
                    Find your next favorite.
                </h1>
            </div>

            <div class="flex flex-wrap items-center gap-4 sm:justify-end">
                <p class="rounded-full bg-island-white px-4 py-2 text-sm font-semibold text-volcanic-teal ring-1 ring-boracay-light">
                    <span data-swipe-count>{{ $cards->count() }}</span>
                    <span class="ml-1 text-benguet-charcoal/65">{{ $cards->count() === 1 ? 'place' : 'places' }} left</span>
                </p>
                <button type="button" onclick="document.getElementById('reset-discovery-confirmation').showModal()" class="min-h-10 rounded-md px-3 py-2 text-sm font-semibold text-boracay-dark transition hover:bg-boracay-light hover:text-volcanic-teal focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-volcanic-teal">
                    Reset deck
                </button>
            </div>
            </div>
        </header>

        <dialog
            id="reset-discovery-confirmation"
            aria-labelledby="reset-discovery-confirmation-title"
            aria-describedby="reset-discovery-confirmation-description"
            onclick="if (event.target === this) this.close()"
            class="m-auto w-[calc(100%-2rem)] max-w-md rounded-lg border border-boracay-light bg-palawan-sand p-0 text-benguet-charcoal shadow-2xl backdrop:bg-volcanic-teal/60 backdrop:backdrop-blur-sm"
        >
            <div class="p-6 sm:p-7">
                <div class="flex items-start gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-boracay-light text-boracay-dark" aria-hidden="true">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12a7.5 7.5 0 0112.8-5.3L20 9m0 0V4m0 5h-5m4.5 3a7.5 7.5 0 01-12.8 5.3L4 15m0 0v5m0-5h5" />
                        </svg>
                    </span>
                    <div>
                        <h2 id="reset-discovery-confirmation-title" class="font-display text-xl font-semibold text-volcanic-teal">
                            Reset your discovery deck?
                        </h2>
                        <p id="reset-discovery-confirmation-description" class="mt-2 text-sm leading-6 text-benguet-charcoal/70">
                            Your previous passes and likes will be cleared so you can start fresh.
                        </p>
                    </div>
                </div>

                <form method="POST" action="{{ route('discover.reset') }}" class="mt-7 flex justify-end gap-3">
                    @csrf
                    <button type="button" autofocus onclick="this.closest('dialog').close()" class="rounded-full border border-boracay-light px-4 py-2 text-sm font-semibold text-benguet-charcoal transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-boracay focus:ring-offset-2">
                        Cancel
                    </button>
                    <button type="submit" class="rounded-full bg-boracay px-4 py-2 text-sm font-bold text-benguet-charcoal transition hover:bg-boracay-dark hover:text-white focus:outline-none focus:ring-2 focus:ring-boracay focus:ring-offset-2">
                        Reset deck
                    </button>
                </form>
            </div>
        </dialog>

        <div class="mx-auto max-w-6xl px-5 sm:px-8 lg:px-12">
        @if ($cards->isEmpty())
            <section data-swipe-empty class="mx-auto max-w-3xl border-y border-boracay-light bg-island-white px-6 py-14 text-center sm:px-12 sm:py-20">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-philippine-gold/20 text-2xl font-semibold text-volcanic-teal" aria-hidden="true">
                    &#10022;
                </div>
                <p class="mt-6 text-xs font-bold uppercase tracking-[0.22em] text-boracay-dark">
                    That is a wrap
                </p>
                <h2 class="mt-3 font-display text-3xl font-semibold text-volcanic-teal sm:text-4xl">
                    You are all caught up.
                </h2>
                <p class="mx-auto mt-3 max-w-md text-sm leading-6 text-benguet-charcoal/65">
                    Your deck is clear. Reset it to take another look at these places.
                </p>
                @if (Route::has('recommendations.index'))
                    <a href="{{ route('recommendations.index') }}" class="tm-primary-button mt-7">
                        View liked places
                    </a>
                @endif
            </section>
        @else
            <div data-swipe-deck data-endpoint="{{ route('discover.swipes.store') }}" class="relative mx-auto h-[660px] w-full max-w-xl touch-pan-y sm:h-[680px]">
                @foreach ($cards as $destination)
                    <article data-swipe-card data-destination-id="{{ $destination->id }}" class="absolute inset-0 flex touch-pan-y select-none cursor-grab flex-col overflow-hidden rounded-2xl bg-island-white shadow-xl ring-1 ring-boracay-light active:cursor-grabbing">
                        @if ($destination->image_url)
                            <div class="relative h-64 shrink-0 overflow-hidden sm:h-[18rem]">
                                <img src="{{ $destination->image_url }}" alt="{{ $destination->name }}" class="h-full w-full object-cover">
                                <div class="absolute inset-x-0 bottom-0 h-28 bg-gradient-to-t from-benguet-charcoal/65 to-transparent" aria-hidden="true"></div>
                                <p class="absolute bottom-4 left-5 max-w-[70%] text-sm font-medium text-white drop-shadow sm:left-7">
                                    {{ $destination->municipality }}, {{ $destination->province }}
                                </p>
                                <span class="tm-gold-badge absolute right-5 top-5 sm:right-7">
                                    {{ $destination->preference_score }}% match
                                </span>
                            </div>
                        @else
                            <div class="relative flex h-64 shrink-0 items-end overflow-hidden bg-gradient-to-br from-volcanic-teal via-boracay-dark to-boracay p-5 sm:h-[18rem] sm:p-7">
                                <span class="absolute right-5 top-5 rounded-full bg-white/15 px-3 py-1.5 text-sm font-semibold text-white sm:right-7">
                                    {{ $destination->preference_score }}% match
                                </span>
                                <p class="font-display text-3xl font-semibold text-white sm:text-4xl">
                                    {{ $destination->province }}
                                </p>
                            </div>
                        @endif

                        <div class="flex min-h-0 flex-1 flex-col p-5 sm:p-7">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                                    Destination
                                </p>
                                <h2 class="mt-2 font-display text-2xl font-semibold leading-tight text-volcanic-teal sm:text-3xl">
                                    {{ $destination->name }}
                                </h2>
                                @unless ($destination->image_url)
                                    <p class="mt-1 text-sm text-benguet-charcoal/60">
                                        {{ $destination->municipality }}, {{ $destination->province }}
                                    </p>
                                @endunless
                            </div>

                            <p class="mt-4 line-clamp-4 text-sm leading-6 text-benguet-charcoal/75">
                                {{ $destination->description }}
                            </p>

                            <div class="mt-4 flex flex-wrap gap-2">
                                @foreach ($destination->tags->take(4) as $tag)
                                    <span class="rounded-full bg-boracay-light px-3 py-1.5 text-xs font-medium text-boracay-dark">
                                        {{ $tag->name }}
                                    </span>
                                @endforeach
                            </div>

                            <div class="mt-auto grid grid-cols-2 gap-3 border-t border-boracay-light pt-5">
                                <button type="button" data-swipe-action="passed" class="min-h-12 rounded-md border border-boracay-light px-4 py-3 text-sm font-semibold text-benguet-charcoal transition hover:border-red-300 hover:bg-red-50 hover:text-red-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700 active:scale-[0.98] active:duration-75">
                                    Pass
                                </button>
                                <button type="button" data-swipe-action="liked" class="min-h-12 rounded-md bg-boracay px-4 py-3 text-sm font-semibold text-benguet-charcoal transition hover:bg-boracay-dark hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-volcanic-teal active:scale-[0.98] active:duration-75">
                                    Like
                                </button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
        </div>
    </div>
</x-app-layout>