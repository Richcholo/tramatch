<?php

namespace App\Services\Planning;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * WHAT MAY BE SENT TO THE MODEL, IN ONE PLACE.
 *
 * There are two surfaces that talk to Gemini — the deck assist ("stuck?") and
 * the trip draft ("lay this out for me") — and both need the same three things
 * assembled: who the traveller is *in the catalogue's terms*, which candidate
 * rows are in play, and how a fee and an opening window are described. Two
 * copies of "what may be sent" is the failure this repo keeps paying for, so
 * the assembly lives here and both callers read it.
 *
 * ---------------------------------------------------------------------------
 * THE DENY-LIST, WHICH IS THE REASON THIS CLASS EXISTS
 * ---------------------------------------------------------------------------
 *
 * A free-tier Gemini request IMPROVES GOOGLE'S PRODUCTS. That is the whole
 * argument, and it is why the following must never reach the wire, on any
 * surface, under any config flag, with or without a traveller's consent:
 *
 *   - `itinerary_items.custom_name`     — can hold a person's name
 *   - `itinerary_items.custom_address`  — a home address
 *   - `itinerary_items.custom_notes`    — free text the traveller typed
 *   - `users.name`, `users.email`       — account identity
 *   - `itineraries.share_token`         — a capability, not a fact
 *   - **any prose a traveller typed**, anywhere, ever.
 *
 * The `custom_*` columns are the dangerous ones because they are the only
 * place a traveller's own words live, and a draft prompt naturally wants to
 * mention the row they are attached to. "Staying at my aunt's place in Baguio"
 * is an address; it is not catalogue data and it is not ours to send.
 *
 * A TEST PINS THIS BY PUTTING A PERSON'S NAME IN `custom_name` AND ASSERTING IT
 * DOES NOT APPEAR IN THE PROMPT — see `TripBriefTest`.
 *
 * ---------------------------------------------------------------------------
 * THE ALLOW-LIST, WHICH IS WHAT MAY BE SENT
 * ---------------------------------------------------------------------------
 *
 * Every item below is server-known, catalogue-owned, or the traveller's own
 * stated preference — never a fact a model is being asked to supply:
 *
 *   - `budget_level`             — the tier; an exact-match filter (ADR 0002)
 *   - `preferred_region`         — a region name, if the traveller set one
 *   - weighted interests         — interest NAME and weight 1-3. Names only:
 *                                  a tag is catalogue vocabulary, not a
 *                                  sentence the traveller wrote
 *   - trip length and dates      — IF SET. An undated trip sends neither
 *   - `budget_amount`            — the traveller's own peso ceiling, if set
 *   - member names               — destinations the trip already holds
 *   - per destination, its TAG NAMES and a PUBLISHED FEE STRING, verbatim
 *   - hours AS THE APP RENDERS THEM — see `hoursLine()`, never a raw column
 *   - pace, steering selections  — chosen from server-defined options
 *   - free-time minutes and the unpriced-stop count
 *
 * ---------------------------------------------------------------------------
 * AND THE RULE THAT MAKES THE ALLOW-LIST WORTH HAVING
 * ---------------------------------------------------------------------------
 *
 * **THE SERVER NEVER TRUSTS THE REPLY.** Everything above is sent so the model
 * can *choose between things we already know*. Nothing in it is a fact the
 * model may restate: the app re-reads price, hours, coordinates, distance and
 * open/closed from its own tables, and a model sentence is labelled as the
 * model's own words wherever it appears. `AGENTS.md`: the model moves
 * opinions, the server owns facts.
 */
class TripBrief
{
    /**
     * THE TRAVELLER'S CATALOGUE TERMS — budget tier, optional region, and
     * weighted interests. No name, no email, no account id.
     *
     * THE WEIGHT IS PART OF THE FACT. "Food and Dining" alone loses the
     * difference between a traveller who rates it 3 and one who rates it 1,
     * which is most of what the ranking is for. Weight 0 is dropped by
     * `PreferenceController` before it is stored, so anything present here is
     * an interest the traveller actually claimed.
     */
    public function profileClause(User $user): string
    {
        $profile = $user->travelProfile;

        $region = $profile->preferred_region;

        return 'budget tier "'.$profile->budget_level.'"'
            .($region ? '; region preference "'.$region.'"' : '')
            .'; interests (name and weight 1-3): '.$this->interests($user);
    }

    /**
     * THE INTERESTS AS A LIST, for a caller that phrases them its own way
     * rather than inside the standard clause.
     */
    public function interests(User $user): string
    {
        return $user->travelProfile->tags
            ->map(fn ($tag) => $tag->name.' (weight '.(int) $tag->pivot->weight.')')
            ->implode(', ');
    }

    /**
     * THE CANDIDATE ROWS, AS JSON.
     *
     * `id` is the contract: it is the only thing the reply is trusted to name,
     * and every id it names is checked against the map these rows came from.
     */
    public function candidateRows(Collection $candidates): string
    {
        return $candidates
            ->map(fn (Destination $destination): array => $this->candidateRow($destination))
            ->toJson(JSON_UNESCAPED_UNICODE);
    }

