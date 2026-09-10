<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#FDFBF7">

    <link rel="icon" href="{{ asset('images/logo.png') }}">
    <link rel="manifest" href="/manifest.webmanifest">

    <title>{{ $title ?? 'TraMatch' }}</title>

    @vite([
        'resources/css/app.css',
        'resources/js/app.js',
        'resources/js/page-transitions.js'
    ])
</head>

<body class="min-h-screen bg-palawan-sand text-benguet-charcoal antialiased">
    <header class="border-b border-boracay-light bg-palawan-sand">
        <div class="mx-auto flex max-w-[1600px] flex-wrap items-center justify-between gap-4 px-5 py-5 sm:px-8 lg:px-12">
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

            <nav class="flex flex-wrap items-center gap-2 text-sm font-semibold sm:gap-3">
                <a
                    href="{{ route('dashboard') }}"
                    class="rounded-full px-3 py-2 transition hover:bg-boracay-light hover:text-boracay-dark"
                >
                    Dashboard
                </a>

                @if (Route::has('discover.index'))
                    <a
                        href="{{ route('discover.index') }}"
                        class="rounded-full px-3 py-2 transition hover:bg-boracay-light hover:text-boracay-dark"
                    >
                        Discover
                    </a>
                @endif

                @if (Route::has('destinations.index'))
                    <a
                        href="{{ route('destinations.index') }}"
                        class="rounded-full px-3 py-2 transition hover:bg-boracay-light hover:text-boracay-dark"
                    >
                        Destinations
                    </a>
                @endif

                @if (Route::has('recommendations.index'))
                    <a
                        href="{{ route('recommendations.index') }}"
                        class="rounded-full px-3 py-2 transition hover:bg-boracay-light hover:text-boracay-dark"
                    >
                        Matches
                    </a>
                @endif

                @if (Route::has('itineraries.index'))
                    <a
                        href="{{ route('itineraries.index') }}"
                        class="rounded-full px-3 py-2 transition hover:bg-boracay-light hover:text-boracay-dark"
                    >
                        My trips
                    </a>
                @endif

                @if (Route::has('profile.edit'))
                    <a
                        href="{{ route('profile.edit') }}"
                        class="rounded-full px-3 py-2 transition hover:bg-boracay-light hover:text-boracay-dark"
                    >
                        Profile
                    </a>
                @endif

                @if (Route::has('preferences.edit'))
                    <a
                        href="{{ route('preferences.edit') }}"
                        class="rounded-full px-3 py-2 transition hover:bg-boracay-light hover:text-boracay-dark"
                    >
                        Preferences
                    </a>
                @endif

                @if (Route::has('admin.dashboard') && auth()->user()->isAdmin())
                    <a
                        href="{{ route('admin.dashboard') }}"
                        class="rounded-full bg-philippine-gold px-3 py-2 text-benguet-charcoal transition hover:bg-boracay"
                    >
                        Admin
                    </a>
                @endif

                @if (Route::has('logout'))
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf

                        <button
                            type="submit"
                            class="rounded-full px-3 py-2 text-benguet-charcoal/70 transition hover:bg-boracay-light hover:text-boracay-dark"
                        >
                            Log out
                        </button>
                    </form>
                @endif
            </nav>
        </div>
    </header>

    @isset($header)
        <section class="border-b border-boracay-light bg-palawan-sand">
            <div class="mx-auto max-w-[1600px] px-5 py-8 sm:px-8 lg:px-12">
                {{ $header }}
            </div>
        </section>
    @endisset

    <main class="mx-auto max-w-[1600px] px-5 py-10 sm:px-8 lg:px-12">
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
</body>
</html>