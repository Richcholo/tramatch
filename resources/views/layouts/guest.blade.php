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
        'resources/js/page-transitions.js'
    ])
</head>

<body class="min-h-screen bg-volcanic-teal text-benguet-charcoal antialiased">
    <div class="fixed inset-0 overflow-hidden">
        <div class="tm-hero-art absolute inset-0 opacity-90"></div>

        <div class="absolute inset-0 bg-gradient-to-br from-volcanic-teal/85 via-volcanic-teal/70 to-boracay-dark/70"></div>

        <div class="absolute -right-32 -top-32 h-96 w-96 rounded-full bg-philippine-gold/20 blur-3xl"></div>

        <div class="absolute -bottom-40 -left-24 h-96 w-96 rounded-full bg-boracay/20 blur-3xl"></div>
    </div>

    <div class="relative min-h-screen">
        <header class="absolute inset-x-0 top-0 z-20">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-5 py-5 sm:px-8 lg:px-12">
                <a href="{{ url('/') }}" class="flex items-center">
                    <img
                        src="{{ asset('images/logo.png') }}"
                        alt="TraMatch"
                        class="h-10 w-auto sm:h-11"
                    >
                </a>

                <a
                    href="{{ url('/') }}"
                    class="rounded-full border border-white/40 px-4 py-2 text-sm font-semibold text-white transition hover:bg-white hover:text-volcanic-teal"
                >
                    Back to home
                </a>
            </div>
        </header>

        <main class="mx-auto grid min-h-screen max-w-7xl lg:grid-cols-[1fr_0.9fr]">
            <section class="hidden flex-col justify-end px-8 pb-20 pt-36 text-white lg:flex lg:px-12">
                <p class="text-xs font-bold uppercase tracking-[0.3em] text-boracay-light">
                    TraMatch
                </p>

                <h1 class="mt-6 max-w-2xl font-display text-6xl font-semibold leading-[0.95] tracking-[-0.05em] xl:text-8xl">
                    Find the places that feel like you.
                </h1>

                <p class="mt-8 max-w-lg text-lg leading-8 text-white/70">
                    Discover destinations through your own travel instincts, then turn the places you love into a trip worth taking.
                </p>

                <div class="mt-10 flex flex-wrap gap-2">
                    <span class="rounded-full bg-white/10 px-4 py-2 text-sm text-white/80 backdrop-blur">
                        Swipe-first discovery
                    </span>

                    <span class="rounded-full bg-white/10 px-4 py-2 text-sm text-white/80 backdrop-blur">
                        Budget-aware
                    </span>

                    <span class="rounded-full bg-philippine-gold px-4 py-2 text-sm font-semibold text-benguet-charcoal">
                        Made locally
                    </span>
                </div>
            </section>

            <section class="flex items-center justify-center px-5 pb-10 pt-28 sm:px-8 lg:px-12 lg:py-32">
                <div class="w-full max-w-md rounded-[2rem] bg-palawan-sand/95 p-6 shadow-2xl shadow-black/20 backdrop-blur sm:p-9">
                    {{ $slot }}
                </div>
            </section>
        </main>
    </div>
</body>
</html>