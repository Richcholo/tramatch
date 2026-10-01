@php
    $meta = [
        'fetchable' => ['Readable', 'bg-emerald-100 text-emerald-800'],
        'js_rendered' => ['Needs JavaScript', 'bg-violet-100 text-violet-800'],
        'thin_page' => ['Nearly empty', 'bg-amber-100 text-amber-800'],
        'bot_wall' => ['Site blocks us', 'bg-orange-100 text-orange-800'],
        'robots_blocked' => ['robots.txt says no', 'bg-orange-100 text-orange-800'],
        'dead' => ['Page is gone', 'bg-red-100 text-red-800'],
        'unreachable' => ['Host is down', 'bg-red-100 text-red-800'],
    ];

    $state = (string) ($source->fetchability ?? '');
@endphp

@if ($state !== '')
    <span
        title="{{ $source->fetchability_note }}"
        {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold '.($meta[$state][1] ?? 'bg-slate-100 text-slate-700')]) }}
    >
        {{ $meta[$state][0] ?? $state }}
    </span>
@endif
