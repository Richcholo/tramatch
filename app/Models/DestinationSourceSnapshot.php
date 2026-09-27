<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DestinationSourceSnapshot extends Model
{
    protected $fillable = [
        'destination_source_id',
        'content_hash',
        'raw_content_path',
        'http_status',
        'content_type',
        'fetched_at',
        'parser_version',
    ];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DestinationSource::class, 'destination_source_id');
    }

    public function sizeInKilobytes(): ?float
    {
        $disk = Storage::disk('local');

        if (!$this->raw_content_path || !$disk->exists($this->raw_content_path)) {
            return null;
        }

        return round($disk->size($this->raw_content_path) / 1024, 1);
    }
}
