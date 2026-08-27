<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItineraryItem extends Model
{
    protected $fillable = [
        'itinerary_day_id',
        'destination_id',
        'sort_order',
        'start_time',
        'end_time',
        'travel_minutes_from_previous',
        'estimated_cost',
        'note',
    ];

    public function day(): BelongsTo
    {
        return $this->belongsTo(ItineraryDay::class, 'itinerary_day_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }
}