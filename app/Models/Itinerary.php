<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Itinerary extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'start_date',
        'budget_level',
        'trip_duration_days',
        'total_estimated_cost',
        'match_score',
        'is_completed',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'total_estimated_cost' => 'decimal:2',
            'match_score' => 'decimal:2',
            'is_completed' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(ItineraryDay::class)->orderBy('day_number');
    }
}