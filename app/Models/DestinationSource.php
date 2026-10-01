<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DestinationSource extends Model
{
    public const FETCHABLE = 'fetchable';

    public const THIN_PAGE = 'thin_page';

    public const JS_RENDERED = 'js_rendered';

    public const BOT_WALL = 'bot_wall';

    public const ROBOTS_BLOCKED = 'robots_blocked';

    public const DEAD = 'dead';

    public const UNREACHABLE = 'unreachable';

    public const FETCHABILITY = [
        self::FETCHABLE,
        self::JS_RENDERED,
        self::THIN_PAGE,
        self::BOT_WALL,
        self::ROBOTS_BLOCKED,
        self::DEAD,
        self::UNREACHABLE,
    ];

    protected $fillable = [
        'destination_id',
        'source_name',
        'source_url',
        'details_url',
        'source_type',
        'allowed_by_policy',
        'robots_status',
        'robots_content',
        'robots_checked_at',
        'crawl_delay_seconds',
        'fetchability',
        'fetchability_checked_at',
        'fetchability_note',
        'last_checked_at',
        'last_success_at',
        'status',
        'etag',
        'last_modified',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'allowed_by_policy' => 'boolean',
            'robots_checked_at' => 'datetime',
            'fetchability_checked_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function crawlUrl(): string
    {
        $details = trim((string) $this->details_url);

        return $details !== '' ? $details : (string) $this->source_url;
    }

    public function isCrawlStale(): bool
    {
        return in_array((string) $this->status, ['crawling', 'queued'], true)
            && self::timestampIsStale($this->last_checked_at);
    }

    public function isActivelyCrawling(): bool
    {
        return $this->status === 'crawling' && !$this->isCrawlStale();
    }

    public function isQueued(): bool
    {
        return $this->status === 'queued' && !$this->isCrawlStale();
    }

    public function hasCrawlInFlight(): bool
    {
        return $this->isActivelyCrawling() || $this->isQueued();
    }

    public function displayStatus(): string
    {
        if ($this->isCrawlStale()) {
            return 'stale';
        }

        return $this->status !== null && $this->status !== ''
            ? (string) $this->status
            : 'pending';
    }

    public static function timestampIsStale(mixed $timestamp): bool
    {
        return $timestamp === null
            || $timestamp->lt(
                now()->subMinutes(
                    (int) config('crawling.stale_after_minutes', 15)
                )
            );
    }

    public static function statusCounts(): array
    {
        $counts = [];

        foreach (self::query()->get(['status', 'last_checked_at']) as $source) {
            $key = self::bucketFor($source);

            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    private static function bucketFor(self $source): string
    {
        $status = (string) ($source->status ?? 'pending');

        if (
            in_array($status, ['crawling', 'queued'], true)
            && self::timestampIsStale($source->last_checked_at)
        ) {
            return 'stale';
        }

        return $status !== '' ? $status : 'pending';
    }

    public function isCrawlUnreachable(): bool
    {
        return in_array(
            (string) $this->fetchability,
            [
                self::DEAD,
                self::UNREACHABLE,
                self::BOT_WALL,
                self::ROBOTS_BLOCKED,
                self::THIN_PAGE,
            ],
            true
        );
    }

    public function needsRendering(): bool
    {
        return $this->fetchability === self::JS_RENDERED;
    }

    public function fetchabilityLabel(): string
    {
        return match ((string) $this->fetchability) {
            self::FETCHABLE => 'Page readable',
            self::JS_RENDERED => 'Needs JavaScript',
            self::THIN_PAGE => 'Page is nearly empty',
            self::BOT_WALL => 'Blocked by the site',
            self::ROBOTS_BLOCKED => 'Disallowed by robots.txt',
            self::DEAD => 'Page is gone',
            self::UNREACHABLE => 'Host unreachable',
            default => 'Not checked yet',
        };
    }

    public static function fetchabilityCounts(): array
    {
        $counts = array_fill_keys(self::FETCHABILITY, 0);
        $counts['unchecked'] = 0;

        foreach (self::query()->get('fetchability') as $source) {
            $key = (string) ($source->fetchability ?? '');

            if (!array_key_exists($key, $counts)) {
                $key = 'unchecked';
            }

            $counts[$key]++;
        }

        return $counts;
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(DestinationSourceSnapshot::class);
    }

    public function crawls(): HasMany
    {
        return $this->hasMany(DestinationSourceCrawl::class);
    }

    public function lastCrawl(): ?DestinationSourceCrawl
    {
        return $this->crawls()->latest('started_at')->first();
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(DestinationUpdateProposal::class);
    }
}
