<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Itinerary extends Model
{
    /**
     * The ceiling on how long a trip may run.
     *
     * The same limit PreferenceController applies to a saved
     * trip_duration_days, so a trip cannot be longer from the editor than it
     * could have been from the preferences form. It lives on the model because
     * three places need it and none of them owns the number: the generation
     * form, the editor's day stepper and the service that appends the day.
     */
    public const MAX_DAYS = 30;

    protected $fillable = [
        'user_id',
        'title',
        'area',
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
        return $this->hasMany(ItineraryDay::class)
            ->orderBy('day_number');
    }
}