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
    <x-navigation-loading />

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
                            class="block w-full rounded-md px-4 py-3 text-left text-benguet-charcoal/70 transition hover:bg-boracay-light hover:text-boracay-dark"
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
        'mx-auto max-w-[1600px] px-5 py-10 sm:px-8 lg:px-12' => ! request()->routeIs('discover.index'),
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