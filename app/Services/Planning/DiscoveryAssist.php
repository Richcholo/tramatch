<?php

namespace App\Services\Planning;

use App\Models\Destination;
use App\Models\User;
use App\Services\SwipeDeckService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * THE DISCOVERY ASSIST: "stuck? tell me where to look next."
 *
 * The deck answers "what matches you?" by arithmetic; this answers "what is
 * there, in words". One Gemini request takes the candidate list the deck
 * itself deals from — already filtered to the traveller's tier and the
 * decisions they have made — and returns up to `assist_choices` picks with a
 * sentence each.
 *
 * THE BOUNDARIES, WHICH ARE THE FEATURE:
 *
 * - **THE MODEL DECIDES SELECTION, AND NOTHING ELSE.** `candidates()` pool,
 *   prompt, and the deck's own ordering are all ours; the reply is consulted
 *   for ids and one sentence per id and nothing more.
 * - **A PROMPT IS NOT ENFORCEMENT.** Every id in the reply is checked
 *   AGAINST THE CANDIDATE MAP the ids were drawn from, and each surviving pick
 *   is then re-read from the database, because the candidates were sent moments
 *   ago and could have changed. An invented id is counted as rejected and
 *   never displayed.
 * - **ALL FACTS ON SCREEN ARE OURS.** The destination is re-read from the
 *   catalogue before display, so the panel prints name, area and fee from the
 *   database; the only thing that arrives from the model is the sentence,
 *   labelled as the model's reason.
 * - **NO PERSONAL DATA LEAVES.** The prompt carries interest weights, tier,
 *   region and catalogue rows. It carries no traveller name, email, or any
 *   account field — free-tier content is used to improve Google's products.
 * - **ONE REQUEST, NEVER A LOOP.** Failures are returned, not retried; the
 *   only thing repeated is the traveller clicking again after the cooldown.
 * - **THE COOLDOWN RECORDS THE SPENT REQUEST**, success or failure — a 429
 *   means the free tier is out of breath right now and an immediate second
 *   click would make it worse. An unconfigured key or an empty pool reaches
 *   the wire for nothing, so it records nothing.
 */
