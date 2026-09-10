<x-app-layout>
    <x-slot name="header">
        <div class="flex items-end justify-between gap-6">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.3em] text-boracay-dark">
                    TraMatch / Collection
                </p>

                <h1 class="mt-3 font-display text-6xl font-medium uppercase leading-[0.85] tracking-[-0.05em] text-volcanic-teal sm:text-8xl lg:text-9xl">
                    Destinations
                </h1>
            </div>

            <span class="hidden text-xs font-bold uppercase tracking-[0.25em] text-benguet-charcoal/45 md:block">
                Luzon / Philippines
            </span>
        </div>
    </x-slot>

    <div class="space-y-8">
        <section class="rounded-[1.5rem] border border-boracay-light bg-palawan-sand">
            <form
                method="GET"
                class="grid gap-5 p-6 sm:grid-cols-3 sm:items-center sm:p-8"
            >
                <label class="block">
                    <span class="text-[0.65rem] font-bold uppercase tracking-[0.25em] text-benguet-charcoal/60">
                        Search
                    </span>

                    <input
                        name="search"
                        value="{{ request('search') }}"
                        placeholder="Location or province"
                        class="mt-3 w-full border-0 border-b border-boracay-light bg-transparent px-0 pb-3 text-sm text-volcanic-teal placeholder:text-benguet-charcoal/40 focus:border-boracay focus:ring-0"
                    >
                </label>

                <label class="block">
                    <span class="text-[0.65rem] font-bold uppercase tracking-[0.25em] text-benguet-charcoal/60">
                        Budget
                    </span>

                    <select
                        name="budget_level"
                        class="mt-3 w-full border-0 border-b border-boracay-light bg-transparent px-0 pb-3 text-sm text-volcanic-teal focus:border-boracay focus:ring-0"
                    >
                        <option value="">All budgets</option>

                        @foreach (['economy', 'mid-range', 'premium'] as $budget)
                            <option
                                value="{{ $budget }}"
                                @selected(request('budget_level') === $budget)
                            >
                                {{ ucfirst($budget) }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <div class="flex items-end justify-between gap-4 sm:justify-end">
                    <span class="text-[0.65rem] font-bold uppercase tracking-[0.25em] text-benguet-charcoal/45">
                        {{ $destinations->total() }} places
                    </span>

                    <button
                        type="submit"
                        class="rounded-full bg-volcanic-teal px-5 py-3 text-xs font-bold uppercase tracking-[0.15em] text-white transition hover:bg-boracay-dark"
                    >
                        Filter
                    </button>
                </div>
            </form>
        </section>

        <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
            @forelse ($destinations as $index => $destination)
                <article class="group overflow-hidden rounded-[1.5rem] border border-boracay-light bg-palawan-sand">
                    <a
                        href="{{ route('destinations.show', $destination) }}"
                        class="block"
                    >
                        @if ($destination->image_url)
                            <img
                                src="{{ $destination->image_url }}"
                                alt="{{ $destination->name }}"
                                loading="lazy"
                                class="h-72 w-full object-cover transition duration-700 group-hover:scale-105"
                            >
                        @else
                            <div class="flex h-72 items-center justify-center bg-volcanic-teal text-4xl font-medium text-white">
                                {{ $destination->province }}
                            </div>
                        @endif
                    </a>

                    <div class="p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-[0.65rem] font-bold uppercase tracking-[0.2em] text-benguet-charcoal/45">
                                    0{{ $index + 1 }}
                                    /
                                    {{ $destination->province }}
                                </p>

                                <h2 class="mt-4 font-display text-3xl font-medium leading-tight text-volcanic-teal">
                                    {{ $destination->name }}
                                </h2>
                            </div>

                            <span class="text-[0.65rem] font-bold uppercase tracking-[0.15em] text-boracay-dark">
                                {{ $destination->budget_level }}
                            </span>
                        </div>

                        <p class="mt-5 line-clamp-3 text-sm leading-6 text-benguet-charcoal/65">
                            {{ $destination->description }}
                        </p>

                        <div class="mt-6 flex flex-wrap gap-2">
                            @foreach ($destination->tags->take(3) as $tag)
                                <span class="border-b border-boracay px-2 pb-1 text-[0.65rem] font-bold uppercase tracking-[0.12em] text-boracay-dark">
                                    {{ $tag->name }}
                                </span>
                            @endforeach
                        </div>

                        <div class="mt-7 flex items-center justify-between border-t border-boracay-light pt-5">
                            <span class="text-sm text-benguet-charcoal/55">
                                From ₱{{ number_format($destination->estimated_cost, 2) }}
                            </span>

                            <a
                                href="{{ route('destinations.show', $destination) }}"
                                class="text-sm font-bold text-volcanic-teal transition group-hover:text-boracay-dark"
                            >
                                View place ↗
                            </a>
                        </div>
                    </div>
                </article>
            @empty
                <div class="border border-boracay-light bg-palawan-sand p-10 md:col-span-2 lg:col-span-3">
                    <p class="text-xs font-bold uppercase tracking-[0.25em] text-boracay-dark">
                        No results
                    </p>

                    <h2 class="mt-5 font-display text-5xl font-medium text-volcanic-teal">
                        Nothing matched this search.
                    </h2>

                    <p class="mt-4 max-w-xl text-benguet-charcoal/65">
                        Try a different destination name, province, or budget level.
                    </p>
                </div>
            @endforelse
        </div>

        {{ $destinations->links() }}
    </div>
</x-app-layout>