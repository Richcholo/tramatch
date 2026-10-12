<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                    Account settings
                </p>
                <h1 class="mt-3 font-display text-4xl font-semibold text-volcanic-teal sm:text-5xl">
                    Edit profile
                </h1>
            </div>
            <a
                href="{{ route('profile.show') }}"
                class="inline-flex min-h-11 items-center justify-center rounded-md border border-volcanic-teal px-4 py-2 text-sm font-semibold text-volcanic-teal transition hover:bg-volcanic-teal hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-volcanic-teal"
            >
                View profile
            </a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-6xl">
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_15rem]">
            <div class="space-y-6">
                <section id="profile-photo" class="scroll-mt-8 rounded-md border border-boracay-light bg-island-white p-6 shadow-sm sm:p-8">
                    @include('profile.partials.profile-photo-form')
                </section>

                <section id="profile-information" class="scroll-mt-8 rounded-md border border-boracay-light bg-island-white p-6 shadow-sm sm:p-8">
                    @include('profile.partials.update-profile-information-form')
                </section>

                <section id="password" class="scroll-mt-8 rounded-md border border-boracay-light bg-island-white p-6 shadow-sm sm:p-8">
                    @include('profile.partials.update-password-form')
                </section>

                <section id="delete-account" class="scroll-mt-8 rounded-md border border-red-200 bg-red-50/60 p-6 shadow-sm sm:p-8">
                    @include('profile.partials.delete-user-form')
                </section>
            </div>

            <aside class="order-first self-start rounded-md border border-boracay-light bg-island-white p-5 shadow-sm lg:sticky lg:top-6 lg:order-last">
                <h2 class="text-sm font-bold uppercase tracking-[0.18em] text-benguet-charcoal/55">
                    Settings
                </h2>
                <nav aria-label="Profile settings" class="mt-4 space-y-1 text-sm font-semibold">
                    <a href="#profile-photo" class="block rounded px-3 py-2.5 text-volcanic-teal transition hover:bg-boracay-light">
                        Profile photo
                    </a>
                    <a href="#profile-information" class="block rounded px-3 py-2.5 text-volcanic-teal transition hover:bg-boracay-light">
                        Profile information
                    </a>
                    <a href="#password" class="block rounded px-3 py-2.5 text-volcanic-teal transition hover:bg-boracay-light">
                        Password
                    </a>
                    <a href="#delete-account" class="block rounded px-3 py-2.5 text-red-700 transition hover:bg-red-50">
                        Delete account
                    </a>
                </nav>
            </aside>
        </div>
    </div>
</x-app-layout>
