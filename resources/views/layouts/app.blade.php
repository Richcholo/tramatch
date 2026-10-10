<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0B252B">

    <link rel="icon" href="{{ asset('images/logo.svg') }}">
    <link rel="manifest" href="/manifest.webmanifest">

    <title>{{ $title ?? 'TraMatch' }}</title>

    {{-- Playfair Display backs every .font-display heading on app pages.
         Without this link those headings silently fall back to Georgia. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600&display=swap" rel="stylesheet">

    @vite([
        'resources/css/app.css',
        'resources/js/app.js',
        'resources/js/page-transitions.js'
    ])
</head>

<body class="tm-topographic-background min-h-screen bg-sea-glass text-benguet-charcoal antialiased">
    <div
        data-navigation-loading
        role="status"
        aria-live="polite"
        class="pointer-events-none fixed inset-0 z-[100000] hidden bg-sea-glass"
    >
        <span class="sr-only">Loading page...</span>

        <div aria-hidden="true" class="border-b border-white/10 bg-volcanic-teal">
            <div class="mx-auto flex h-[81px] max-w-[1600px] items-center justify-between px-5 sm:px-8 lg:px-12">
                <div class="h-10 w-32 animate-pulse rounded bg-white/20"></div>
                <div class="h-11 w-11 animate-pulse rounded-full bg-white/15"></div>
            </div>
        </div>

        <div aria-hidden="true" class="mx-auto max-w-[1600px] px-5 py-10 sm:px-8 lg:px-12">
            <div class="mb-10 max-w-2xl">
                <div class="h-3 w-24 animate-pulse rounded bg-boracay/40"></div>
                <div class="mt-4 h-9 w-3/4 animate-pulse rounded bg-benguet-charcoal/10"></div>
                <div class="mt-3 h-4 w-full max-w-lg animate-pulse rounded bg-benguet-charcoal/10"></div>
            </div>

            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-md border border-boracay-light bg-island-white p-5">
                    <div class="aspect-[16/9] animate-pulse rounded bg-boracay-light"></div>
                    <div class="mt-5 h-5 w-2/3 animate-pulse rounded bg-benguet-charcoal/10"></div>
                    <div class="mt-3 h-3 w-full animate-pulse rounded bg-benguet-charcoal/10"></div>
                    <div class="mt-2 h-3 w-4/5 animate-pulse rounded bg-benguet-charcoal/10"></div>
                </div>
                <div class="rounded-md border border-boracay-light bg-island-white p-5">
                    <div class="aspect-[16/9] animate-pulse rounded bg-boracay-light"></div>
                    <div class="mt-5 h-5 w-1/2 animate-pulse rounded bg-benguet-charcoal/10"></div>
                    <div class="mt-3 h-3 w-full animate-pulse rounded bg-benguet-charcoal/10"></div>
                    <div class="mt-2 h-3 w-4/5 animate-pulse rounded bg-benguet-charcoal/10"></div>
                </div>
                <div class="rounded-md border border-boracay-light bg-island-white p-5 sm:hidden lg:block">
                    <div class="aspect-[16/9] animate-pulse rounded bg-boracay-light"></div>
                    <div class="mt-5 h-5 w-3/5 animate-pulse rounded bg-benguet-charcoal/10"></div>
                    <div class="mt-3 h-3 w-full animate-pulse rounded bg-benguet-charcoal/10"></div>
                    <div class="mt-2 h-3 w-4/5 animate-pulse rounded bg-benguet-charcoal/10"></div>
                </div>
            </div>
        </div>
    </div>

    <header class="relative z-30 border-b border-white/10 bg-volcanic-teal">
        <div class="mx-auto flex max-w-[1600px] items-center justify-between gap-4 px-5 py-5 sm:px-8 lg:px-12">
            <a
                href="{{ url('/') }}"
                class="flex items-center"
            >
                <img
                    src="{{ asset('images/logo.svg') }}"
                    alt="TraMatch"
                    class="h-10 w-auto"
                >
            </a>

            <details class="group relative">
                <summary
                    aria-label="Toggle navigation menu"
                    title="Navigation menu"
                    class="flex h-11 w-11 cursor-pointer list-none items-center justify-center rounded-full border border-white/30 bg-white/10 text-white shadow-sm transition hover:bg-white/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-philippine-gold [&::-webkit-details-marker]:hidden"
                >
                    {{-- Three separate lines so they can rotate into the X.
                         Previously two whole paths were swapped with
                         hidden/group-open:hidden, which is an instant cut --
                         the button looked like it glitched. --}}
                    <svg
                        class="tm-burger h-6 w-6"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        aria-hidden="true"
                    >
                        <line class="tm-burger-line tm-burger-line--top" x1="4" y1="7" x2="20" y2="7" />
                        <line class="tm-burger-line tm-burger-line--middle" x1="4" y1="12" x2="20" y2="12" />
                        <line class="tm-burger-line tm-burger-line--bottom" x1="4" y1="17" x2="20" y2="17" />
                    </svg>
                </summary>

                <nav aria-label="Main navigation" class="absolute right-0 top-full z-50 mt-3 w-72 max-w-[calc(100vw-2.5rem)] rounded-md border border-boracay-light bg-palawan-sand p-2 text-sm font-semibold shadow-xl shadow-benguet-charcoal/15">
                    <a href="{{ route('dashboard') }}" class="block rounded-md px-4 py-3 transition hover:bg-boracay-light hover:text-boracay-dark">
                        Dashboard
                    </a>

                    @if (Route::has('discover.index'))
                        <a href="{{ route('discover.index') }}" class="block rounded-md px-4 py-3 transition hover:bg-boracay-light hover:text-boracay-dark">
                            Discover
                        </a>
                    @endif

                    @if (Route::has('destinations.index'))
                        <a href="{{ route('destinations.index') }}" class="block rounded-md px-4 py-3 transition hover:bg-boracay-light hover:text-boracay-dark">
                            Destinations
                        </a>
                    @endif

                    @if (Route::has('recommendations.index'))
                        <a href="{{ route('recommendations.index') }}" class="block rounded-md px-4 py-3 transition hover:bg-boracay-light hover:text-boracay-dark">
                            Matches
                        </a>
                    @endif

                    @if (Route::has('itineraries.index'))
                        <a href="{{ route('itineraries.index') }}" class="block rounded-md px-4 py-3 transition hover:bg-boracay-light hover:text-boracay-dark">
                            My trips
                        </a>
                    @endif

                    @if (Route::has('profile.show'))
                        <a href="{{ route('profile.show') }}" class="block rounded-md px-4 py-3 transition hover:bg-boracay-light hover:text-boracay-dark">
                            Profile
                        </a>
                    @endif

@if (Route::has('preferences.edit'))
                        <a href="{{ route('preferences.edit') }}" class="block rounded-md px-4 py-3 transition hover:bg-boracay-light hover:text-boracay-dark">
                            Preferences
                        </a>
                    @endif

                    @if (Route::has('admin.dashboard') && auth()->check() && auth()->user()->isAdmin())
                        <a href="{{ route('admin.dashboard') }}" class="mt-1 block rounded-md bg-philippine-gold px-4 py-3 text-benguet-charcoal transition hover:bg-boracay">
                            Admin
                        </a>
                    @endif

                    @if (Route::has('logout'))
                        <div class="my-2 border-t border-boracay-light"></div>
                        <button
                            type="button"
                            onclick="document.getElementById('logout-confirmation').showModal()"
                            class="block w-full rounded-md px-4 py-3 text-left text-red-600 transition hover:bg-red-50 hover:text-red-700 active:scale-[0.98] active:duration-75"
                        >
                            Log out
                        </button>
                    @endif
                </nav>
            </details>
        </div>
    </header>

    @isset($header)
        <section class="border-b border-boracay-light">
            <div class="mx-auto max-w-[1600px] px-5 py-8 sm:px-8 lg:px-12">
                {{ $header }}
            </div>
        </section>
    @endisset

    <main @class([
        'relative z-10',
        'w-full' => request()->routeIs('discover.index'),
        'w-full px-5 py-10 sm:px-8 lg:px-12' => request()->routeIs('destinations.show'),
        'mx-auto max-w-[1600px] px-5 py-10 sm:px-8 lg:px-12' => ! request()->routeIs('discover.index') && ! request()->routeIs('destinations.show'),
    ])>
        @if (session('status'))
            <div class="mb-8 rounded-2xl border border-boracay bg-boracay-light p-4 text-benguet-charcoal">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-8 rounded-2xl border border-red-200 bg-red-50 p-4 text-red-800">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{ $slot }}
    </main>

    <x-logout-confirmation />

    @stack('scripts')
</body>
</html>