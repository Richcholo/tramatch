<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Destination extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'province',
        'municipality',
        'latitude',
        'longitude',
        'budget_level',
        'entrance_fee',
        'estimated_cost',
        'recommended_minutes',
        'opening_time',
        'closing_time',
        'image_url',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'entrance_fee' => 'decimal:2',
            'estimated_cost' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function swipes(): HasMany
    {
        return $this->hasMany(DestinationSwipe::class);
    }
}