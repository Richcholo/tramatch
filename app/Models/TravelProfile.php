<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TravelProfile extends Model
{
    protected $fillable = [
    'user_id',
    'budget_level',
    'group_size',
    'trip_duration_days',
    'preferred_region',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'user_preferences', 'travel_profile_id', 'tag_id')
            ->withPivot('weight');
    }
}