<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#00A896">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">

    <link rel="icon" href="{{ asset('images/logo.png') }}">
    <link rel="manifest" href="/manifest.webmanifest">

    <title>{{ $title ?? 'TraMatch' }}</title>

    @vite([
    'resources/css/app.css',
    'resources/js/app.js',
    'resources/js/page-transitions.js'
    ])
</head>

<body class="min-h-screen bg-palawan-sand text-benguet-charcoal">
    <header class="border-b border-boracay-light bg-island-white">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
            <a
                href="{{ route('dashboard') }}"
                class="flex items-center gap-3"
            >
                <img
                    src="{{ asset('images/logo.png') }}"
                    alt="TraMatch logo"
                    class="h-10 w-auto"
                >

                <span class="sr-only">
                    TraMatch
                </span>
            </a>

            <nav class="flex flex-wrap items-center gap-3 text-sm font-medium sm:gap-5">
                <a
                    href="{{ route('dashboard') }}"
                    class="rounded-lg px-2 py-1 hover:text-boracay-dark"
                >
                    Dashboard
                </a>

                @if (Route::has('destinations.index'))
                    <a
                        href="{{ route('destinations.index') }}"
                        class="rounded-lg px-2 py-1 hover:text-boracay-dark"
                    >
                        Destinations
                    </a>
                @endif

                @if (Route::has('discover.index'))
                    <a
                        href="{{ route('discover.index') }}"
                        class="rounded-lg px-2 py-1 hover:text-boracay-dark"
                    >
                        Discover
                    </a>
                @endif

                @if (Route::has('recommendations.index'))
                    <a
                        href="{{ route('recommendations.index') }}"
                        class="rounded-lg px-2 py-1 hover:text-boracay-dark"
                    >
                        Recommendations
                    </a>
                @endif

                @if (Route::has('itineraries.index'))
                    <a
                        href="{{ route('itineraries.index') }}"
                        class="rounded-lg px-2 py-1 hover:text-boracay-dark"
                    >
                        My Trips
                    </a>
                @endif

                @if (Route::has('preferences.edit'))
                    <a
                        href="{{ route('preferences.edit') }}"
                        class="rounded-lg px-2 py-1 hover:text-boracay-dark"
                    >
                        Preferences
                    </a>
                @endif

                @if (Route::has('profile.edit'))
                    <a
                        href="{{ route('profile.edit') }}"
                        class="rounded-lg px-2 py-1 hover:text-boracay-dark"
                    >
                        Profile
                    </a>
                @endif

                @if (Route::has('admin.dashboard') && auth()->check() && auth()->user()->isAdmin())
                    <a
                        href="{{ route('admin.dashboard') }}"
                        class="rounded-lg px-2 py-1 font-semibold text-boracay-dark hover:text-volcanic-teal"
                    >
                        Admin
                    </a>
                @endif

                @if (Route::has('logout'))
                    <form
                        method="POST"
                        action="{{ route('logout') }}"
                    >
                        @csrf

                        <button
                            type="submit"
                            class="rounded-lg px-2 py-1 text-slate-600 hover:text-boracay-dark"
                        >
                            Log out
                        </button>
                    </form>
                @endif
            </nav>
        </div>
    </header>

    @isset($header)
        <div class="border-b border-boracay-light bg-island-white">
            <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
                {{ $header }}
            </div>
        </div>
    @endisset

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        @if (session('status'))
            <div class="mb-6 rounded-xl border border-boracay bg-boracay-light p-4 text-benguet-charcoal">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{ $slot }}
    </main>
</body>
</html>