@php
    $meta = [
        'pending' => ['Not crawled yet', 'bg-slate-100 text-slate-700'],
        'queued' => ['Queued — not started', 'bg-indigo-100 text-indigo-800'],
        'crawling' => ['Crawling', 'bg-blue-100 text-blue-800'],
        'stale' => ['Stalled — safe to retry', 'bg-amber-100 text-amber-800'],
        'success' => ['Updated successfully', 'bg-emerald-100 text-emerald-800'],
        'unchanged' => ['No changes found', 'bg-teal-100 text-teal-800'],
        'blocked' => ['Blocked by robots.txt', 'bg-orange-100 text-orange-800'],
        'failed' => ['Failed', 'bg-red-100 text-red-800'],
    ];

    $status = $source->displayStatus();
    [$label, $classes] = $meta[$status] ?? [ucfirst($status), 'bg-slate-100 text-slate-700'];
@endphp

<span
    {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold '.$classes]) }}
    @if ($status === 'crawling')
        data-status-pulse
    @endif
>
    @if ($status === 'crawling')
        <span class="h-2 w-2 animate-pulse rounded-full bg-blue-500"></span>
    @endif

    @if ($status === 'queued')
        <span class="h-2 w-2 animate-pulse rounded-full bg-indigo-500"></span>
    @endif

    {{ $label }}
</span>
