<?php

namespace App\Models;

use App\Services\ItinerarySchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItineraryDay extends Model
{
    protected $fillable = ['itinerary_id', 'day_number', 'date'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function itinerary(): BelongsTo
    {
        return $this->belongsTo(Itinerary::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ItineraryItem::class)->orderBy('sort_order');
    }

    /**
     * Minutes of the day window this day leaves unplanned, or null when that
     * cannot be worked out.
     *
     * THE END OF THE LAST STOP, NOT A BUDGET MINUS THE PLAN. The difference
     * matters the moment a day overruns: a traveller whose afternoon ran long is
     * owed zero free time and must never be shown a negative one.
     *
     * Null, not zero, when there is nothing to measure — an empty day, or stops
     * with no times yet. "0 min free" on a day that was never planned reads as a
     * verdict on the traveller's day rather than as the absence of an answer.
     */
    public function freeMinutes(): ?int
    {
        $items = $this->items;

        if ($items->isEmpty()) {
            return null;
        }

        $end = ItinerarySchedule::parse((string) $items->last()->end_time);

        if ($end === null) {
            return null;
        }

        return max(0, ItinerarySchedule::DAY_END_MINUTES - $end);
    }

    /**
     * The free time as one sentence, or null when there is none worth saying.
     *
     * **A SENTENCE, NOT A CHART, AND THE REJECTED ALTERNATIVE IS THE POINT.**
     * The first instinct was a utilisation meter — a bar showing how much of the
     * 08:00-18:00 window the plan fills. A bar is the wrong shape for this: it
     * asks the traveller to decode a fraction before they know whether the number
     * is good, and an empty-looking bar on a full day reads as a fault when it
     * is the opposite. "2 h 20 min free" is the same fact and needs nothing
     * decoded.
     *
     * `ItineraryItem::durationLabel()` rather than a second formatter, so the
     * page, the PDF and the map tooltip cannot round a duration three ways.
     */
    public function freeTimeSentence(): ?string
    {
        $minutes = $this->freeMinutes();

        if ($minutes === null || $minutes <= 0) {
            return null;
        }

        return 'That leaves '.ItineraryItem::durationLabel($minutes).' free.';
    }
}