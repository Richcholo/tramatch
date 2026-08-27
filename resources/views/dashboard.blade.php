<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-benguet-charcoal">Dashboard</h2>
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-[1.25fr_0.75fr]">
        <section class="tm-card overflow-hidden p-8 sm:p-10">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-boracay-dark">Welcome to TraMatch</p>
            <h1 class="mt-3 max-w-2xl text-4xl font-bold tracking-tight text-volcanic-teal sm:text-5xl">Plan a trip that fits you.</h1>
            <p class="mt-5 max-w-2xl text-lg leading-8 text-benguet-charcoal/75">
                Set your travel preferences, discover destinations that match your style, and build a day-by-day itinerary for your next local adventure.
            </p>
            <div class="mt-8 flex flex-wrap gap-3">
                <span class="tm-primary-button">Preference setup comes next</span>
                <span class="inline-flex items-center rounded-xl border border-boracay-light px-5 py-3 font-semibold text-boracay-dark">Explore Luzon</span>
            </div>
        </section>

        <aside class="rounded-2xl bg-volcanic-teal p-8 text-white shadow-sm">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-boracay-light">Travel with intention</p>
            <h2 class="mt-3 text-2xl font-bold">Your interests become your itinerary.</h2>
            <p class="mt-4 leading-7 text-white/75">Choose beaches, mountains, culture, adventure, food, nature, and more. TraMatch will use those choices to find relevant destinations.</p>
            <div class="mt-8 flex flex-wrap gap-2">
                <span class="rounded-full bg-philippine-gold px-3 py-1 text-sm font-semibold text-benguet-charcoal">★ Personalized</span>
                <span class="rounded-full bg-boracay-light px-3 py-1 text-sm font-semibold text-volcanic-teal">Map-ready</span>
            </div>
        </aside>
    </div>
</x-app-layout>