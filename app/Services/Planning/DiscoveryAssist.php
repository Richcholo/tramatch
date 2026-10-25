<?php

namespace App\Services\Planning;

use App\Models\Itinerary;
use App\Models\Place;
use Illuminate\Support\Collection;

/**
 * THE ITINERARY LOCATION ADVISER: "where should we eat and sleep on this trip?"
 *
 * One Gemini request takes the food and lodging rows from the trip's own
 * province and returns up to five picks with a sentence each. The model
 * chooses selection; the catalogue decides membership — every id it names is
 * checked against the candidate map built here, so an invented id is dropped
 * rather than rendered.
 *
 * THE BOUNDARIES, WHICH ARE THE FEATURE:
 *
 * - **THE MODEL DECIDES SELECTION, AND NOTHING ELSE.** The candidate pool,
 *   the prompt and the ordering are all ours; the reply is consulted for ids
 *   and one sentence per id and nothing more.
 * - **A PROMPT IS NOT ENFORCEMENT.** Every id in the reply is checked
 *   AGAINST THE CANDIDATE MAP the ids were drawn from. An invented id is
 *   silently dropped.
 * - **ALL FACTS ON SCREEN ARE OURS.** The place is re-read from the
 *   candidate map before display, so the panel prints name, area and type
 *   from the database; the only thing that arrives from the model is the
 *   sentence.
 * - **NO PERSONAL DATA LEAVES.** The prompt carries the trip's area and the
 *   candidate rows. It carries no traveller name, email or account field —
 *   free-tier content is used to improve Google's products.
 * - **ONE REQUEST, NEVER A LOOP.** Failures are returned empty, not retried.
 * - **A MISSING KEY OR EMPTY POOL RETURNS EMPTY.** This is a nice-to-have
 *   panel, not a workflow the trip depends on, and the controller renders
 *   nothing when it is empty.
 */
class DiscoveryAssist
{
    public function __construct(
        private readonly GeminiClient $gemini
    ) {
        //
    }

    /**
     * THE MODEL'S RESTAURANT AND HOTEL PICKS FOR A TRIP.
     *
     * The Gemini client already decodes the reply to an array, so this
     * method reads the array instead of re-decoding a JSON string.
     */
    public function recommendLocationsForItinerary(Itinerary $itinerary): array
    {
        if (trim((string) config('services.gemini.key')) === '') {
            return [];
        }

        $places = $this->tripPlaces($itinerary);

        if ($places->isEmpty()) {
            return [];
        }

        try {
            $decoded = $this->gemini->ask(
                $this->recommendationPrompt($itinerary, $places)
            );
        } catch (GeminiError) {
            return [];
        }

        $map = $places->keyBy('id');
        $out = [];

        foreach ($decoded['suggestions'] ?? [] as $pick) {
            if (! is_array($pick)) {
                continue;
            }

            $id = $pick['place_id'] ?? $pick['id'] ?? null;
            $reason = $pick['reason'] ?? $pick['why'] ?? null;

            if (! is_numeric($id) || ! isset($map[(int) $id]) || ! is_string($reason)) {
                continue;
            }

            $reason = trim(preg_replace('/\s+/', ' ', $reason));

            if ($reason === '' || mb_strlen($reason) > 280) {
                continue;
            }

            $out[] = ['place' => $map[(int) $id], 'reason' => $reason];
        }

        return $out;
    }

    /**
     * Food and lodging rows in the trip's own province, ready to send.
     *
     * Scoped to the trip's province so the model never suggests a place the
     * traveller would have to leave the area to reach, and trimmed to a
     * candidate list the prompt can carry without blowing the token budget.
     */
    private function tripPlaces(Itinerary $itinerary): Collection
    {
        return Place::query()
            ->where('is_active', true)
            ->where('province', $itinerary->area)
            ->whereIn('type', ['food', 'lodging'])
            ->limit(60)
            ->get(['id', 'name', 'type', 'province', 'municipality']);
    }

    /**
     * THE PROMPT, AND WHAT IT DELIBERATELY LEAVES OUT.
     *
     * The candidate rows are the only facts the model sees; it is told to
     * state nothing else, because there is nowhere for its own arithmetic to
     * be corrected from. No traveller name, email or account field leaves —
     * a free-tier request is improving Google's products.
     */
    private function recommendationPrompt(Itinerary $itinerary, Collection $places): string
    {
        $rows = $places->map(fn (Place $place) => [
            'id' => $place->id,
            'name' => $place->name,
            'type' => $place->type,
            'area' => $place->municipality,
        ])->toJson();

        return "A traveller is planning a trip in {$itinerary->area} and wants to add restaurants and hotels to it.\n\n"
            ."Candidate food and lodging rows in that province:\n$rows\n\n"
            ."Choose up to 5 distinct ids worth adding, ranked best first. Each pick carries one sentence on why it suits the trip.\n\n"
            ."Reply with JSON only, in this exact shape: {\"suggestions\":[{\"place_id\":123,\"reason\":\"...\"}]}\n"
            ."Every place_id must be one of the candidate ids above. Never state prices, hours or any fact that is not in the candidate rows.\n";
    }

    /**
     * THE GENERATION-FORM PICKS: which of the traveller's own candidate list
     * to visit, and one sentence on why each.
     *
     * Same boundaries as the location suggest: the model chooses selection
     * and one sentence, the caller's candidate map decides membership, and an
     * invented id is dropped rather than rendered. The controller has already
     * filtered the candidates to the traveller's chosen area and ticked ids —
     * this method is pure selection over what it was handed.
     */
    public function recommendCandidatesForGeneration(string $area, Collection $candidates): array
    {
        if (trim((string) config('services.gemini.key')) === '') {
            return [];
        }

        try {
            $decoded = $this->gemini->ask(
                $this->generationPrompt($area, $candidates)
            );
        } catch (GeminiError) {
            return [];
        }

        $map = $candidates->keyBy('id');
        $out = [];

        foreach ($decoded['suggestions'] ?? [] as $pick) {
            if (! is_array($pick)) {
                continue;
            }

            $id = $pick['destination_id'] ?? $pick['place_id'] ?? $pick['id'] ?? null;
            $reason = $pick['reason'] ?? $pick['why'] ?? null;

            if (! is_numeric($id) || ! isset($map[(int) $id]) || ! is_string($reason)) {
                continue;
            }

            $reason = trim(preg_replace('/\s+/', ' ', $reason));

            if ($reason === '' || mb_strlen($reason) > 280) {
                continue;
            }

            $out[] = ['destination' => $map[(int) $id], 'reason' => $reason];
        }

        return $out;
    }

    /**
     * The generation prompt, and what it deliberately leaves out: the
     * candidate rows are the only facts the model sees, and no traveller
     * name, email or account field leaves the server.
     */
    private function generationPrompt(string $area, Collection $candidates): string
    {
        $rows = $candidates->map(fn ($destination) => [
            'id' => $destination->id,
            'name' => $destination->name,
            'area' => $destination->municipality,
        ])->toJson();

        return "A traveller is planning a trip in {$area} and is choosing which places to visit from a shortlist.\n\n"
            ."Their shortlist:\n$rows\n\n"
            ."Choose up to 5 distinct ids worth visiting, ranked best first. Each pick carries one sentence on why it suits the trip.\n\n"
            ."Reply with JSON only, in this exact shape: {\"suggestions\":[{\"destination_id\":123,\"reason\":\"...\"}]}\n"
            ."Every destination_id must be one of the shortlist ids above. Never state prices, hours or any fact that is not in the shortlist.\n";
    }
}
