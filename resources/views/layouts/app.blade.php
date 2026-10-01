<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0B252B">

    <link rel="icon" href="{{ asset('images/logo.png') }}">
    <link rel="manifest" href="/manifest.webmanifest">

    <title>{{ $title ?? 'TraMatch' }}</title>

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
                    src="{{ asset('images/logo.png') }}"
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
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path class="group-open:hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path class="hidden group-open:block" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
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

                    @if (Route::has('admin.dashboard') && auth()->user()->isAdmin())
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
</body>
</html>