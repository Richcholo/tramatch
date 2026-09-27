<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CrawlSourceJob;
use App\Models\DestinationSource;
use App\Services\Crawling\DestinationSourceParser;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SourceController extends Controller
{
    public const STATUSES = [
        'pending',
        'queued',
        'crawling',
        'stale',
        'success',
        'unchanged',
        'blocked',
        'failed',
    ];

    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search')->toString());
        $status = (string) $request->string('status')->toString();

        if (!in_array($status, self::STATUSES, true)) {
            $status = '';
        }

        $reachability = (string) $request->string('reachability')->toString();

        if (
            !in_array($reachability, DestinationSource::FETCHABILITY, true)
            && $reachability !== 'unchecked'
        ) {
            $reachability = '';
        }

        $sources = $this->filteredSources($search, $status, $reachability)
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.sources.index', [
            'sources' => $sources,
            'summary' => $this->summary(),
            'search' => $search,
            'status' => $status,
            'reachability' => $reachability,
        ]);
    }

    private function filteredSources(
        string $search,
        string $status,
        string $reachability = ''
    ): Builder {
        $query = DestinationSource::query()
            ->with('destination')
            ->withCount([
                'proposals as pending_proposals_count' => fn ($query) => $query->where('status', 'pending'),
            ]);

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function (Builder $query) use ($like) {
                $query->where('source_name', 'like', $like)
                    ->orWhere('source_url', 'like', $like)
                    ->orWhere('details_url', 'like', $like)
                    ->orWhereHas('destination', function (Builder $query) use ($like) {
                        $query->where('name', 'like', $like)
                            ->orWhere('province', 'like', $like)
                            ->orWhere('municipality', 'like', $like);
                    });
            });
        }

        if ($status === 'stale') {
            $query->whereIn('status', ['crawling', 'queued'])
                ->where($this->staleConstraint());
        } elseif ($status !== '') {
            $query->where('status', $status);

            if (in_array($status, ['crawling', 'queued'], true)) {
                $query->whereNot($this->staleConstraint());
            }
        }

        if ($reachability === 'unchecked') {
            $query->whereNull('fetchability');
        } elseif ($reachability !== '') {
            $query->where('fetchability', $reachability);
        }

        return $query;
    }

    private function staleConstraint(): Closure
    {
        $threshold = now()->subMinutes(
            (int) config('crawling.stale_after_minutes', 15)
        );

        return function (Builder $query) use ($threshold) {
            $query->whereNull('last_checked_at')
                ->orWhere('last_checked_at', '<', $threshold);
        };
    }

    public function show(DestinationSource $source): View
    {
        $source->load([
            'destination',
            'snapshots' => fn ($query) => $query->latest('fetched_at')->limit(10),
            'proposals' => fn ($query) => $query->latest(),
        ]);

        return view('admin.sources.show', [
            'source' => $source,
            'summary' => $this->summary(),
            'feeNotice' => $this->feeNoticeFor($source),
        ]);
    }

    public function update(
        Request $request,
        DestinationSource $source
    ): RedirectResponse {
        $validated = $request->validate([
            'details_url' => ['nullable', 'url', 'max:1000'],
        ]);

        $source->update([
            'details_url' => trim((string) ($validated['details_url'] ?? '')) ?: null,
        ]);

        return back()->with(
            'toast_message',
            'Crawl page saved. Crawl the source again to use it.'
        );
    }

    private function feeNoticeFor(DestinationSource $source): ?string
    {
        $snapshot = $source->snapshots->first();

        if (!$snapshot) {
            return null;
        }

        $disk = Storage::disk('local');
        $path = (string) $snapshot->raw_content_path;

        if ($path === '' || !$disk->exists($path)) {
            return null;
        }

        return app(DestinationSourceParser::class)->feeNotice(
            $disk->get($path)
        );
    }

    public function status(): JsonResponse
    {
        return response()->json($this->summary());
    }

    public function crawl(DestinationSource $source): RedirectResponse
    {
        if ($source->hasCrawlInFlight()) {
            $name = $source->destination?->name ?? 'That source';

            return back()->with(
                'toast_message',
                $name.' is already '.
                    ($source->isQueued() ? 'queued' : 'being crawled').'.'
            );
        }

        return $this->dispatch([$source]);
    }

    public function bulkCrawl(Request $request): RedirectResponse
    {
        $search = trim((string) $request->string('filter_search')->toString());
        $status = (string) $request->string('filter_status')->toString();
        $reachability = (string) $request->string('filter_reachability')->toString();

        if (!in_array($status, self::STATUSES, true)) {
            $status = '';
        }

        if (!in_array($reachability, DestinationSource::FETCHABILITY, true)) {
            $reachability = $reachability === 'unchecked' ? 'unchecked' : '';
        }

        $selectAll = $request->boolean('select_all');

        if ($selectAll) {
            $ids = $this->filteredSources($search, $status, $reachability)->pluck('id');

            $sources = DestinationSource::with('destination')
                ->whereIn('id', $ids)
                ->get();

            if ($sources->isEmpty()) {
                return back()->with(
                    'toast_message',
                    'No sources match your filters, so there was nothing to queue.'
                );
            }

            return $this->dispatch($sources);
        }

        $validated = $request->validate([
            'source_ids' => ['required', 'array', 'min:1'],
            'source_ids.*' => ['integer', 'distinct', 'exists:destination_sources,id'],
        ]);

        $sources = DestinationSource::with('destination')
            ->whereIn('id', $validated['source_ids'])
            ->get();

        $queueable = $sources->reject(
            fn ($source) => $source->hasCrawlInFlight()
        )->values();

        if ($queueable->isEmpty()) {
            return back()->with(
                'toast_message',
                'Nothing to crawl: every selected source is already queued or running.'
            );
        }

        return $this->dispatch($queueable, $sources->count() - $queueable->count());
    }

    private function dispatch(iterable $sources, int $skipped = 0): RedirectResponse
    {
        $sources = collect($sources)->values();

        foreach ($sources as $source) {
            if ($source->hasCrawlInFlight()) {
                $skipped++;

                continue;
            }

            $source->update([
                'status' => 'queued',
                'last_checked_at' => now(),
                'error_message' => null,
            ]);

            CrawlSourceJob::dispatch($source->id);
        }

        $count = $sources->count() - $skipped;

        $message = $count === 1
            ? 'Queued '.($sources->first()->destination?->name ?? 'source').' for crawling.'
            : "Queued {$count} sources for crawling.";

        if ($skipped > 0) {
            $message .= " {$skipped} already queued or running.";
        }

        return back()->with('toast_message', $message.' Watch the queue below.');
    }

    private function summary(): array
    {
        $counts = DestinationSource::statusCounts();

        foreach ([
            'pending',
            'queued',
            'crawling',
            'stale',
            'success',
            'unchanged',
            'blocked',
            'failed',
        ] as $status) {
            $counts[$status] ??= 0;
        }

        $active = $counts['crawling'];
        $queued = $counts['queued'];

        $finished = $counts['success']
            + $counts['unchanged']
            + $counts['blocked']
            + $counts['failed'];

        $waiting = DB::table('jobs')->count();

        $reachable = DestinationSource::fetchabilityCounts();

        return [
            'counts' => $counts,
            'stale' => $counts['stale'],
            'active' => $active,
            'queued' => $queued,
            'finished' => $finished,
            'total' => array_sum($counts),
            'busy' => $active > 0,
            'waiting' => $waiting,
            'reachable' => $reachable,
            'usable' => ($reachable[DestinationSource::FETCHABLE] ?? 0)
                + ($reachable[DestinationSource::JS_RENDERED] ?? 0),
            'queue' => $this->queueList(),
            'running' => $this->runningList(),
        ];
    }

    private function queueList(): array
    {
        return DestinationSource::query()
            ->with('destination:id,name')
            ->where('status', 'queued')
            ->orderBy('last_checked_at')
            ->get()
            ->reject(fn ($source) => $source->isCrawlStale())
            ->map(fn ($source) => [
                'id' => $source->id,
                'destination' => $source->destination?->name ?? 'Unknown destination',
                'host' => $this->hostOf($source->source_url),
                'waiting' => $source->last_checked_at?->diffForHumans() ?? 'just now',
            ])
            ->all();
    }

    private function runningList(): array
    {
        return DestinationSource::query()
            ->with('destination:id,name')
            ->where('status', 'crawling')
            ->orderBy('last_checked_at')
            ->get()
            ->reject(fn ($source) => $source->isCrawlStale())
            ->map(fn ($source) => [
                'id' => $source->id,
                'destination' => $source->destination?->name ?? 'Unknown destination',
                'host' => $this->hostOf($source->source_url),
                'since' => $source->last_checked_at?->diffForHumans() ?? 'just now',
            ])
            ->all();
    }

    private function hostOf(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: $url);
    }
}