class DiscoveryAssist
{
    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly SwipeDeckService $deck
    ) {
        //
    }

    public function suggest(User $user): array
    {
        if (trim((string) config('services.gemini.key')) === '') {
            return ['status' => 'unconfigured'];
        }

        $retryAfter = $this->cooldownRemaining($user);

        if ($retryAfter > 0) {
            return ['status' => 'cooldown', 'retry_after' => $retryAfter];
        }

        $candidates = $this->deck->cards(
            $user,
            (int) config('planning.limits.max_candidates_per_request')
        );

        if ($candidates->isEmpty()) {
            // The deck dealt from an empty pool. Reaching the wire for a
            // suggestion of nothing would be a request pretending one was
            // possible.
            return ['status' => 'no_pool'];
        }

        $thrown = null;
        $decoded = [];

        try {
            $decoded = $this->gemini->ask($this->prompt($user, $candidates));
        } catch (GeminiError $error) {
            $thrown = $error;
        } finally {
            // Spent is spent: the attempt went out unless the pool or the key
            // stopped it before the transport did.
            $this->recordCooldown($user);
        }

        if ($thrown !== null) {
            return ['status' => $thrown->kind()];
        }

        $picks = $this->decodePicks(
            is_array($decoded['picks'] ?? null) ? $decoded['picks'] : []
        );

        // EVERYTHING THE REPLY NAMED, COUNTED BEFORE ANYTHING WAS FILTERED —
        // invented ids, dupes it was told not to repeat, entries with no
        // usable reason, valid rows past the asked-for cap. Rejected is
        // `named - shown`, so a short suggestion list can never read as the
        // whole reply.
        $named = is_array($decoded['picks'] ?? null) ? count($decoded['picks']) : 0;

        $valid = $this->validate($picks, $candidates, $user);

        if ($valid->isEmpty()) {
            return [
                'status' => 'no_picks',
                'rejected' => $named,
            ];
        }

        return [
            'status' => 'ok',
            'suggestions' => $valid,
            'asked_over' => $candidates->count(),
            'rejected' => $named - $valid->count(),
        ];
    }

    /**
     * THE REPLY, REDUCED TO THE SHAPE WE ACCEPT.
     *
     * Deduped to its first mention — the model was told for distinct ids and
     * a repeat is noise, not a second pick — and each remaining entry
     * sanitised to one line. A pick that carries no usable reason is dropped
     * whole: a recommendation with no stated reason is exactly what the
     * traveller asked for when they asked WHY. The cap lives in validate(),
     * after the membership check, so the model's own ranking is what fills
     * the asked-for seats. Nothing here is counted; `named - shown` in
     * suggest() is the honest arithmetic over the entries the reply actually
     * carried.
     */
    private function decodePicks(array $raw): Collection
    {
        $seen = [];
        $picks = collect();

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = $entry['id'] ?? null;
            $why = $entry['why'] ?? null;

            if (! is_numeric($id) || ! is_string($why)) {
                continue;
            }

            $id = (int) $id;

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            $why = trim(preg_replace('/\s+/', ' ', $why));

            if ($why === '' || mb_strlen($why) > 280) {
                continue;
            }

            $picks->push(['id' => $id, 'why' => $why]);
        }

        return $picks;
    }

    /**
     * THE ID CHECK, BOTH PLACES THE PLAN DEMANDS IT.
     *
     * "Validated against the catalogue" — every surviving pick is re-read
     * from the destinations table, active and in this traveller's tier, so
     * the panel can only ever name a real, currently published row in the
     * right budget band. "Validated against the traveller's tier" — the
     * candidates were tier-filtered before the prompt, so the id must be one
     * of THEM: membership in the deck's own list is what makes "in the
     * catalogue" and "in my tier" the same sentence.
     *
     * THE TIER IS DELIBERATELY ENFORCED THREE TIMES, and the falsification
     * work recorded why: the deck filter (first), the membership check
     * (second) and this re-read's WHERE (third). Dropping the WHERE alone
     * changes nothing reachable — membership already refuses an out-of-tier
     * id — so it is not the layer any test reads; it is kept because THIS
     * method should refuse an out-of-scope id on its own terms, without
     * borrowing the caller's guarantees. A redundant guard on an honesty
     * boundary is not a second copy of a rule; it is the rule stated where
     * the decision is made.
     *
     * The reply's own order is kept — that ranking is the one decision the
     * model owns — and the cap bites at the end, so a well-behaved reply
     * loses nothing and a chatty one is cut to the asked-for number.
     */
    private function validate(Collection $picks, Collection $candidates, User $user): Collection
    {
        $known = $candidates->keyBy(fn (Destination $candidate) => $candidate->id);

        $suggestions = collect();

        foreach ($picks as $pick) {
            if ($suggestions->count() >= (int) config('planning.limits.assist_choices')) {
                break;
            }

            if (! $known->has($pick['id'])) {
                continue;
            }

            $destination = Destination::query()
                ->where('id', $pick['id'])
                ->where('is_active', true)
                ->where('budget_level', $user->travelProfile->budget_level)
                ->first();

            if (! $destination) {
                continue;
            }

            $suggestions->push([
                'destination' => $destination,
                'why' => $pick['why'],
            ]);
        }

        return $suggestions;
    }

    /**
     * THE PROMPT, AND WHAT IT DELIBERATELY LEAVES OUT.
     *
     * Facts travel one way only: the model is given the candidate rows as
     * they stand in the catalogue and is told not to state anything else,
     * because there is nowhere for its own arithmetic to be corrected from.
     * No name, no account, no booking — a free-tier request is improving
     * Google's products, and this traveller is not a field test.
     */
    private function prompt(User $user, Collection $candidates): string
    {
        $profile = $user->travelProfile;

        $interests = $profile->tags
            ->map(fn ($tag) => $tag->name.' (weight '.(int) $tag->pivot->weight.')')
            ->implode(', ');

        $region = $profile->preferred_region;

        $rows = $candidates
            ->map(fn (Destination $destination): array => [
                'id' => $destination->id,
                'name' => $destination->name,
                'area' => trim($destination->municipality.', '.$destination->province),
                'tags' => $destination->tags->pluck('name')->values()->all(),
                'catalogue_fee_estimate' => $this->feeNote($destination),
            ])
            ->toJson(JSON_UNESCAPED_UNICODE);

        $preface = "A traveller using a Luzon travel catalogue wants help deciding where to look next. This is a discovery suggestion, not an itinerary.\n\n"
            ."Traveller profile, with no personal details: budget tier \"{$profile->budget_level}\""
            .($region ? "; region preference \"$region\"" : '')
            ."; interests (name and weight 1-3): $interests.\n\n"
            ."Candidate catalogue rows, already filtered to their tier and to places they have not decided on:\n$rows\n\n"
            ."Choose at most ".(int) config('planning.limits.assist_choices')." distinct ids from the candidate rows "
            ."— the places most worth this traveller's time next, ranked best first. Each pick carries one sentence "
            ."on why it suits this profile.\n\n"
            ."Reply with JSON only, in this exact shape: {\"picks\":[{\"id\":123,\"why\":\"...\"}]}\n"
            ."Every id must be one of the candidate ids above. Never state hours, prices or any fact that is not "
            ."in the candidate rows — the app reads all facts from its own catalogue.\n";

        return $preface;
    }

    /**
     * A FEE NOTE ONLY WHEN SOMEONE PUBLISHED ONE.
     *
     * The catalogue's own rule — "no verified figure" is a real state, and a
     * question mark inventing one in the prompt would start the lie the
     * readback exists to stop. A published display string is shared verbatim;
     * `estimated_cost` is prefixed with its own origin so the model cannot
     * misread it as a ticket price.
     */
    private function feeNote(Destination $destination): ?string
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

    private function cooldownRemaining(User $user): int
    {
        $endsAt = (int) Cache::get($this->cooldownKey($user), 0);

        return max(0, $endsAt - now()->timestamp);
    }

    private function recordCooldown(User $user): void
    {
        $seconds = max(1, (int) config('planning.limits.assist_cooldown'));

        Cache::put(
            $this->cooldownKey($user),
            now()->timestamp + $seconds,
            $seconds
        );
    }

    private function cooldownKey(User $user): string
    {
        return 'assist:cooldown:'.$user->id;
    }
}
