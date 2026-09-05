@php
    $isAuthenticated = auth()->check();
    $hasProfile = $isAuthenticated && auth()->user()->travelProfile;

    if (!$isAuthenticated) {
        $primaryHref = route('register');
        $primaryLabel = 'Start planning';
    } elseif (!$hasProfile) {
        $primaryHref = route('preferences.edit');
        $primaryLabel = 'Set your preferences';
    } elseif (Route::has('discover.index')) {
        $primaryHref = route('discover.index');
        $primaryLabel = 'Continue discovering';
    } else {
        $primaryHref = route('dashboard');
        $primaryLabel = 'Open TraMatch';
    }
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0B252B">
    <meta name="description" content="TraMatch helps you discover destinations and build travel plans around the places you actually want to visit.">

    <link rel="icon" href="{{ asset('images/logo.png') }}">
    <link rel="manifest" href="/manifest.webmanifest">

    <title>TraMatch — Find the places that feel like you</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-palawan-sand text-benguet-charcoal antialiased">
    <header class="absolute inset-x-0 top-0 z-30">
        <div class="mx-auto flex max-w-[1600px] items-center justify-between px-5 py-5 sm:px-8 lg:px-12">
            <a href="{{ url('/') }}" class="flex items-center gap-3">
                <img
                    src="{{ asset('images/logo.png') }}"
                    alt="TraMatch"
                    class="h-9 w-auto object-contain sm:h-11"
                >
            </a>

            <div class="flex items-center gap-3">
                @if ($isAuthenticated)
                    <a
                        href="{{ route('dashboard') }}"
                        class="hidden rounded-full border border-white/40 px-4 py-2 text-sm font-semibold text-white transition hover:bg-white hover:text-volcanic-teal sm:inline-flex"
                    >
                        Dashboard
                    </a>
                @else
                    @if (Route::has('login'))
                        <a
                            href="{{ route('login') }}"
                            class="hidden text-sm font-semibold text-white/90 transition hover:text-white sm:inline-flex"
                        >
                            Log in
                        </a>
                    @endif
                @endif

                <a
                    href="{{ $primaryHref }}"
                    class="rounded-full bg-philippine-gold px-4 py-2 text-sm font-bold text-benguet-charcoal shadow-lg shadow-black/10 transition hover:-translate-y-0.5 hover:bg-white"
                >
                    {{ $primaryLabel }}
                </a>
            </div>
        </div>
    </header>

    <main>
        <section
            id="hero"
            data-bg="#0B252B"
            data-theme-color="#0B252B"
            class="relative isolate min-h-[760px] overflow-hidden text-white lg:min-h-screen"
        >
            <div
                data-hero-art
                class="tm-hero-art absolute inset-0 opacity-95"
            ></div>

            <div class="absolute inset-0 bg-gradient-to-b from-black/30 via-transparent to-volcanic-teal/80"></div>

            <div class="pointer-events-none absolute right-5 top-1/2 hidden -translate-y-1/2 select-none text-[0.65rem] font-bold uppercase tracking-[0.4em] text-white/40 xl:block [writing-mode:vertical-rl]">
                TraMatch — Discover the Philippines
            </div>

            <div class="relative mx-auto grid min-h-[760px] max-w-[1600px] items-end gap-12 px-5 pb-12 pt-32 sm:px-8 sm:pb-16 lg:min-h-screen lg:grid-cols-[1.1fr_0.9fr] lg:items-center lg:px-12 lg:pb-20">
                <div class="max-w-3xl">
                    <div class="animate-reveal mb-8 flex items-center gap-4 text-xs font-semibold uppercase tracking-[0.28em] text-boracay-light">
                        <span class="h-2 w-2 rounded-full bg-philippine-gold"></span>
                        Local travel, made personal
                    </div>

                    <h1 class="animate-reveal max-w-4xl text-6xl font-semibold leading-[0.9] tracking-[-0.06em] text-white sm:text-8xl lg:text-[9rem]">
                        Find the
                        <span class="inline-block -mb-2 overflow-hidden pb-2 align-bottom">
                            <span
                                data-rotator
                                data-words='["beach","mountain","food trip","island","weekend"]'
                                class="inline-block text-philippine-gold"
                            >beach</span>
                        </span>
                        that feels like you.
                    </h1>

                    <p class="animate-reveal-delay mt-8 max-w-xl text-base leading-7 text-white/75 sm:text-lg">
                        TraMatch turns your travel taste into a swipe-first discovery experience for your next Luzon adventure.
                    </p>

                    <div class="animate-reveal-delay mt-9 flex flex-wrap items-center gap-4">
                        <a
                            href="{{ $primaryHref }}"
                            class="inline-flex items-center gap-3 rounded-full bg-white px-6 py-3 font-bold text-volcanic-teal transition hover:-translate-y-1 hover:bg-philippine-gold"
                        >
                            {{ $primaryLabel }}
                            <span aria-hidden="true">↗</span>
                        </a>

                        <a
                            href="#how-it-works"
                            class="inline-flex items-center gap-3 rounded-full border border-white/35 px-6 py-3 font-semibold text-white transition hover:bg-white/10"
                        >
                            See how it works
                            <span aria-hidden="true">↓</span>
                        </a>
                    </div>
                </div>

                <div class="animate-reveal-delay relative hidden min-h-[520px] lg:block">
                    <div data-depth="1.2" class="absolute right-[10%] top-[6%] z-10">
                        <div class="animate-float w-56 rotate-6 rounded-[2rem] bg-white p-3 text-volcanic-teal shadow-2xl shadow-black/25">
                            <div class="flex h-52 flex-col justify-between rounded-[1.4rem] bg-gradient-to-br from-philippine-gold via-orange-400 to-red-400 p-4 text-white">
                                <span class="text-[0.65rem] font-bold uppercase tracking-[0.2em] text-white/80">
                                    Match 98%
                                </span>

                                <div>
                                    <p class="text-lg font-bold leading-tight">
                                        Batanes
                                    </p>

                                    <p class="text-xs text-white/80">
                                        Rolling hills · Golden hour
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center justify-between px-2 pb-1 pt-3">
                                <span class="flex h-9 w-9 items-center justify-center rounded-full border border-benguet-charcoal/15 text-sm text-benguet-charcoal/60">
                                    ✕
                                </span>

                                <span class="text-[0.65rem] font-bold uppercase tracking-[0.18em] text-benguet-charcoal/50">
                                    Swipe
                                </span>

                                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-boracay text-sm text-white">
                                    ♥
                                </span>
                            </div>
                        </div>
                    </div>

                    <div data-depth="0.7" class="absolute bottom-[10%] left-[2%]">
                        <div class="animate-float-delay w-60 -rotate-6 rounded-[2rem] bg-palawan-sand p-3 text-volcanic-teal shadow-2xl shadow-black/25">
                            <div class="flex h-56 flex-col justify-between rounded-[1.4rem] bg-gradient-to-br from-boracay via-cyan-500 to-volcanic-teal p-4 text-white">
                                <span class="text-[0.65rem] font-bold uppercase tracking-[0.2em] text-white/80">
                                    Match 91%
                                </span>

                                <div>
                                    <p class="text-lg font-bold leading-tight">
                                        La Union
                                    </p>

                                    <p class="text-xs text-white/80">
                                        Surf · Sunsets · Cafés
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center justify-between px-2 pb-1 pt-3">
                                <span class="flex h-9 w-9 items-center justify-center rounded-full border border-benguet-charcoal/15 text-sm text-benguet-charcoal/60">
                                    ✕
                                </span>

                                <span class="text-[0.65rem] font-bold uppercase tracking-[0.18em] text-benguet-charcoal/50">
                                    Swipe
                                </span>

                                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-boracay text-sm text-white">
                                    ♥
                                </span>
                            </div>
                        </div>
                    </div>

                    <div
                        data-depth="0.4"
                        class="absolute bottom-[4%] right-[2%] flex h-20 w-20 items-center justify-center rounded-full bg-philippine-gold text-center text-xs font-bold leading-4 text-benguet-charcoal shadow-xl"
                    >
                        <span class="block">
                            Swipe.<br>
                            Match.<br>
                            Go.
                        </span>
                    </div>
                </div>
            </div>

            <div class="absolute bottom-6 left-5 hidden items-center gap-4 text-xs font-semibold uppercase tracking-[0.22em] text-white/60 sm:flex lg:left-12">
                <span class="text-philippine-gold">00</span>
                <span class="h-px w-12 bg-white/40"></span>
                <span>Scroll</span>
            </div>
        </section>

        <div
            data-bg="#FFFFFF"
            class="overflow-hidden border-y border-boracay-light py-5"
            aria-hidden="true"
        >
            <div class="animate-marquee flex w-max items-center gap-10 whitespace-nowrap text-sm font-bold uppercase tracking-[0.3em] text-volcanic-teal">
                @for ($i = 0; $i < 2; $i++)
                    <span>Discover locally</span>
                    <span class="text-philippine-gold">✦</span>
                    <span>Travel personally</span>
                    <span class="text-philippine-gold">✦</span>
                    <span>Swipe. Match. Go.</span>
                    <span class="text-philippine-gold">✦</span>
                    <span>Made for Filipino travelers</span>
                    <span class="text-philippine-gold">✦</span>
                @endfor
            </div>
        </div>

        <section id="about" data-bg="#FDFBF7">
            <div class="mx-auto max-w-[1600px] px-5 py-24 sm:px-8 lg:px-12 lg:py-36">
                <div class="grid gap-12 lg:grid-cols-[0.45fr_1fr] lg:gap-20">
                    <div data-reveal>
                        <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                            01 / The idea
                        </p>

                        <div
                            data-line
                            class="mt-8 hidden h-px w-24 bg-philippine-gold lg:block"
                        ></div>
                    </div>

                    <div>
                        <h2
                            data-reveal
                            data-parallax="6"
                            class="max-w-5xl text-4xl font-semibold leading-[1.05] tracking-[-0.04em] text-volcanic-teal sm:text-6xl"
                        >
                            Not another list of places. A
                            <span class="text-boracay-dark">better way</span>
                            to discover where you want to go.
                        </h2>

                        <div class="mt-10 grid gap-8 border-t border-boracay-light pt-8 sm:grid-cols-2">
                            <p data-reveal class="leading-7 text-benguet-charcoal/75">
                                Travel planning usually starts with too many tabs, too many opinions, and no clear sense of what fits you.
                            </p>

                            <p
                                data-reveal
                                data-reveal-delay="0.15"
                                class="leading-7 text-benguet-charcoal/75"
                            >
                                TraMatch starts with your taste, then lets your instincts lead. Swipe through destinations, keep what feels right, and turn your choices into a trip.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="how-it-works" data-bg="#FFFFFF">
            <div class="mx-auto max-w-[1600px] px-5 py-24 sm:px-8 lg:px-12 lg:py-32">
                <div data-reveal class="flex flex-wrap items-end justify-between gap-8">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                            02 / The ritual
                        </p>

                        <h2 class="mt-4 max-w-3xl text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal sm:text-6xl">
                            Three small decisions. One trip that feels like yours.
                        </h2>
                    </div>

                    <span class="tm-gold-badge">
                        Swipe-first planning
                    </span>
                </div>

                <div class="mt-16 flex flex-col gap-6">
                    <article class="tm-step rounded-[2rem] border border-boracay-light bg-palawan-sand shadow-xl shadow-volcanic-teal/5" style="--i: 0">
                        <div class="grid min-h-[20rem] gap-8 p-8 sm:p-12 md:grid-cols-[auto_1fr_auto] md:items-center md:gap-16">
                            <span class="text-7xl font-semibold leading-none tracking-[-0.05em] text-philippine-gold sm:text-8xl">
                                01
                            </span>

                            <div>
                                <h3 class="text-3xl font-bold tracking-[-0.02em] text-volcanic-teal sm:text-4xl">
                                    Set a direction.
                                </h3>

                                <p class="mt-4 max-w-xl leading-7 text-benguet-charcoal/70">
                                    Tell TraMatch your budget, group, trip length, and the kinds of places you naturally gravitate toward.
                                </p>
                            </div>

                            <div class="flex flex-wrap gap-2 md:max-w-[13rem]">
                                <span class="rounded-full border border-boracay/25 bg-island-white px-3 py-1.5 text-xs font-semibold text-benguet-charcoal/70">
                                    ₱5k budget
                                </span>

                                <span class="rounded-full border border-boracay/25 bg-island-white px-3 py-1.5 text-xs font-semibold text-benguet-charcoal/70">
                                    3 days
                                </span>

                                <span class="rounded-full border border-boracay/25 bg-island-white px-3 py-1.5 text-xs font-semibold text-benguet-charcoal/70">
                                    Barkada of 4
                                </span>

                                <span class="rounded-full border border-boracay/25 bg-island-white px-3 py-1.5 text-xs font-semibold text-benguet-charcoal/70">
                                    Beach + food
                                </span>
                            </div>
                        </div>
                    </article>

                    <article class="tm-step rounded-[2rem] border border-boracay-light bg-palawan-sand shadow-xl shadow-volcanic-teal/5" style="--i: 1">
                        <div class="grid min-h-[20rem] gap-8 p-8 sm:p-12 md:grid-cols-[auto_1fr_auto] md:items-center md:gap-16">
                            <span class="text-7xl font-semibold leading-none tracking-[-0.05em] text-philippine-gold sm:text-8xl">
                                02
                            </span>

                            <div>
                                <h3 class="text-3xl font-bold tracking-[-0.02em] text-volcanic-teal sm:text-4xl">
                                    Follow your instinct.
                                </h3>

                                <p class="mt-4 max-w-xl leading-7 text-benguet-charcoal/70">
                                    Swipe through destination cards. Like what sparks something. Pass on what does not.
                                </p>
                            </div>

                            <div class="flex items-center gap-4">
                                <span class="flex h-14 w-14 items-center justify-center rounded-full border border-benguet-charcoal/15 text-xl text-benguet-charcoal/60">
                                    ✕
                                </span>

                                <span class="flex h-14 w-14 items-center justify-center rounded-full bg-boracay text-xl text-white shadow-lg shadow-boracay/30">
                                    ♥
                                </span>
                            </div>
                        </div>
                    </article>

                    <article class="tm-step rounded-[2rem] border border-boracay-light bg-palawan-sand shadow-xl shadow-volcanic-teal/5" style="--i: 2">
                        <div class="grid min-h-[20rem] gap-8 p-8 sm:p-12 md:grid-cols-[auto_1fr_auto] md:items-center md:gap-16">
                            <span class="text-7xl font-semibold leading-none tracking-[-0.05em] text-philippine-gold sm:text-8xl">
                                03
                            </span>

                            <div>
                                <h3 class="text-3xl font-bold tracking-[-0.02em] text-volcanic-teal sm:text-4xl">
                                    Make it a plan.
                                </h3>

                                <p class="mt-4 max-w-xl leading-7 text-benguet-charcoal/70">
                                    Your liked destinations become the foundation for a practical, personalized itinerary.
                                </p>
                            </div>

                            <div class="w-full max-w-[15rem] space-y-3 text-xs font-semibold text-benguet-charcoal/70">
                                <div class="flex items-center gap-3">
                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-philippine-gold"></span>
                                    09:00 — Breakfast in Tagaytay
                                </div>

                                <div class="flex items-center gap-3">
                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-boracay"></span>
                                    13:00 — Taal viewpoint
                                </div>

                                <div class="flex items-center gap-3">
                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-volcanic-teal"></span>
                                    17:30 — Sunset drive home
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <section
            id="destinations"
            data-bg="#FDFBF7"
            class="overflow-hidden py-24 lg:py-32"
        >
            <div class="mx-auto max-w-[1600px] px-5 sm:px-8 lg:px-12">
                <div data-reveal class="mx-auto max-w-3xl text-center">
                    <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                        03 / The places
                    </p>

                    <h2 class="mt-4 text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal sm:text-6xl">
                        Start close to home.
                    </h2>

                    @if (Route::has('destinations.index'))
                        <a
                            href="{{ route('destinations.index') }}"
                            class="mt-7 inline-flex font-semibold text-boracay-dark transition hover:text-volcanic-teal"
                        >
                            Browse all destinations →
                        </a>
                    @endif
                </div>
            </div>

            <div
                data-drag-rail
                class="tm-drag-rail mt-14 overflow-x-auto pb-4"
            >
                <div class="flex w-max min-w-full justify-start gap-5 px-5 md:justify-center sm:px-8 lg:px-12">
                    @forelse ($featuredDestinations as $index => $destination)
                        <a
                            href="{{ route('destinations.show', $destination) }}"
                            data-reveal
                            data-reveal-delay="{{ $index * 0.12 }}"
                            draggable="false"
                            class="group relative min-h-[460px] w-[80vw] shrink-0 overflow-hidden rounded-[2rem] bg-volcanic-teal sm:w-[26rem]"
                        >
                            @if ($destination->image_url)
                                <img
                                    src="{{ $destination->image_url }}"
                                    alt="{{ $destination->name }}"
                                    draggable="false"
                                    loading="lazy"
                                    class="absolute inset-0 h-full w-full object-cover transition duration-700 group-hover:scale-105"
                                >

                                <div class="absolute inset-0 bg-gradient-to-t from-volcanic-teal/90 via-volcanic-teal/10 to-transparent"></div>
                            @else
                                <div class="absolute inset-0 bg-gradient-to-br from-boracay via-cyan-500 to-volcanic-teal"></div>
                                <div class="absolute inset-0 bg-gradient-to-t from-volcanic-teal/80 via-transparent to-transparent"></div>
                            @endif

                            <div class="relative flex h-full flex-col justify-between p-6 text-white">
                                <div class="flex items-start justify-between">
                                    <span class="text-sm font-semibold text-white/70">
                                        0{{ $index + 1 }}
                                    </span>

                                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-white/15 backdrop-blur transition group-hover:bg-philippine-gold group-hover:text-benguet-charcoal">
                                        ↗
                                    </span>
                                </div>

                                <div>
                                    <p class="text-sm text-white/70">
                                        {{ $destination->municipality }}, {{ $destination->province }}
                                    </p>

                                    <h3 class="mt-2 text-2xl font-bold">
                                        {{ $destination->name }}
                                    </h3>

                                    <div class="mt-4 flex flex-wrap gap-2">
                                        @foreach ($destination->tags->take(3) as $tag)
                                            <span class="rounded-full bg-white/15 px-3 py-1 text-xs text-white backdrop-blur">
                                                {{ $tag->name }}
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </a>
                    @empty
                        <div class="w-full shrink-0 rounded-[2rem] bg-volcanic-teal p-8 text-white">
                            <p class="text-lg font-semibold">
                                Your destination collection is getting ready.
                            </p>

                            <p class="mt-2 text-white/70">
                                Seed the catalog to reveal places on the front page.
                            </p>
                        </div>
                    @endforelse
                </div>
            </div>
        </section>

        <section
            data-bg="#0B252B"
            data-theme-color="#0B252B"
            class="relative overflow-hidden text-white"
        >
            <div class="overflow-hidden border-b border-white/10 py-5" aria-hidden="true">
                <div class="animate-marquee flex w-max items-center gap-10 whitespace-nowrap text-sm font-bold uppercase tracking-[0.3em] text-white/70">
                    @for ($i = 0; $i < 2; $i++)
                        <span>A trip to take</span>
                        <span class="text-philippine-gold">✦</span>
                        <span>A place to return to</span>
                        <span class="text-philippine-gold">✦</span>
                        <span>Year after year</span>
                        <span class="text-philippine-gold">✦</span>
                    @endfor
                </div>
            </div>

            <div class="mx-auto max-w-[1600px] px-5 py-24 sm:px-8 lg:px-12 lg:py-36">
                <div class="grid gap-12 lg:grid-cols-[0.7fr_1.3fr] lg:gap-24">
                    <div data-reveal>
                        <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-light">
                            04 / Your next move
                        </p>

                        <div data-line class="mt-8 h-px w-24 bg-philippine-gold"></div>
                    </div>

                    <div>
                        <h2
                            data-reveal
                            data-parallax="8"
                            class="max-w-5xl text-5xl font-semibold leading-[0.95] tracking-[-0.05em] sm:text-7xl"
                        >
                            The island you've always wanted.
                            <span class="text-philippine-gold">
                                Yours this year.
                            </span>
                        </h2>

                        <p
                            data-reveal
                            data-reveal-delay="0.1"
                            class="mt-8 max-w-xl leading-7 text-white/70"
                        >
                            Built for solo escapes, barkada trips, and everything in between.
                        </p>

                        <div
                            data-reveal
                            data-reveal-delay="0.2"
                            class="mt-10 flex flex-wrap items-center gap-4"
                        >
                            <a
                                href="{{ $primaryHref }}"
                                class="inline-flex items-center gap-3 rounded-full bg-philippine-gold px-6 py-3 font-bold text-benguet-charcoal transition hover:-translate-y-1 hover:bg-white"
                            >
                                {{ $primaryLabel }}
                                <span aria-hidden="true">↗</span>
                            </a>

                            <span class="text-sm text-white/55">
                                Made for local travelers.
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer
        data-bg="#FDFBF7"
        data-theme-color="#FDFBF7"
        class="overflow-hidden"
    >
        <div class="mx-auto max-w-[1600px] px-5 pt-16 sm:px-8 lg:px-12">
            <div class="flex flex-wrap items-center justify-between gap-5 text-sm text-benguet-charcoal/60">
                <span>© {{ date('Y') }} TraMatch</span>
                <span>Discover locally. Travel personally.</span>
                <a
                    href="#top"
                    class="font-semibold text-boracay-dark transition hover:text-volcanic-teal"
                >
                    To top ↑
                </a>
            </div>

            <div
                data-wordmark
                class="pointer-events-none mt-10 -mb-[3vw] select-none text-center text-[18vw] font-semibold leading-[0.8] tracking-[-0.06em] text-boracay-light"
                aria-hidden="true"
            >
                TraMatch
            </div>
        </div>
    </footer>
</body>
</html>