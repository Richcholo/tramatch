<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <a
                href="{{ route('admin.sources.index') }}"
                class="text-sm font-semibold text-boracay-dark"
            >
                ← All sources
            </a>

            <div class="flex flex-wrap items-center gap-3">
                <a
                    href="{{ route('destinations.show', $source->destination) }}"
                    class="rounded-full bg-boracay px-5 py-3 text-sm font-bold text-benguet-charcoal"
                >
                    View public page →
                </a>

                <a
                    href="{{ route('admin.proposals.index') }}"
                    class="rounded-full border border-boracay px-5 py-3 text-sm font-semibold text-boracay-dark"
                >
                    Review queue
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-8">
        <x-flash-toast />

        <section class="relative overflow-hidden rounded-[2rem] bg-volcanic-teal p-8 text-white shadow-xl sm:p-12">
            <div class="absolute -right-24 -top-24 h-96 w-96 rounded-full bg-boracay/25 blur-3xl"></div>

            <div class="relative">
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-light">
                    {{ $source->source_type === 'official_lgu' ? 'Local government site' : 'Official site' }}
                </p>

                <h1 class="mt-4 font-display text-4xl font-semibold tracking-[-0.04em] text-white sm:text-6xl">
                    {{ $source->destination->name }}
                </h1>

                <p class="mt-3 text-white/70">
                    {{ $source->destination->province }}
                    @if ($source->destination->municipality)
                        , {{ $source->destination->municipality }}
                    @endif
                </p>

                <p class="mt-5 text-white/65">
                    {{ $source->source_name }}
                </p>

                <div class="mt-3 space-y-2">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-light">
                        Page that will be crawled
                    </p>

                    <a
                        href="{{ $source->crawlUrl() }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-block break-all text-white underline decoration-white/40"
                    >
                        {{ $source->crawlUrl() }}
                    </a>
                </div>

                @if ($source->details_url)
                    <div class="mt-4 space-y-1">
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-light">
                            Registered source page
                        </p>

                        <a
                            href="{{ $source->source_url }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-block break-all text-sm text-white/55 underline decoration-white/20"
                        >
                            {{ $source->source_url }}
                        </a>
                    </div>
                @endif

                <div class="mt-8 flex flex-wrap items-center gap-4">
                    <x-source-status-pill :source="$source" />
                    <x-source-reachability-pill :source="$source" />

                    @if ($source->fetchability_note)
                        <span class="text-sm text-white/70">
                            {{ $source->fetchability_note }}
                            @if ($source->fetchability_checked_at)
                                (checked {{ $source->fetchability_checked_at->diffForHumans() }})
                            @endif
                        </span>
                    @endif

                    @if ($source->isActivelyCrawling())
                        <span class="text-sm text-white/70">
                            This crawl is running now. Reload in a moment to see
                            the result.
                        </span>
                    @elseif ($source->isQueued())
                        <span class="text-sm text-white/70">
                            Queued. It starts as soon as a queue worker picks it
                            up — run <code>php artisan dev</code> if nothing is
                            processing the queue.
                        </span>
                    @else
                        <form method="POST" action="{{ route('admin.sources.crawl', $source) }}">
                            @csrf

                            <button class="rounded-full bg-philippine-gold px-5 py-3 text-sm font-bold text-benguet-charcoal">
                                {{ $source->isCrawlStale() ? 'Retry crawl' : 'Crawl now' }}
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </section>

        <section class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-8">
            <h2 class="font-display text-2xl font-semibold text-volcanic-teal">
                Which page should we crawl?
            </h2>

            <p class="mt-2 text-sm leading-6 text-benguet-charcoal/60">
                Most official sites put fees and opening hours on a sub page
                rather than the homepage. Point this at that page — for example
                a “location info”, “visit” or “rates” page — and the crawler
                will read it instead of the homepage.
            </p>

            <form
                method="POST"
                action="{{ route('admin.sources.update', $source) }}"
                class="mt-6"
            >
                @csrf
                @method('PATCH')

                <div class="grid gap-4 md:grid-cols-[1fr_auto] md:items-end">
                    <div>
                        <label
                            for="details_url"
                            class="block text-sm font-medium text-benguet-charcoal"
                        >
                            Crawl page
                        </label>

                        <input
                            id="details_url"
                            name="details_url"
                            type="url"
                            value="{{ old('details_url', $source->details_url) }}"
                            placeholder="{{ $source->source_url }}"
                            class="mt-2 w-full rounded-xl border-boracay-light bg-palawan-sand text-sm focus:border-boracay focus:ring-boracay"
                        >

                        <p class="mt-2 text-xs text-benguet-charcoal/50">
                            Leave empty to crawl
                            {{ $source->source_url }}
                        </p>
                    </div>

                    <button
                        type="submit"
                        class="rounded-full bg-volcanic-teal px-5 py-3 text-sm font-bold text-white"
                    >
                        Save crawl page
                    </button>
                </div>
            </form>

            @if ($source->details_url)
                <p class="mt-4 rounded-2xl bg-boracay-light p-4 text-sm text-benguet-charcoal">
                    Set, so the crawler reads
                    <span class="font-semibold break-all">{{ $source->details_url }}</span>
                    instead of
                    <span class="break-all">{{ $source->source_url }}</span>.
                </p>
            @endif
        </section>

        @if ($feeNotice)
            <section class="rounded-[2rem] border border-philippine-gold/40 bg-philippine-gold/10 p-6 sm:p-8">
                <h2 class="font-display text-2xl font-semibold text-volcanic-teal">
                    Fees found on the page
                </h2>

                <p class="mt-2 text-sm leading-6 text-benguet-charcoal/70">
                    Copied from the page we last fetched. Fees are not applied
                    automatically — compare it with what we store and update the
                    destination if a price has changed.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="rounded-2xl bg-island-white p-4">
                        <p class="text-xs font-bold uppercase tracking-wide text-benguet-charcoal/45">
                            Currently stored
                        </p>

                        <p class="mt-2 text-2xl font-semibold text-volcanic-teal">
                            {{ $source->destination->entrance_fee_display ?: 'Not set' }}
                        </p>

                        @if ($source->destination->fee_operational_notes)
                            <p class="mt-2 text-xs leading-5 text-benguet-charcoal/60">
                                {{ $source->destination->fee_operational_notes }}
                            </p>
                        @endif
                    </div>

                    <div class="rounded-2xl bg-island-white p-4">
                        <p class="text-xs font-bold uppercase tracking-wide text-benguet-charcoal/45">
                            On the page
                        </p>

                        <p class="mt-2 max-h-48 overflow-y-auto whitespace-pre-line text-sm leading-6 text-benguet-charcoal/80">
                            {{ $feeNotice }}
                        </p>
                    </div>
                </div>
            </section>
        @endif

        @if ($source->error_message)
            <section class="rounded-[2rem] border border-red-200 bg-red-50 p-6 sm:p-8">
                <h2 class="font-display text-2xl font-semibold text-red-900">
                    What went wrong
                </h2>

                <p class="mt-3 text-sm leading-6 text-red-800">
                    {{ $source->error_message }}
                </p>
            </section>
        @endif

        <section class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-8">
                <h2 class="text-xs font-bold uppercase tracking-[0.25em] text-boracay-dark">
                    Freshness
                </h2>

                <dl class="mt-6 space-y-5">
                    <div>
                        <dt class="text-sm text-benguet-charcoal/55">
                            Last checked
                        </dt>

                        <dd class="mt-1 font-semibold text-volcanic-teal">
                            @if ($source->last_checked_at)
                                {{ $source->last_checked_at->diffForHumans() }}
                                <span class="font-normal text-benguet-charcoal/50">
                                    ({{ $source->last_checked_at->format('j M Y, g:i a') }})
                                </span>
                            @else
                                Never
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm text-benguet-charcoal/55">
                            Last successful crawl
                        </dt>

                        <dd class="mt-1 font-semibold text-volcanic-teal">
                            @if ($source->last_success_at)
                                {{ $source->last_success_at->diffForHumans() }}
                                <span class="font-normal text-benguet-charcoal/50">
                                    ({{ $source->last_success_at->format('j M Y, g:i a') }})
                                </span>
                            @else
                                Never
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm text-benguet-charcoal/55">
                            Politeness delay
                        </dt>

                        <dd class="mt-1 font-semibold text-volcanic-teal">
                            {{ $source->crawl_delay_seconds }}s between requests
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm text-benguet-charcoal/55">
                            Crawling policy
                        </dt>

                        <dd class="mt-1 font-semibold text-volcanic-teal">
                            @if ($source->robots_checked_at)
                                robots.txt checked
                                {{ $source->robots_checked_at->diffForHumans() }}
                                (server replied {{ $source->robots_status }})
                            @else
                                robots.txt not checked yet
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-8">
                <h2 class="text-xs font-bold uppercase tracking-[0.25em] text-boracay-dark">
                    Crawl history
                </h2>

                <p class="mt-2 text-sm text-benguet-charcoal/60">
                    Every attempt, newest first — including the ones that
                    failed or were refused.
                </p>

                <div class="mt-6 space-y-3">
                    @forelse ($source->crawls()->latest('started_at')->limit(12)->get() as $crawl)
                        <div class="rounded-2xl bg-palawan-sand p-4">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="font-semibold text-volcanic-teal">
                                    {{ $crawl->started_at?->format('j M Y, g:i a') }}
                                </p>

                                <span @class([
                                    'rounded-full px-3 py-1 text-xs font-semibold',
                                    'bg-emerald-100 text-emerald-800' => $crawl->outcome === 'success',
                                    'bg-teal-100 text-teal-800' => $crawl->outcome === 'unchanged',
                                    'bg-orange-100 text-orange-800' => $crawl->outcome === 'blocked',
                                    'bg-red-100 text-red-800' => $crawl->outcome === 'failed',
                                ])>
                                    {{ match ($crawl->outcome) {
                                        'success' => 'Fetched',
                                        'unchanged' => 'No change',
                                        'blocked' => 'Refused by robots.txt',
                                        default => 'Failed',
                                    } }}
                                </span>
                            </div>

                            <p class="mt-2 text-xs text-benguet-charcoal/60">
                                @if ($crawl->attempt > 1)
                                    Attempt {{ $crawl->attempt }} ·
                                @endif
                                @if ($crawl->duration_ms !== null)
                                    {{ number_format($crawl->duration_ms / 1000, 1) }}s ·
                                @endif
                                @if ($crawl->http_status)
                                    HTTP {{ $crawl->http_status }} ·
                                @endif
                                @if ($crawl->bytes)
                                    {{ number_format($crawl->bytes / 1024, 1) }} KB
                                @endif
                            </p>

                            @if ($crawl->outcome === 'success')
                                <p class="mt-2 text-sm">
                                    @if ($crawl->content_changed === false)
                                        <span class="text-benguet-charcoal/60">
                                            Page identical to the last crawl.
                                        </span>
                                    @endif

                                    @if ($crawl->proposals_created > 0)
                                        <span class="font-semibold text-emerald-800">
                                            {{ $crawl->proposals_created }}
                                            new proposal(s) to review.
                                        </span>
                                    @elseif (empty($crawl->extracted_fields))
                                        <span class="text-amber-800">
                                            {{ $crawl->emptyReason() }}
                                        </span>
                                    @else
                                        <span class="text-benguet-charcoal/70">
                                            Read
                                            <strong>{{ implode(', ', $crawl->extracted_fields) }}</strong>
                                            — already proposed or unchanged.
                                        </span>
                                    @endif
                                </p>
                            @endif

                            @if ($crawl->redirected())
                                <p class="mt-2 text-xs leading-5 text-amber-800">
                                    Redirected to
                                    <span class="font-semibold break-all">{{ $crawl->final_url }}</span>
                                    — update this source's URL to remove the redirect.
                                </p>
                            @endif

                            @if ($crawl->error_message)
                                <p class="mt-2 text-xs leading-5 text-red-700">
                                    {{ $crawl->error_message }}
                                </p>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-benguet-charcoal/60">
                            Never crawled. Use “Crawl now” to make the first
                            attempt — it will be recorded here either way.
                        </p>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-8">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <h2 class="text-xs font-bold uppercase tracking-[0.25em] text-boracay-dark">
                    Suggested changes
                </h2>

                <a
                    href="{{ route('admin.proposals.index') }}"
                    class="text-sm font-semibold text-boracay-dark"
                >
                    Review everything →
                </a>
            </div>

            <div class="mt-6 space-y-4">
                @forelse ($source->proposals->take(10) as $proposal)
                    <div class="rounded-2xl bg-palawan-sand p-5">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="font-semibold text-volcanic-teal">
                                {{ \App\Models\DestinationUpdateProposal::fieldLabel($proposal->field_name) }}
                            </p>

                            <div class="flex items-center gap-3">
                                <div class="h-2 w-24 overflow-hidden rounded-full bg-island-white">
                                    <div
                                        class="h-full rounded-full bg-boracay"
                                        style="width: {{ max(0, min(100, (float) $proposal->confidence)) }}%"
                                    ></div>
                                </div>

                                <span class="text-xs text-benguet-charcoal/55">
                                    {{ number_format((float) $proposal->confidence, 0) }}% sure
                                </span>
                            </div>
                        </div>

                        <p class="mt-3 text-sm leading-6 text-benguet-charcoal/70">
                            <span class="text-benguet-charcoal/50">
                                Currently:
                            </span>
                            {{ $proposal->old_value ?: 'nothing set' }}
                            <span class="mx-1">→</span>
                            <span class="font-semibold text-volcanic-teal">
                                {{ $proposal->proposed_value }}
                            </span>
                        </p>

                        <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/45">
                            {{ ucfirst($proposal->status) }}
                        </p>
                    </div>
                @empty
                    <p class="text-sm text-benguet-charcoal/60">
                        Nothing suggested yet. Suggested changes appear here
                        after a crawl, and nothing is published until an
                        administrator approves it.
                    </p>
                @endforelse
            </div>
        </section>
    </div>
</x-app-layout>
