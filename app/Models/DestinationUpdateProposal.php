<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DestinationUpdateProposal extends Model
{
    protected $fillable = [
        'destination_id',
        'destination_source_id',
        'field_name',
        'old_value',
        'proposed_value',
        'confidence',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public static function fieldLabel(?string $field): string
    {
        return match ($field) {
            'name' => 'Destination name',
            'description' => 'Description',
            'latitude' => 'Latitude',
            'longitude' => 'Longitude',
            'entrance_fee' => 'Entrance fee',
            'entrance_fee_display' => 'Entrance fee (display)',
            'opening_time' => 'Opening time',
            'closing_time' => 'Closing time',
            'operating_status' => 'Operating status',
            default => ucwords(str_replace('_', ' ', (string) $field)),
        };
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DestinationSource::class, 'destination_source_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
