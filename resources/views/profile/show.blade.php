<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                Your account
            </p>
            <h1 class="mt-3 font-display text-4xl font-semibold text-volcanic-teal sm:text-5xl">
                Profile
            </h1>
        </div>
    </x-slot>

    <section class="mx-auto max-w-3xl rounded-2xl border border-boracay-light bg-island-white p-6 shadow-sm sm:p-10">
        <div class="flex flex-col items-center text-center">
            <div class="flex h-32 w-32 items-center justify-center overflow-hidden rounded-full bg-volcanic-teal text-3xl font-semibold text-white ring-4 ring-boracay-light">
                @if ($user->profile_photo_path)
                    <img
                        src="{{ asset('storage/' . $user->profile_photo_path) }}"
                        alt="{{ $user->name }} profile photo"
                        class="h-full w-full object-cover"
                    >
                @else
                    {{ strtoupper(substr($user->first_name ?: 'T', 0, 1) . substr($user->last_name, 0, 1)) }}
                @endif
            </div>

            <h2 class="mt-5 font-display text-3xl font-semibold text-volcanic-teal">
                {{ $user->name }}
            </h2>
            <p class="mt-1 text-sm text-benguet-charcoal/65">
                {{ $user->email }}
            </p>
            @if ($user->isSuperAdmin())
                <p class="mt-2 text-xs font-bold uppercase tracking-[0.2em] text-philippine-gold">
                    Super admin
                </p>
            @elseif ($user->isAdmin())
                <p class="mt-2 text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                    Admin
                </p>
            @endif
            <a
                href="{{ route('profile.edit') }}"
                class="mt-4 inline-flex min-h-11 items-center justify-center rounded-md bg-volcanic-teal px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-boracay-dark focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-volcanic-teal"
            >
                Edit profile
            </a>
        </div>

        <section class="mt-8 border-t border-boracay-light pt-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                        Your travel style
                    </p>
                    <h2 class="mt-2 font-display text-2xl font-semibold text-volcanic-teal">
                        Preferences
                    </h2>
                </div>
                <a
                    href="{{ route('preferences.edit') }}"
                    class="inline-flex min-h-10 items-center justify-center rounded-md border border-volcanic-teal px-4 py-2 text-sm font-semibold text-volcanic-teal transition hover:bg-volcanic-teal hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-volcanic-teal"
                >
                    Edit preferences
                </a>
            </div>

            <div class="mt-6 rounded-md bg-palawan-sand p-4 sm:p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-volcanic-teal">Core travel preferences</p>
                        <p class="mt-1 text-sm text-benguet-charcoal/65">
                            {{ $completedPreferences }} of {{ $totalPreferences }} complete
                        </p>
                    </div>
                    <span class="font-display text-2xl font-semibold text-volcanic-teal">
                        {{ $preferencePercentage }}%
                    </span>
                </div>

                <div
                    role="progressbar"
                    aria-label="Core travel preferences complete"
                    aria-valuemin="0"
                    aria-valuemax="{{ $totalPreferences }}"
                    aria-valuenow="{{ $completedPreferences }}"
                    class="mt-4 h-2 overflow-hidden rounded-full bg-boracay-light"
                >
                    <div class="h-full rounded-full bg-volcanic-teal transition-[width] duration-500" style="width: {{ $preferencePercentage }}%"></div>
                </div>

                @if ($missingPreferences)
                    <p class="mt-3 text-xs leading-5 text-benguet-charcoal/65">
                        Still to add: {{ implode(', ', $missingPreferences) }}.
                        Preferred region is optional.
                    </p>
                @else
                    <p class="mt-3 text-xs leading-5 text-benguet-charcoal/65">
                        Your core preferences are ready. Preferred region is optional.
                    </p>
                @endif
            </div>

            @if ($travelProfile)
                <dl class="mt-6 grid gap-5 sm:grid-cols-2">
                    <div class="border-l-2 border-philippine-gold pl-4">
                        <dt class="text-xs font-bold uppercase tracking-[0.15em] text-benguet-charcoal/55">Budget</dt>
                        <dd class="mt-1 font-semibold text-volcanic-teal">{{ ucfirst(str_replace('-', ' ', $travelProfile->budget_level)) }}</dd>
                    </div>
                    <div class="border-l-2 border-boracay pl-4">
                        <dt class="text-xs font-bold uppercase tracking-[0.15em] text-benguet-charcoal/55">Group size</dt>
                        <dd class="mt-1 font-semibold text-volcanic-teal">{{ $travelProfile->group_size }} {{ $travelProfile->group_size === 1 ? 'traveler' : 'travelers' }}</dd>
                    </div>
                    <div class="border-l-2 border-boracay-dark pl-4">
                        <dt class="text-xs font-bold uppercase tracking-[0.15em] text-benguet-charcoal/55">Trip length</dt>
                        <dd class="mt-1 font-semibold text-volcanic-teal">{{ $travelProfile->trip_duration_days }} {{ $travelProfile->trip_duration_days === 1 ? 'day' : 'days' }}</dd>
                    </div>
                    <div class="border-l-2 border-philippine-gold pl-4">
                        <dt class="text-xs font-bold uppercase tracking-[0.15em] text-benguet-charcoal/55">Preferred region</dt>
                        <dd class="mt-1 font-semibold text-volcanic-teal">{{ $travelProfile->preferred_region ?: 'Any region' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-bold uppercase tracking-[0.15em] text-benguet-charcoal/55">Interests</dt>
                        <dd class="mt-2 flex flex-wrap gap-2">
                            @forelse ($travelProfile->tags as $tag)
                                <span class="rounded-full bg-boracay-light px-3 py-1.5 text-sm font-medium text-boracay-dark">
                                    {{ $tag->name }}
                                </span>
                            @empty
                                <span class="text-sm text-benguet-charcoal/60">No interests selected</span>
                            @endforelse
                        </dd>
                    </div>
                </dl>
            @else
                <p class="mt-5 text-sm leading-6 text-benguet-charcoal/65">
                    Set your budget, trip length, region, and interests to personalize destination recommendations.
                </p>
            @endif
        </section>
    </section>
</x-app-layout>