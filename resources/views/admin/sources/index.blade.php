<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
                    Admin / Data freshness
                </p>

                <h1 class="mt-3 font-display text-4xl font-semibold tracking-[-0.04em] text-volcanic-teal sm:text-6xl">
                    Approved sources.
                </h1>
            </div>

            <a
                href="{{ route('admin.proposals.index') }}"
                class="rounded-full border border-boracay px-5 py-3 text-sm font-semibold text-boracay-dark"
            >
                View proposals
            </a>
        </div>
    </x-slot>

    <div class="space-y-8">
        <x-flash-toast />

        <div
            data-crawl-finished
            class="hidden rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900"
        >
            <p class="font-semibold">Crawling finished.</p>

            <p class="mt-1 text-sm">
                The statuses below are up to date. New proposals are waiting in
                the review queue.
            </p>
        </div>

        <section class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-8">
            <form method="GET" action="{{ route('admin.sources.index') }}">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div class="min-w-64 flex-1">
                        <label
                            for="search"
                            class="block text-sm font-medium text-benguet-charcoal"
                        >
                            Search sources
                        </label>

                        <input
                            id="search"
                            name="search"
                            type="search"
                            value="{{ $search }}"
                            placeholder="Destination, source name, province or URL"
                            class="mt-2 w-full rounded-xl border-boracay-light bg-palawan-sand text-sm focus:border-boracay focus:ring-boracay"
                        >
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        @if ($search !== '' || $status !== '')
                            <span class="text-sm text-benguet-charcoal/60">
                                Showing
                                <strong>{{ $sources->total() }}</strong>
                                of {{ $summary['total'] }}
                            </span>

                            <a
                                href="{{ route('admin.sources.index') }}"
                                class="rounded-full border border-boracay px-4 py-2 text-sm font-semibold text-boracay-dark"
                            >
                                Clear filters
                            </a>
                        @endif

                        <button
                            type="submit"
                            class="rounded-full bg-volcanic-teal px-5 py-2 text-sm font-bold text-white"
                        >
                            Search
                        </button>
                    </div>
                </div>
            </form>

            <div class="mt-6 flex flex-wrap items-center gap-2">
                <a
                    href="{{ route('admin.sources.index', array_filter(['search' => $search])) }}"
                    @class([
                        'rounded-full px-4 py-2 text-sm font-semibold transition',
                        'bg-volcanic-teal text-white' => $status === '',
                        'bg-palawan-sand text-benguet-charcoal/70 hover:bg-boracay-light' => $status !== '',
                    ])
                >
                    All
                </a>

                @foreach ([
                    'pending' => 'Not crawled',
                    'queued' => 'Queued',
                    'crawling' => 'Crawling',
                    'stale' => 'Stalled',
                    'failed' => 'Failed',
                    'blocked' => 'Blocked',
                    'success' => 'Updated',
                    'unchanged' => 'No changes',
                ] as $key => $label)
                    <a
                        href="{{ route('admin.sources.index', array_filter(['search' => $search, 'status' => $key])) }}"
                        @class([
                            'rounded-full px-4 py-2 text-sm font-semibold transition',
                            'bg-volcanic-teal text-white' => $status === $key,
                            'bg-palawan-sand text-benguet-charcoal/70 hover:bg-boracay-light' => $status !== $key,
                        ])
                    >
                        {{ $label }}
                        <span class="opacity-60">{{ $summary['counts'][$key] }}</span>
                    </a>
                @endforeach
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="text-xs font-bold uppercase tracking-[0.18em] text-benguet-charcoal/50">
                    Page reachability
                </span>

                <a
                    href="{{ route('admin.sources.index', array_filter(['search' => $search, 'status' => $status])) }}"
                    @class([
                        'rounded-full px-3 py-1 text-xs font-semibold transition',
                        'bg-volcanic-teal text-white' => $reachability === '',
                        'bg-palawan-sand text-benguet-charcoal/70 hover:bg-boracay-light' => $reachability !== '',
                    ])
                >
                    Any
                </a>

                @foreach ([
                    'fetchable' => 'Readable',
                    'js_rendered' => 'Needs JS',
                    'thin_page' => 'Nearly empty',
                    'bot_wall' => 'Blocks us',
                    'dead' => 'Gone',
                    'unreachable' => 'Host down',
                    'unchecked' => 'Not checked',
                ] as $key => $label)
                    <a
                        href="{{ route('admin.sources.index', array_filter(['search' => $search, 'status' => $status, 'reachability' => $key])) }}"
                        @class([
                            'rounded-full px-3 py-1 text-xs font-semibold transition',
                            'bg-volcanic-teal text-white' => $reachability === $key,
                            'bg-palawan-sand text-benguet-charcoal/70 hover:bg-boracay-light' => $reachability !== $key,
                        ])
                    >
                        {{ $label }}
                        <span class="opacity-60">
                            {{ $key === 'unchecked' ? ($summary['reachable']['unchecked'] ?? 0) : ($summary['reachable'][$key] ?? 0) }}
                        </span>
                    </a>
                @endforeach
            </div>

            <p class="mt-4 text-sm text-benguet-charcoal/60">
                <strong>{{ $summary['usable'] }}</strong> of <strong>{{ $summary['total'] }}</strong>
                source pages can be read at all. The rest are on domains that do not exist, or
                refuse automated requests — crawling them again will not produce data.
                Run <code>php artisan sources:check</code> to re-measure.
            </p>

            @if ($search !== '' || $status !== '')
                <p class="mt-4 text-sm text-benguet-charcoal/60">
                    @if ($status !== '')
                        Filtered to <strong>{{ $status }}</strong> sources
                    @endif
                    @if ($search !== '')
                        @if ($status !== '')
                            ·
                        @endif
                        matching <strong>{{ $search }}</strong>
                    @endif
                </p>
            @endif
        </section>

        <section class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-8">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="font-display text-3xl font-semibold text-volcanic-teal">
                        Crawl progress
                    </h2>

                    <p
                        data-progress-line
                        class="mt-2 text-sm text-benguet-charcoal/60"
                    >
                        {{ $summary['finished'] }} of {{ $summary['total'] }} sources checked@if ($summary['queued'] > 0), {{ $summary['queued'] }} waiting in the queue@endif.
                    </p>
                </div>

                @if ($summary['active'] > 0)
                    <span class="inline-flex items-center gap-2 rounded-full bg-blue-100 px-4 py-2 text-sm font-semibold text-blue-800">
                        <span class="h-2 w-2 animate-pulse rounded-full bg-blue-500"></span>
                        Crawling {{ $summary['active'] }} source(s)…
                    </span>
                @elseif ($summary['queued'] > 0)
                    <span class="inline-flex items-center gap-2 rounded-full bg-indigo-100 px-4 py-2 text-sm font-semibold text-indigo-800">
                        <span class="h-2 w-2 animate-pulse rounded-full bg-indigo-500"></span>
                        {{ $summary['queued'] }} queued, waiting for a worker
                    </span>
                @endif
            </div>

            <div class="mt-6 h-3 w-full overflow-hidden rounded-full bg-palawan-sand">
                <div
                    data-progress-bar
                    class="h-full rounded-full bg-boracay transition-all duration-500"
                    style="width: {{ $summary['total'] > 0 ? round($summary['finished'] / $summary['total'] * 100, 2) : 0 }}%"
                ></div>
            </div>

            <dl class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-4">
                @foreach ([
                    'pending' => 'Not crawled',
                    'queued' => 'Queued',
                    'crawling' => 'Crawling',
                    'finished' => 'Finished',
                ] as $key => $label)
                    <div class="rounded-2xl bg-palawan-sand p-4">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/55">
                            {{ $label }}
                        </dt>

                        <dd
                            data-count="{{ $key }}"
                            class="mt-2 text-3xl font-semibold text-volcanic-teal"
                        >
                            @if ($key === 'finished')
                                <span data-finished-count>{{ $summary['finished'] }}</span>
                            @else
                                {{ $summary['counts'][$key] }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>

            <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ([
                    'success' => 'Updated',
                    'unchanged' => 'No changes',
                    'blocked' => 'Blocked',
                    'failed' => 'Failed',
                ] as $key => $label)
                    <div class="rounded-2xl bg-palawan-sand p-4">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-benguet-charcoal/55">
                            {{ $label }}
                        </dt>

                        <dd
                            data-count="{{ $key }}"
                            class="mt-2 text-2xl font-semibold text-volcanic-teal"
                        >
                            {{ $summary['counts'][$key] }}
                        </dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-4 text-sm text-benguet-charcoal/55">
                <span data-waiting-count>{{ $summary['waiting'] }}</span>
                job(s) waiting in the database queue.
            </p>

            @if ($summary['stale'] > 0)
                <p class="mt-4 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">
                    {{ $summary['stale'] }} source(s) stalled — their crawl never
                    finished. Nothing is running for them, so use
                    <strong>Crawl now</strong> on those rows to try again.
                </p>
            @endif
        </section>

        <section class="rounded-[2rem] bg-island-white p-6 shadow-sm ring-1 ring-boracay-light sm:p-8">
            <h2 class="font-display text-3xl font-semibold text-volcanic-teal">
                Crawl queue
            </h2>

            <p class="mt-2 text-sm text-benguet-charcoal/60">
                What is waiting to be crawled, and what is running right now.
                This list refreshes itself.
            </p>

            <p class="mt-3 rounded-2xl bg-palawan-sand px-4 py-3 text-xs leading-5 text-benguet-charcoal/60">
                Crawls run in the background, one at a time, so a source can sit
                in <strong>Waiting</strong> for a few seconds before it moves to
                <strong>Running now</strong>. If the queue stops draining
                altogether, a queue worker is not running —
                <code>php artisan dev</code> starts one along with the server
                and Vite.
            </p>

            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                        Running now
                    </h3>

                    <ul class="mt-4 space-y-3" data-running-list>
                        @forelse ($summary['running'] as $item)
                            <li class="rounded-2xl bg-blue-50 p-4">
                                <p class="font-semibold text-volcanic-teal">
                                    {{ $item['destination'] }}
                                </p>

                                <p class="mt-1 text-xs text-benguet-charcoal/60">
                                    {{ $item['host'] }} · started {{ $item['since'] }}
                                </p>
                            </li>
                        @empty
                            <li class="rounded-2xl bg-palawan-sand p-4 text-sm text-benguet-charcoal/60">
                                Nothing is crawling at the moment.
                            </li>
                        @endforelse
                    </ul>
                </div>

                <div>
                    <h3 class="text-xs font-bold uppercase tracking-[0.2em] text-boracay-dark">
                        Waiting
                    </h3>

                    <ul class="mt-4 max-h-80 space-y-3 overflow-y-auto pr-1" data-queue-list>
                        @forelse ($summary['queue'] as $item)
                            <li class="rounded-2xl bg-indigo-50 p-4">
                                <p class="font-semibold text-volcanic-teal">
                                    {{ $item['destination'] }}
                                </p>

                                <p class="mt-1 text-xs text-benguet-charcoal/60">
                                    {{ $item['host'] }} · queued {{ $item['waiting'] }}
                                </p>
                            </li>
                        @empty
                            <li class="rounded-2xl bg-palawan-sand p-4 text-sm text-benguet-charcoal/60">
                                The queue is empty.
                            </li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </section>

        <form method="POST" action="{{ route('admin.sources.bulk-crawl') }}">
            @csrf

            <input type="hidden" name="filter_search" value="{{ $search }}">
            <input type="hidden" name="filter_status" value="{{ $status }}">
            <input type="hidden" name="filter_reachability" value="{{ $reachability }}">
            <input type="hidden" name="select_all" value="0" data-select-all-input>

            <div
                data-selection-banner
                class="mb-5 hidden rounded-2xl border border-boracay bg-boracay-light p-4 text-benguet-charcoal"
            >
                <p class="text-sm" data-selection-text>
                    All <strong data-page-count>0</strong> sources on this page
                    are selected.
                </p>

                <button
                    type="button"
                    data-select-all-matching
                    class="mt-3 rounded-full bg-volcanic-teal px-4 py-2 text-sm font-bold text-white"
                >
                    Select all <span data-total-count>0</span> matching sources
                </button>
            </div>

            <div class="mb-5 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-sm text-benguet-charcoal/60">
                        Select sources, then queue them. Crawling runs in the
                        background and this page keeps itself up to date.
                    </p>
                </div>

                <button
                    type="submit"
                    class="rounded-full bg-volcanic-teal px-5 py-3 text-sm font-bold text-white transition hover:bg-boracay-dark"
                    onclick="return confirm('Queue the selected sources for crawling?')"
                >
                    Crawl selected
                </button>
            </div>

            <div class="overflow-x-auto rounded-[2rem] bg-island-white shadow-sm ring-1 ring-boracay-light">
                <table class="min-w-full text-left text-sm">
                    <thead class="border-b border-boracay-light bg-palawan-sand">
                        <tr>
                            <th class="px-5 py-4">
                                <input
                                    type="checkbox"
                                    data-select-all
                                    aria-label="Select every source on this page"
                                    class="rounded border-boracay text-boracay focus:ring-boracay"
                                >
                            </th>
                            <th class="px-5 py-4 font-bold text-volcanic-teal">Destination</th>
                            <th class="px-5 py-4 font-bold text-volcanic-teal">Source</th>
                            <th class="px-5 py-4 font-bold text-volcanic-teal">Status</th>
                            <th class="px-5 py-4 font-bold text-volcanic-teal">Proposals</th>
                            <th class="px-5 py-4 text-right font-bold text-volcanic-teal">Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($sources as $source)
                            <tr class="border-b border-boracay-light last:border-0">
                                <td class="px-5 py-4">
                                    <input
                                        type="checkbox"
                                        name="source_ids[]"
                                        value="{{ $source->id }}"
                                        data-source-checkbox
                                        aria-label="Select {{ $source->destination->name }}"
                                        class="rounded border-boracay text-boracay focus:ring-boracay"
                                    >
                                </td>

                                <td class="px-5 py-4">
                                    <p class="font-bold text-volcanic-teal">
                                        {{ $source->destination->name }}
                                    </p>
                                    <p class="mt-1 text-xs text-benguet-charcoal/55">
                                        {{ $source->destination->province }}
                                    </p>
                                    <a
                                        href="{{ route('destinations.show', $source->destination) }}"
                                        class="mt-2 inline-block text-xs font-semibold text-boracay-dark underline decoration-boracay/40"
                                    >
                                        View public page →
                                    </a>
                                </td>

                                <td class="max-w-xs px-5 py-4">
                                    <p class="font-semibold text-benguet-charcoal">
                                        {{ $source->source_name }}
                                    </p>

                                    <a
                                        href="{{ $source->source_url }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="mt-1 block truncate text-xs text-benguet-charcoal/55"
                                    >
                                        {{ $source->source_url }}
                                    </a>

                                    @if ($source->details_url)
                                        <a
                                            href="{{ $source->details_url }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="mt-2 block truncate text-xs font-semibold text-boracay-dark"
                                            title="Page that will be crawled"
                                        >
                                            Crawls {{ $source->details_url }}
                                        </a>
                                    @endif
                                </td>

                                <td class="max-w-sm px-5 py-4">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-source-status-pill :source="$source" />
                                        <x-source-reachability-pill :source="$source" />
                                    </div>

                                    @if ($source->fetchability_note)
                                        <p class="mt-2 text-xs text-benguet-charcoal/55">
                                            {{ $source->fetchability_note }}
                                        </p>
                                    @endif

                                    @if ($source->last_checked_at)
                                        <p class="mt-2 text-xs text-benguet-charcoal/55">
                                            Checked
                                            {{ $source->last_checked_at->diffForHumans() }}
                                        </p>
                                    @endif

                                    @if ($source->error_message)
                                        <p
                                            class="mt-2 text-xs leading-5 text-red-700"
                                            title="{{ $source->error_message }}"
                                        >
                                            {{ \Illuminate\Support\Str::limit($source->error_message, 140) }}
                                        </p>
                                    @elseif ($lastCrawl = $source->lastCrawl())
                                        @if ($lastCrawl->proposals_created > 0)
                                            <p class="mt-2 text-xs font-semibold text-emerald-800">
                                                {{ $lastCrawl->proposals_created }} proposal(s) last run
                                            </p>
                                        @elseif ($lastCrawl->emptyReason())
                                            <p class="mt-2 text-xs font-semibold text-amber-800">
                                                {{ $lastCrawl->emptyReason() }}
                                            </p>
                                        @endif
                                    @endif
                                </td>

                                <td class="px-5 py-4">
                                    @if ($source->pending_proposals_count > 0)
                                        <a
                                            href="{{ route('admin.proposals.index') }}"
                                            class="font-semibold text-boracay-dark"
                                        >
                                            {{ $source->pending_proposals_count }} waiting
                                        </a>
                                    @else
                                        <span class="text-benguet-charcoal/45">None</span>
                                    @endif
                                </td>

                                <td class="px-5 py-4 text-right">
                                    <div class="flex flex-wrap justify-end gap-4">
                                        <a
                                            href="{{ route('admin.sources.show', $source) }}"
                                            class="font-semibold text-boracay-dark"
                                        >
                                            Details
                                        </a>

                                        @if ($source->isActivelyCrawling())
                                            <span class="text-sm text-benguet-charcoal/50">
                                                Crawling…
                                            </span>
                                        @elseif ($source->isQueued())
                                            <span class="text-sm text-benguet-charcoal/50">
                                                Queued
                                            </span>
                                        @else
                                            <button
                                                type="submit"
                                                form="crawl-source-{{ $source->id }}"
                                                class="font-semibold text-volcanic-teal"
                                            >
                                                {{ $source->isCrawlStale() ? 'Retry' : 'Crawl now' }}
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-12 text-center text-benguet-charcoal/60">
                                    @if ($search !== '' || $status !== '')
                                        No sources match your filters.
                                        <a
                                            href="{{ route('admin.sources.index') }}"
                                            class="font-semibold text-boracay-dark underline"
                                        >
                                            Clear them
                                        </a>
                                    @else
                                        No approved sources registered.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </form>

        @foreach ($sources as $source)
            <form
                id="crawl-source-{{ $source->id }}"
                method="POST"
                action="{{ route('admin.sources.crawl', $source) }}"
                class="hidden"
            >
                @csrf
            </form>
        @endforeach

        {{ $sources->links() }}
    </div>

    <script>
        const selectAll = document.querySelector('[data-select-all]');
        const checkboxes = document.querySelectorAll('[data-source-checkbox]');
        const selectAllInput = document.querySelector('[data-select-all-input]');
        const banner = document.querySelector('[data-selection-banner]');
        const pageCount = document.querySelector('[data-page-count]');
        const totalCount = document.querySelector('[data-total-count]');
        const selectAllMatching = document.querySelector('[data-select-all-matching]');
        const matchingTotal = @json($sources->total());
        const pageTotal = @json($sources->count());

        const clearSelection = () => {
            sessionStorage.removeItem('selectAllSources');
            selectAllInput.value = '0';

            if (selectAll) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            }

            checkboxes.forEach((checkbox) => {
                checkbox.checked = false;
            });

            banner.classList.add('hidden');
        };

        const syncBanner = () => {
            const pageSelected = Array.from(checkboxes).every(
                (checkbox) => checkbox.checked
            );

            const everything = sessionStorage.getItem('selectAllSources') === '1';

            if (pageTotal > 0) {
                if (everything) {
                    selectAll.checked = true;
                    selectAll.indeterminate = false;
                } else if (pageSelected) {
                    selectAll.checked = false;
                    selectAll.indeterminate = true;
                } else {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                }
            }

            const allOnPage = pageTotal > 0 && (everything || pageSelected);

            banner.classList.toggle('hidden', !allOnPage);

            if (allOnPage && everything) {
                banner.querySelector('[data-selection-text]').innerHTML =
                    `All <strong>${matchingTotal}</strong> matching sources are selected.`;
                selectAllMatching.textContent = 'Clear selection';
            } else if (allOnPage) {
                banner.querySelector('[data-selection-text]').innerHTML =
                    `All <strong>${pageTotal}</strong> sources on this page are selected.`;
                selectAllMatching.textContent =
                    `Select all ${matchingTotal} matching sources`;
            }
        };

        selectAll?.addEventListener('change', () => {
            const checked = selectAll.checked && !selectAll.indeterminate;

            checkboxes.forEach((checkbox) => {
                checkbox.checked = checked;
            });

            if (!checked) {
                clearSelection();
            }

            syncBanner();
        });

        checkboxes.forEach((checkbox) => {
            checkbox.addEventListener('change', syncBanner);
        });

        selectAllMatching?.addEventListener('click', () => {
            if (sessionStorage.getItem('selectAllSources') === '1') {
                clearSelection();

                return;
            }

            sessionStorage.setItem('selectAllSources', '1');
            selectAllInput.value = '1';
            syncBanner();
        });

        if (sessionStorage.getItem('selectAllSources') === '1') {
            selectAllInput.value = '1';
        }

        if (pageTotal > 0) {
            pageCount.textContent = pageTotal;
            totalCount.textContent = matchingTotal;
        }

        syncBanner();

        document
            .querySelector('form[action$="bulk-crawl"]')
            ?.addEventListener('submit', (event) => {
                if (sessionStorage.getItem('selectAllSources') === '1') {
                    sessionStorage.removeItem('selectAllSources');

                    return;
                }

                const anyChecked = Array.from(checkboxes).some(
                    (checkbox) => checkbox.checked
                );

                if (!anyChecked) {
                    event.preventDefault();
                }
            });

        const finishedBanner = document.querySelector('[data-crawl-finished]');

        if (finishedBanner && sessionStorage.getItem('crawlFinished') === '1') {
            sessionStorage.removeItem('crawlFinished');
            finishedBanner.classList.remove('hidden');
        }

        const statusUrl = @json(route('admin.sources.status'));
        const initial = @json($summary);
        const progressLine = document.querySelector('[data-progress-line]');
        const progressBar = document.querySelector('[data-progress-bar]');
        const finishedCount = document.querySelector('[data-finished-count]');
        const waitingCount = document.querySelector('[data-waiting-count]');

        let active = initial.active;
        let signature = JSON.stringify(initial.counts);

        const renderSummary = (data) => {
            Object.entries(data.counts).forEach(([key, value]) => {
                const cell = document.querySelector(`[data-count="${key}"]`);

                if (cell) {
                    cell.textContent = value;
                }
            });

            if (finishedCount) {
                finishedCount.textContent = data.finished;
            }

            if (waitingCount) {
                waitingCount.textContent = data.waiting;
            }

            if (progressLine) {
                const parts = [`${data.finished} of ${data.total} sources checked`];

                if (data.active > 0) {
                    parts.push(`${data.active} crawling now`);
                }

                if (data.queued > 0) {
                    parts.push(`${data.queued} waiting in the queue`);
                }

                progressLine.textContent = parts.join(' · ') + '.';
            }

            if (progressBar) {
                const percent = data.total > 0
                    ? (data.finished / data.total) * 100
                    : 0;

                progressBar.style.width = `${percent.toFixed(2)}%`;
            }
        };

        const poll = async () => {
            let data;

            try {
                const response = await fetch(statusUrl, {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });

                if (!response.ok) {
                    return;
                }

                data = await response.json();
            } catch {
                return;
            }

            renderSummary(data);

            if (active > 0 && data.active === 0) {
                sessionStorage.setItem('crawlFinished', '1');
                window.location.reload();

                return;
            }

            if (JSON.stringify(data.counts) !== signature) {
                window.location.reload();

                return;
            }

            active = data.active;
        };

        setInterval(poll, initial.busy || initial.queued > 0 ? 4000 : 15000);
    </script>
</x-app-layout>
