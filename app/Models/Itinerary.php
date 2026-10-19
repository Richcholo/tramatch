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
        'budget_amount',
        'trip_duration_days',
        'total_estimated_cost',
        'match_score',
        'is_completed',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'budget_amount' => 'decimal:2',
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
     * DOES THIS TRIP FIT THE BUDGET THE TRAVELLER STATED?
     *
     * The one place the arithmetic lives; the hero in
     * `partials/trip.blade.php` renders whatever this returns, so the
     * owner's page and the shared page cannot disagree about whether a trip
     * is over. Returns null when no budget was set — "no ceiling stated" is
     * a real state, and it must not render as a verdict of ₱0 over
     * something.
     *
     * THE TOTAL IS A SUM OF WHAT THE CATALOGUE VERIFIED, and the arithmetic
     * here does nothing to make it more complete than it is:
     *
     * - `total_estimated_cost` counts an unpriced stop as zero, because the
     *   column cannot hold null. The unpriced count lives in
     *   `ItineraryEditor::countUnpricedStops()` and the hero prints it
     *   beside this line; the wording here ("in verified prices so far") is
     *   what keeps a partially-priced trip from reading as a final one.
     * - A hotel is charged once for the trip, not per night, and a place
     *   contributes its band's MIDPOINT rather than an end of it. Neither is
     *   corrected here: this compares against the traveller's own figure
     *   using the same total the hero and the PDF print, and a second,
     *   truer total beside it would be two numbers on one screen.
     * - Nothing is divided by `group_size`. The total is not, so scaling the
     *   budget by the party while leaving the total per-person would be the
     *   app inventing a comparison it cannot support.
     *
     * @return array{amount: float, known: float, remaining: float, over: bool}|null
     */
    public function budgetCheck(): ?array
    {
        if ($this->budget_amount === null) {
            return null;
        }

        $amount = (float) $this->budget_amount;
        $known = (float) $this->total_estimated_cost;

        return [
            'amount' => $amount,
            'known' => $known,
            'remaining' => round($amount - $known, 2),
            'over' => $known > $amount,
        ];
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