<x-app-layout>
    <x-slot name="header">
        <a
            href="{{ route('destinations.index') }}"
            class="text-sm font-semibold text-boracay-dark hover:text-volcanic-teal"
        >
            ← Back to destinations
        </a>
    </x-slot>

    <div class="space-y-10">
        <section class="relative overflow-hidden rounded-[2rem] bg-volcanic-teal text-white shadow-xl">
            @if ($destination->image_url)
                <img
                    src="{{ $destination->image_url }}"
                    alt="{{ $destination->name }}"
                    class="absolute inset-0 h-full w-full object-cover opacity-55"
                >
            @else
                <div class="absolute inset-0 bg-gradient-to-br from-boracay via-cyan-500 to-volcanic-teal"></div>
            @endif

            <div class="absolute inset-0 bg-gradient-to-t from-volcanic-teal via-volcanic-teal/55 to-transparent"></div>

            <div class="relative flex min-h-[32rem] flex-col justify-end p-8 sm:p-12">
                <div class="max-w-4xl">
                    <p class="text-xs font-bold uppercase tracking-[0.3em] text-boracay-light">
                        {{ $destination->municipality }},
                        {{ $destination->province }}
                    </p>

                    <h1 class="mt-5 font-display text-5xl font-semibold leading-[0.95] tracking-[-0.05em] text-white sm:text-7xl">
                        {{ $destination->name }}
                    </h1>

                    <div class="mt-6 flex flex-wrap gap-2">
                        @foreach ($destination->tags as $tag)
                            <span class="rounded-full bg-white/15 px-3 py-1 text-xs font-semibold text-white backdrop-blur">
                                {{ $tag->name }}
                            </span>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <div class="grid gap-8 lg:grid-cols-[1.2fr_0.8fr]">
            <section class="space-y-8">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                        01 / The place
                    </p>

                    <h2 class="mt-4 font-display text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal">
                        A place to remember.
                    </h2>

                    <p class="mt-5 max-w-3xl leading-8 text-benguet-charcoal/70">
                        {{ $destination->description }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-[1.5rem] bg-island-white p-5 ring-1 ring-boracay-light">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-boracay-dark">
                            Entrance
                        </p>

                        <p class="mt-5 text-2xl font-bold text-volcanic-teal">
                            ₱{{ number_format($destination->entrance_fee, 2) }}
                        </p>
                    </div>

                    <div class="rounded-[1.5rem] bg-island-white p-5 ring-1 ring-boracay-light">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-boracay-dark">
                            Estimated cost
                        </p>

                        <p class="mt-5 text-2xl font-bold text-volcanic-teal">
                            ₱{{ number_format($destination->estimated_cost, 2) }}
                        </p>
                    </div>

                    <div class="rounded-[1.5rem] bg-island-white p-5 ring-1 ring-boracay-light">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-boracay-dark">
                            Visit length
                        </p>

                        <p class="mt-5 text-2xl font-bold text-volcanic-teal">
                            {{ $destination->recommended_minutes }} min
                        </p>
                    </div>
                </div>

                <div class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-8">
                    <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                        02 / Location
                    </p>

                    <div
                        data-destination-map
                        data-lat="{{ $destination->latitude }}"
                        data-lng="{{ $destination->longitude }}"
                        data-name="{{ $destination->name }}"
                        class="mt-6 h-[28rem] rounded-[1.5rem] bg-boracay-light"
                    ></div>

                    <p class="mt-4 text-xs text-benguet-charcoal/50">
                        {{ $destination->latitude }},
                        {{ $destination->longitude }}
                    </p>
                </div>
            </section>

            <aside class="space-y-8">
                <section class="rounded-[2rem] bg-boracay-dark p-6 text-white shadow-xl sm:p-8">
                    <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-light">
                        03 / Travel fit
                    </p>

                    <h2 class="mt-5 font-display text-4xl font-semibold leading-tight">
                        {{ ucfirst($destination->budget_level) }}
                        and ready to discover.
                    </h2>

                    @if (Route::has('discover.index'))
                        <a
                            href="{{ route('discover.index') }}"
                            class="mt-8 inline-flex rounded-full bg-philippine-gold px-5 py-3 font-bold text-benguet-charcoal transition hover:bg-white"
                        >
                            Find similar places →
                        </a>
                    @endif
                </section>

                <section class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-8">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                                04 / Reviews
                            </p>

                            <h2 class="mt-4 font-display text-3xl font-semibold text-volcanic-teal">
                                Traveler notes.
                            </h2>
                        </div>

                        @if ($destination->reviews->isNotEmpty())
                            <span class="tm-gold-badge">
                                ★ {{ number_format($destination->reviews->avg('rating'), 1) }}
                            </span>
                        @endif
                    </div>

                    <div class="mt-6 space-y-5">
                        @forelse ($destination->reviews as $review)
                            <article class="border-t border-boracay-light pt-5">
                                <div class="flex items-center justify-between gap-3">
                                    <strong class="text-volcanic-teal">
                                        {{ $review->user->name }}
                                    </strong>

                                    <span class="text-sm font-semibold text-boracay-dark">
                                        ★ {{ $review->rating }}/5
                                    </span>
                                </div>

                                <p class="mt-3 text-sm leading-6 text-benguet-charcoal/70">
                                    {{ $review->comment }}
                                </p>
                            </article>
                        @empty
                            <p class="text-sm text-benguet-charcoal/60">
                                No reviews yet.
                            </p>
                        @endforelse
                    </div>

                    @auth
                        <form
                            method="POST"
                            action="{{ route('reviews.store', $destination) }}"
                            class="mt-8 border-t border-boracay-light pt-8"
                        >
                            @csrf

                            <h3 class="font-semibold text-volcanic-teal">
                                Share your experience
                            </h3>

                            <label class="mt-5 block">
                                <span class="text-sm font-semibold">
                                    Rating
                                </span>

                                <select
                                    name="rating"
                                    required
                                    class="mt-2 w-full rounded-xl border-boracay-light bg-palawan-sand focus:border-boracay focus:ring-boracay"
                                >
                                    @foreach ([5, 4, 3, 2, 1] as $rating)
                                        <option value="{{ $rating }}">
                                            {{ $rating }}/5
                                        </option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="mt-5 block">
                                <span class="text-sm font-semibold">
                                    Review
                                </span>

                                <textarea
                                    name="comment"
                                    rows="4"
                                    required
                                    minlength="10"
                                    class="mt-2 w-full rounded-xl border-boracay-light bg-palawan-sand focus:border-boracay focus:ring-boracay"
                                    placeholder="What should other travelers know?"
                                >{{ old('comment') }}</textarea>
                            </label>

                            <button
                                type="submit"
                                class="tm-primary-button mt-5 rounded-full"
                            >
                                Submit review
                            </button>
                        </form>
                    @else
                        <p class="mt-8 border-t border-boracay-light pt-8 text-sm text-benguet-charcoal/60">
                            Log in to leave a review.
                        </p>
                    @endauth
                </section>
            </aside>
        </div>
    </div>
</x-app-layout>