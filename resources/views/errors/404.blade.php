{{--
    404, IN THE APP'S OWN LAYOUT.

    Without this, every mistyped or stale URL -- `/destinations/locations`, an
    old share link, a destination that has since been archived -- renders
    Laravel's bare error page: no navigation, no logo, no theme toggle, no way
    back, and (with APP_DEBUG on locally) a debug screen in the middle of a
    design that is otherwise entirely themed. It reads as a broken site rather
    than as a page that does not exist.

    So the error is a themed page like every other one. It is deliberately NOT
    dark: this one is an ordinary content page on the theme's own ground, which
    is the same ground every other page follows.

    THE LINKS ARE THE POINT. "Back to destinations" and "Back to home" are both
    real routes, not placeholders -- an error page whose only action is a dead
    end is how a visitor ends up convinced the whole application is broken.
--}}
<x-app-layout>
    <div class="mx-auto max-w-2xl py-16 text-center sm:py-24">
        <p class="text-xs font-bold uppercase tracking-[0.3em] text-philippine-gold">
            404
        </p>

        <h1 class="mt-4 font-display text-4xl font-semibold leading-tight text-ink-strong sm:text-5xl">
            There is nothing at this address.
        </h1>

        <p class="mx-auto mt-5 max-w-lg text-base leading-7 text-ink/70">
            The page may have been moved, or the place may have been archived.
            Everything that still exists is one click away.
        </p>

        <div class="mt-9 flex flex-wrap items-center justify-center gap-3">
            @if (Route::has('destinations.index'))
                <a href="{{ route('destinations.index') }}" class="tm-primary-button">
                    Browse destinations
                </a>
            @endif

            <a
                href="{{ url('/') }}"
                class="inline-flex min-h-11 items-center justify-center rounded-md border border-line px-5 py-2.5 text-sm font-semibold text-ink transition hover:bg-line"
            >
                Back to home
            </a>
        </div>
    </div>
</x-app-layout>