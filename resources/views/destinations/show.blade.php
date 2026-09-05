<x-app-layout>
    <x-slot name="header">
        <a
            href="{{ route('destinations.index') }}"
            class="text-sm font-medium text-boracay-dark hover:text-volcanic-teal"
        >
            ← Back to destinations
        </a>
    </x-slot>

    <div class="grid gap-8 lg:grid-cols-[1.3fr_0.7fr]">
        <section class="rounded-2xl bg-island-white p-6 shadow-sm ring-1 ring-boracay-light">
            @if ($destination->image_url)
                <img
                    src="{{ $destination->image_url }}"
                    alt="{{ $destination->name }}"
                    class="mb-6 h-64 w-full rounded-2xl object-cover"
                >
            @endif

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-sm text-benguet-charcoal/70">
                        {{ $destination->municipality }},
                        {{ $destination->province }}
                    </p>

                    <h1 class="mt-1 text-3xl font-bold text-volcanic-teal">
                        {{ $destination->name }}
                    </h1>
                </div>

                <span class="rounded-full bg-boracay-light px-3 py-1 text-sm font-semibold capitalize text-boracay-dark">
                    {{ $destination->budget_level }}
                </span>
            </div>

            <p class="mt-6 leading-7 text-benguet-charcoal/75">
                {{ $destination->description }}
            </p>

            <div class="mt-6 grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl bg-palawan-sand p-4">
                    <p class="text-xs text-benguet-charcoal/60">Entrance fee</p>
                    <p class="mt-1 font-semibold text-volcanic-teal">
                        ₱{{ number_format($destination->entrance_fee, 2) }}
                    </p>
                </div>

                <div class="rounded-xl bg-palawan-sand p-4">
                    <p class="text-xs text-benguet-charcoal/60">Estimated cost</p>
                    <p class="mt-1 font-semibold text-volcanic-teal">
                        ₱{{ number_format($destination->estimated_cost, 2) }}
                    </p>
                </div>

                <div class="rounded-xl bg-palawan-sand p-4">
                    <p class="text-xs text-benguet-charcoal/60">Recommended stay</p>
                    <p class="mt-1 font-semibold text-volcanic-teal">
                        {{ $destination->recommended_minutes }} minutes
                    </p>
                </div>
            </div>

            <div class="mt-6 flex flex-wrap gap-2">
                @foreach ($destination->tags as $tag)
                    <span class="rounded-full bg-boracay-light px-3 py-1 text-sm text-boracay-dark">
                        {{ $tag->name }}
                    </span>
                @endforeach
            </div>
        </section>

        <aside class="space-y-5">
            <div class="rounded-2xl bg-island-white p-6 shadow-sm ring-1 ring-boracay-light">
                <h2 class="text-lg font-semibold text-volcanic-teal">
                    Location
                </h2>

                <div
                    class="mt-4 h-64 rounded-xl bg-boracay-light"
                    data-destination-map
                    data-lat="{{ $destination->latitude }}"
                    data-lng="{{ $destination->longitude }}"
                    data-name="{{ $destination->name }}"
                ></div>

                <p class="mt-3 text-xs text-benguet-charcoal/60">
                    {{ $destination->latitude }},
                    {{ $destination->longitude }}
                </p>
            </div>

            <div class="rounded-2xl bg-island-white p-6 shadow-sm ring-1 ring-boracay-light">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-volcanic-teal">
                            Reviews
                        </h2>

                        <p class="mt-1 text-sm text-benguet-charcoal/60">
                            {{ $destination->reviews->count() }} published review(s)
                        </p>
                    </div>

                    @if ($destination->reviews->isNotEmpty())
                        <span class="tm-gold-badge">
                            ★ {{ number_format($destination->reviews->avg('rating'), 1) }}/5
                        </span>
                    @endif
                </div>

                <div class="mt-5 space-y-4">
                    @forelse ($destination->reviews as $review)
                        <div class="border-t border-boracay-light pt-4">
                            <div class="flex items-center justify-between gap-3">
                                <strong class="text-volcanic-teal">
                                    {{ $review->user->name }}
                                </strong>

                                <span class="tm-gold-badge">
                                    ★ {{ $review->rating }}/5
                                </span>
                            </div>

                            <p class="mt-2 text-sm leading-6 text-benguet-charcoal/75">
                                {{ $review->comment }}
                            </p>
                        </div>
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
                        class="mt-6 border-t border-boracay-light pt-6"
                    >
                        @csrf

                        <h3 class="font-semibold text-volcanic-teal">
                            Share your experience
                        </h3>

                        <label class="mt-4 block">
                            <span class="text-sm font-medium text-benguet-charcoal">
                                Rating
                            </span>

                            <select
                                name="rating"
                                required
                                class="mt-2 w-full rounded-lg border-boracay-light bg-palawan-sand"
                            >
                                @foreach ([5, 4, 3, 2, 1] as $rating)
                                    <option value="{{ $rating }}">
                                        {{ $rating }}/5
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label class="mt-4 block">
                            <span class="text-sm font-medium text-benguet-charcoal">
                                Review
                            </span>

                            <textarea
                                name="comment"
                                rows="4"
                                required
                                minlength="10"
                                class="mt-2 w-full rounded-lg border-boracay-light bg-palawan-sand"
                                placeholder="What should other travelers know?"
                            >{{ old('comment') }}</textarea>
                        </label>

                        <button
                            type="submit"
                            class="tm-primary-button mt-4"
                        >
                            Submit review
                        </button>
                    </form>
                @else
                    <p class="mt-6 border-t border-boracay-light pt-6 text-sm text-benguet-charcoal/60">
                        Log in to leave a review.
                    </p>
                @endauth
            </div>
        </aside>
    </div>
</x-app-layout>