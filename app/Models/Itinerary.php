<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
        'share_token',
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

    /**
     * THE SHARE LINK'S MAGIC SIGNATURE, OR THE ABSENCE OF ONE.
     *
     * Returns the token when this trip HAS one, and null when it does not.
     * The truthy/not-truthy split is the access control, so it must be the
     * caller's to see: minting should be a decision someone makes on purpose,
     * not a side effect of a stranger loading the page.
     *
     * @see sharedToken() for the minting side.
     */
    public function hasShareToken(): bool
    {
        return $this->share_token !== null;
    }

    /**
     * Mint the share token, once, and never rotate it.
     *
     * `Str::random(40)` from the [A-Za-z0-9] pool — about 238 bits of
     * surprise. The unique index is the correctness backstop for a collision
     * nobody can actually engineer. A token minted here changes only by the
     * owner's explicit `rotateShareToken()` — regeneration by accident would
     * break every link already handed out, which is a worse loss than a link
     * someone still wants disabled.
     *
     * `$this->forceFill()->save()` rather than `update(['share_token' ...])`
     * so the write cannot be intercepted or reordered by mass-assignment
     * rules a future refactor adds.
     */
    public function sharedToken(): string
    {
        if ($this->share_token !== null) {
            return $this->share_token;
        }

        $this->forceFill(['share_token' => Str::random(40)])->save();

        return $this->share_token;
    }

    /**
     * THE OWNER'S "stop sharing" — replace the secret, leave the feature on.
     *
     * A signed public link has exactly one honest revocation: the link
     * everyone already holds must STOP WORKING, and a new one must start.
     * Clearing the column without replacing it would also do that, but it
     * would leave the page claiming sharing is on while no link exists,
     * which is the UI the share page is built to disambiguate. Rotation is
     * deliberate and destructive BY DESIGN: breaking distributed links is
     * what the owner asked for, so the request layer confirms first.
     */
    public function rotateShareToken(): string
    {
        $this->forceFill(['share_token' => Str::random(40)])->save();

        return $this->share_token;
    }
}