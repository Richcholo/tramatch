{{--
    WHERE THE OWNER ARRANGES THE SHARE LINK.

    Reached from the itinerary page's "Share" button. Two visibly different
    states, and neither is a lie:

    - no token yet: say so, and offer to create one. Nothing about an
      uncreated link is claimed to exist.
    - link live: the URL itself, copy-hinted, plus the rotate control that
      kills every link handed out so far.

    The URL is a full href in an <a>, not a JS-only field: clicking it opens
    the public page in a new tab, which is the copy test that matters.
--}}

<x-app-layout>
    <x-slot name="header">
        <a
            href="{{ route('itineraries.show', $itinerary) }}"
            class="text-sm font-semibold text-boracay-dark hover:text-volcanic-teal"
        >
            ← Back to {{ $itinerary->title }}
        </a>
    </x-slot>

    <div class="mx-auto max-w-3xl space-y-8">
        <section class="rounded-[2rem] bg-volcanic-teal p-8 text-white shadow-xl">
            <p class="text-xs font-bold uppercase tracking-[0.3em] text-boracay-light">
                {{ $itinerary->title }}
            </p>

            <h2 class="mt-4 font-display text-3xl font-semibold tracking-[-0.04em]">
                Share this trip as a link.
            </h2>

            <p class="mt-4 max-w-xl text-white/70">
                The link opens a read-only copy of this itinerary — the days, the times and the
                map, with no editing on it. Anybody holding the URL can read it, so send it to
                the people you mean and nobody else.
            </p>

            @if ($shareUrl)
                <div class="mt-7 rounded-xl bg-island-white p-5">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                        Live share link
                    </p>

                    <a
                        href="{{ $shareUrl }}"
                        target="_blank"
                        rel="noopener"
                        class="mt-3 block min-h-11 break-all font-mono text-sm text-volcanic-teal hover:text-boracay-dark"
                    >
                        {{ $shareUrl }}
                    </a>

                    <p class="mt-3 text-xs text-benguet-charcoal/60">
                        Open it and copy from the address bar, or share the link directly — it
                        works with no account and no sign-in.
                    </p>
                </div>

                <form
                    method="POST"
                    action="{{ route('itineraries.share.regenerate', $itinerary) }}"
                    class="mt-6 flex flex-wrap items-center gap-3"
                >
                    @csrf

                    {{-- Destructive by design: rotation stops every link already
                         handed out. The confirmation is labelled as exactly that,
                         never as a bare checkbox. --}}
                    <label class="flex items-start gap-2 text-sm text-white/80">
                        <input
                            type="checkbox"
                            name="confirm"
                            value="1"
                            class="mt-1 h-4 w-4 rounded"
                        >
                        <span>
                            I understand a replaced link cannot be undone — trips shared
                            from the old URL will stop working.
                        </span>
                    </label>

                    <button
                        type="submit"
                        class="rounded-full border border-white/35 px-5 py-3 text-sm font-semibold text-white transition hover:bg-white hover:text-volcanic-teal"
                    >
                        Replace with a new link
                    </button>
                </form>
            @else
                <div class="mt-7 rounded-xl bg-island-white p-5">
                    <p class="text-sm text-benguet-charcoal/70">
                        No share link exists for this trip yet.
                    </p>

                    <form
                        method="POST"
                        action="{{ route('itineraries.share.regenerate', $itinerary) }}"
                        class="mt-4"
                    >
                        @csrf

                        <input type="hidden" name="confirm" value="1">

                        <button
                            type="submit"
                            class="inline-flex min-h-11 items-center justify-center rounded-lg bg-boracay px-5 py-3 text-sm font-bold text-volcanic-teal transition hover:bg-boracay-dark hover:text-white"
                        >
                            Create a share link
                        </button>
                    </form>
                </div>
            @endif
        </section>

        <section class="rounded-[2rem] border border-boracay-light bg-island-white p-6 shadow-sm sm:p-8">
            <h3 class="font-display text-xl font-semibold text-volcanic-teal">
                What a recipient sees
            </h3>

            <ul class="mt-4 space-y-2 text-sm text-benguet-charcoal/70">
                <li>· the trip, the map and every scheduled stop;</li>
                <li>· live updates: edits you save are on the page the next time the link is opened;</li>
                <li>· nothing editable, nothing that saves, and no download of anything but your browser's own copy of the page.</li>
            </ul>

            <a
                href="{{ route('itineraries.show', $itinerary) }}"
                class="mt-6 inline-flex min-h-11 items-center rounded-full border border-boracay px-5 py-3 text-sm font-semibold text-volcanic-teal transition hover:bg-boracay hover:text-white"
            >
                Keep editing instead
            </a>
        </section>
    </div>
</x-app-layout>
