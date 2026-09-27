<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DestinationSourceCrawl extends Model
{
    protected $fillable = [
        'destination_source_id',
        'attempt',
        'outcome',
        'started_at',
        'finished_at',
        'duration_ms',
        'http_status',
        'requested_url',
        'final_url',
        'robots_state',
        'bytes',
        'content_hash',
        'content_changed',
        'extracted_fields',
        'proposals_created',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'content_changed' => 'boolean',
            'extracted_fields' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DestinationSource::class, 'destination_source_id');
    }

    public function producedData(): bool
    {
        return $this->proposals_created > 0;
    }

    public function redirected(): bool
    {
        $requested = trim((string) $this->requested_url);
        $final = trim((string) $this->final_url);

        return $requested !== '' && $final !== '' && $requested !== $final;
    }

    public function emptyReason(): ?string
    {
        if ($this->outcome !== 'success' || !empty($this->extracted_fields)) {
            return null;
        }

        $path = parse_url((string) ($this->final_url ?: $this->requested_url), PHP_URL_PATH);

        if ($path === null || $path === '' || $path === '/') {
            return 'Crawled the site homepage, which carries no fees or hours for this'
                .' destination. Set a details URL on this source to point at the'
                .' page that lists them.';
        }

        return 'The page was readable but published no fee or hours data in a form'
            .' the parser recognises.';
    }
}