    /**
     * ONE CANDIDATE ROW, as an array, so a caller can build a differently
     * shaped payload without re-deriving the fields.
     *
     * `area` is assembled from the parts that are ACTUALLY SET. The original
     * was `trim($municipality.', '.$province)`, which only trims the OUTSIDE:
     * a row with no municipality sent `", Benguet"` — a dangling comma in a
     * prompt whose whole job is being precise about places, and one the deck
     * assist shipped with for as long as it existed. No row in the catalogue is
     * blank today (measured: 0 of 100 destinations, 0 of 36 places), so this
     * changes no live prompt — it removes a trap for the first blank one.
     *
     * @return array<string, mixed>
     */
    public function candidateRow(Destination $destination): array
    {
        return [
            'id' => $destination->id,
            'name' => $destination->name,
            'area' => $this->area($destination),
            'tags' => $destination->tags->pluck('name')->values()->all(),
            'catalogue_fee_estimate' => $this->feeNote($destination),
        ];
    }

    /**
     * "Baguio, Benguet" — or "Benguet" alone when the municipality is blank.
     *
     * The joiner never appears twice and never leads or trails, which is the
     * guarantee `trim()` cannot give.
     */
    public function area(Destination $destination): string
    {
        $parts = array_filter([
            trim((string) $destination->municipality),
            trim((string) $destination->province),
        ], fn (string $part): bool => $part !== '');

        return implode(', ', $parts);
    }

    /**
     * A FEE NOTE ONLY WHEN SOMEONE PUBLISHED ONE.
     *
     * The catalogue's own rule — "no verified figure" is a real state, and a
     * question mark inventing one in the prompt would start the lie the
     * readback exists to stop. A published display string is shared verbatim;
     * `estimated_cost` is prefixed with its own origin so the model cannot
     * misread it as a ticket price.
     *
     * `null` here is the honest third state, and the prompt carries it as JSON
     * `null` rather than as "unknown" or a zero. A model told a fee is zero
     * will plan a free day.
     */
    public function feeNote(Destination $destination): ?string
    {
        $display = trim((string) $destination->entrance_fee_display);

        if ($display !== '') {
            return $display;
        }

        if ($destination->estimated_cost !== null && (float) $destination->estimated_cost > 0) {
            return 'curated estimate PHP '.number_format((float) $destination->estimated_cost, 0);
        }

        return null;
    }

    /**
     * HOURS AS THE APP RENDERS THEM, NEVER AS A RAW COLUMN PAIR.
     *
     * This mirrors `ItineraryPdf::hoursLine()` deliberately — same order, same
     * words — because it is the same problem one layer up. `Destination` owns
     * several shapes of hours and the difference between them is the
     * difference between a true sentence and a false one:
     *
     *   - a PER-DAY week is summarized by `perDayHoursLabel()`, because
     *     flattening it to one pair is the collapse the per-day schema exists
     *     to prevent;
     *   - `always_open` (a curated 00:00–23:59) is an UNGATED place — a
     *     mountainside viewpoint with no gate. `formatHours()` returns
     *     "00:00–23:59" for it, and printing that into a prompt tells a model
     *     the gate opens at midnight. That is exactly the lie the PDF shipped
     *     once and now refuses, so `always_open` gets its own words and is
     *     checked the same way the PDF checks it — by the column, not by
     *     comparing the formatted string;
     *   - a plain window is `formatHours()`;
     *   - no hours at all is "Hours not listed", which beats a wrong number.
     *
     * A TEST PINS EACH SHAPE, so a new kind cannot arrive without a line here.
     */
    public function hoursLine(Destination $destination): string
    {
        if ($destination->hasPerDayHours() && $destination->perDayHoursLabel()) {
            return (string) $destination->perDayHoursLabel();
        }

        if ($destination->hours_kind === Destination::HOURS_ALWAYS_OPEN) {
            return 'Open — no gate';
        }

        $formatted = trim((string) $destination->formatHours());

        return $formatted !== '' ? $formatted : 'Hours not listed';
    }

    /**
     * THE TRIP'S OWN SHAPE, and only the parts that are SET.
     *
     * An undated trip sends no date at all, and a draft must never be handed an
     * invented one. The calendar writer already refuses to build a file for a
     * trip whose days carry no date, for the same reason: a date nobody chose
     * is a plan for a day that does not exist.
     *
     * `asDate()` on `Itinerary` does not exist — `start_date` is a `date` cast,
     * which hands back a MUTABLE Carbon, so `modify()` on it would rename the
     * trip's own start date. The copy is taken through
     * `DateTimeImmutable::createFromInterface()`, the same escape the PDF's
     * `ItineraryPdf::asDate()` uses after a two-day trip read "3 – 3 Nov".
     */
    public function tripClause(Itinerary $itinerary): string
    {
        $days = max(1, (int) $itinerary->trip_duration_days);

        $parts = ["trip length \"{$days} day(s)\""];

        $start = $itinerary->start_date !== null
            ? \DateTimeImmutable::createFromInterface($itinerary->start_date)
            : null;

        if ($start !== null) {
            $parts[] = 'starts "'.$start->format('D j M Y').'"';
            $parts[] = 'ends "'.$start->add(new \DateInterval('P'.($days - 1).'D'))->format('D j M Y').'"';
        } else {
            $parts[] = 'undated';
        }

        $budget = $itinerary->budget_amount;

        if ($budget !== null && (float) $budget > 0) {
            $parts[] = 'the traveller\'s own ceiling is PHP '.number_format((float) $budget, 0);
        }

        return implode('; ', $parts);
    }
}
