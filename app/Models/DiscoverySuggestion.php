<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A VALIDATED MODEL PICK, AS STATE.
 *
 * Row = "the assistant named this destination for this traveller, with this
 * sentence, at this rank". Nothing but validated picks ever lands here —
 * `DiscoveryAssist::remember()` writes only after the candidate-membership
 * and catalogue re-read checks have passed — so the table can never hold an
 * invented id.
 *
 * The `why` sentence is the model's own words, and it is stored exactly as
 * `decodePicks()` sanitised it (one line, ≤280 chars). It is re-rendered with
 * the same "the model's own words, unchecked" label wherever it appears; the
 * destination itself is always re-read from the catalogue.
 */
class DiscoverySuggestion extends Model
{
    protected $fillable = [
        'user_id',
        'destination_id',
        'rank',
        'why',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }
}
