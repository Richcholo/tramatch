<?php

namespace App\Services;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\User;
use Illuminate\Support\Collection;

class SwipeDeckService
{
    /**
     * HOW MANY CARDS OF ONE TAG MAY APPEAR IN A DEALT DECK.
     *
     * Ranking by score alone makes a traveller who rates "Museums" highly deal
     * five museums before anything else, because five museums all score
     * 100. That is the correct answer to the question the deck asks — "what
     * matches you?" — and a useless answer to the one the traveller is
     * actually asking, which is "what is there?".
     *
     * Three of any tag in a twenty-card deck. High enough that a traveller
     * who genuinely loves museums is never deprived of them, low enough that
     * the deck cannot open with four of the same thing. It is a PREFERENCE,
     * never a filter: a row the cap defers is pushed down the deck, not
     * dropped, so the cap can never quietly shrink what the traveller is
     * offered.
     */
    private const MAX_PER_TAG = 3;

    /**
     * THE ONE CARD THAT MATCHES NONE OF YOUR INTERESTS.
     *
     * Placed LAST, deliberately. First position would put an unranked place
     * in front of someone who has just opened the deck and let it read as a
     * match; last means it is met after the traveller has seen what they
     * actually asked for, which is the only order in which "here is something
     * unlike everything you picked" is a gift rather than a glitch.
     *
     * ONE, AND ONLY WHEN THE POOL HAS MATCHES. A traveller whose interests
     * match nothing gets the honest empty state with its two ways out, not a
     * wall of cards chosen for sharing nothing — that is the same lie
     * `emptyState()` was written to refuse, wearing a different hat.
     */
    private const NOVELTY_SLOTS = 1;

    public function cards(User $user, int $limit = 20): Collection
    {
        $resolved = $this->penalisedWeights($user);

        if ($resolved === null) {
            return collect();
        }

        $profile = $resolved['profile'];
        $weights = $resolved['weights'];

        $totalWeight = $weights->sum();

        $swipedIds = DestinationSwipe::where(
            'user_id',
            $user->id
        )->pluck('destination_id');

        /*
         * THE CATALOGUE'S OWN ORDER, EXPLICITLY. The deck is dealt from a
         * plain query, and "whichever order the database returned" is not an
         * order two requests in the same page load can agree on — the same
         * lesson the place strip and the stage payload learned. `sort_order,
         * name` is the catalogue's total order, and everything below is a
         * pure function of it plus the swipes.
         */
        $destinations = Destination::query()
            ->with('tags')
            ->where('is_active', true)
            ->whereIn('budget_level', Destination::affordableTiers($profile->budget_level))
            ->whereNotIn('id', $swipedIds)
            ->when($profile->preferred_region, function ($query) use ($profile) {
                $region = $profile->preferred_region;

                $query->where(function ($query) use ($region) {
                    $query
                        ->where('province', 'like', "%{$region}%")
                        ->orWhere('municipality', 'like', "%{$region}%");
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $scored = $destinations
            ->map(function (Destination $destination) use (
                $weights,
                $totalWeight
            ) {
                $matchedTags = $destination->tags->filter(
                    fn ($tag) => $weights->get($tag->id, 0) > 0
                );

                $matchedWeight = $matchedTags->sum(
                    fn ($tag) => $weights->get($tag->id, 0)
                );

                $destination->preference_score = $totalWeight > 0
                    ? round(($matchedWeight / $totalWeight) * 100, 2)
                    : 0;

                $destination->matched_tags = $matchedTags->values();

                // Nothing in the deck is novelty until `cards()` says so. The
                // flag is set on exactly one row, below, so a view cannot
                // mistake "scored 0" for "chosen to stretch you".
                $destination->is_novelty = false;

                return $destination;
            });

        $matches = $scored
            ->filter(function (Destination $destination) {
                return $destination->matched_tags->isNotEmpty()
                    && $destination->preference_score > 0;
            })
            ->sortByDesc('preference_score')
            ->values();

        if ($matches->isEmpty()) {
            // No interest match means no deck, and the honest empty state
            // says which filter emptied it. A novelty slot here would replace
            // that explanation with twenty unrelated places.
            return collect();
        }

        $room = max(1, $limit - self::NOVELTY_SLOTS);
        $deck = $this->diversify($matches)->take($room)->values();

        $novelty = $this->noveltyCard($scored, $deck);

        return $novelty ? $deck->push($novelty) : $deck;
    }

    /**
     * THE PER-TAG CAP, AS A REORDERING RATHER THAN A FILTER.
     *
     * Two passes over the ranked list: one that admits a destination while
     * none of its matched tags is at the cap, and one that appends
     * everything the first pass deferred, in the order it was ranked. Nothing
     * is dropped, so the cap decides the order and never the contents — a
     * filter would silently shorten the deck and nobody would be able to say
     * why.
     */
    private function diversify(Collection $matches): Collection
    {
        $counts = [];
        $admitted = collect();
        $deferred = collect();

        foreach ($matches as $destination) {
            $tagIds = $destination->matched_tags
                ->map(fn ($tag) => (int) $tag->id)
                ->all();

            $withinCap = collect($tagIds)->every(
                fn (int $tagId) => ($counts[$tagId] ?? 0) < self::MAX_PER_TAG
            );

            if ($withinCap) {
                $admitted->push($destination);

                foreach ($tagIds as $tagId) {
                    $counts[$tagId] = ($counts[$tagId] ?? 0) + 1;
                }

                continue;
            }

            $deferred->push($destination);
        }

        return $admitted->concat($deferred);
    }

    /**
     * THE STRETCH CARD, OR NOTHING.
     *
     * The first place in catalogue order that shares none of the traveller's
     * interests and is not already on the deck. `sort_order, name` from the
     * query is what makes "first" a fact rather than whatever the database
     * felt like returning; a random or insertion-ordered pick would make the
     * same traveller get a different stretch card on every page load.
     */
    private function noveltyCard(Collection $scored, Collection $deck): ?Destination
    {
        return $scored
            ->reject(fn (Destination $destination) => $destination->matched_tags->isNotEmpty())
            ->reject(fn (Destination $destination) => $deck->contains('id', $destination->id))
            ->first(function (Destination $destination) {
                // The flag the view keys its badge on, set HERE and nowhere
                // else. Left false everywhere else, so a card cannot be
                // labelled "outside your interests" by anything except being
                // the one this method picked.
                $destination->is_novelty = true;

                return true;
            });
    }

    /**
     * THE PROFILE'S INTEREST WEIGHTS, LESS WHAT THE TRAVELLER HAS SAID NO TO.
     *
     * Shape: `[profile, weights]` or null when the traveller has no profile —
     * null, NOT an empty collection, because "no profile" and "profile with
     * no weights" are different empty decks with different messages, and
     * both `cards()` and `emptyState()` have to tell them apart.
     *
     * A PASS IS READ BACK HERE, FOR THE FIRST TIME. `destination_swipes`
     * rows were written and never read anywhere: a pass cost the traveller
     * the card and nothing else, so "no" and "decide later" could sit in the
     * table indistinguishable. The penalty reads the passed rows back —
     * every pass on a place carrying a tag subtracts ONE step from that
     * tag's weight, floored at zero.
     *
     * Why one step: weights are 1..3, so a single pass against a
     * weight-1 interest erases it. That is the rule doing its job — the
     * weakest interest dying after one "no" — not a bug to soften while
     * "making it friendlier".
     *
     * A SKIP IS NEVER READ HERE. `skipped` rows carry no opinion; this
     * method deliberately does not touch them, because the whole point of
     * the action is that "decide later" leaves no trace in scoring while
     * still keeping the card out of the deck, and the moment it starts
     * penalising, "no opinion" and "no" are the same again.
     */
    private function penalisedWeights(User $user): ?array
    {
        $profile = $user
            ->load('travelProfile.tags')
            ->travelProfile;

        if (!$profile) {
            return null;
        }

        $penalties = DestinationSwipe::query()
            ->where('destination_swipes.user_id', $user->id)
            ->where('destination_swipes.action', 'passed')
            ->join('destination_tag', 'destination_tag.destination_id', '=', 'destination_swipes.destination_id')
            // THE GROUP BY IS NOT OPTIONAL, AND ITS ABSENCE WAS A 500 ON
            // EVERY DEPLOYED DECK. This counts passes per tag. Without a
            // GROUP BY, SQLite returns the count over the whole result set
            // and MySQL's strict mode refuses the query outright
            // ("Mixing of GROUP columns ... with no GROUP columns is
            // illegal"). The suite runs sqlite, so every test passed while
            // /discover 500'd on the host — and, worse, the SQLite path was
            // silently computing ONE tag's count for every tag.
            //
            // This is the third sqlite/MySQL divergence this repo has paid
            // for; the rule it teaches is that a query only exercised on the
            // test driver has never been exercised at all.
            ->groupBy('destination_tag.tag_id')
            ->selectRaw('destination_tag.tag_id as tag_id, count(*) as passes')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->tag_id => (int) $row->passes]);

        $weights = $profile->tags
            ->pluck('pivot.weight', 'id')
            ->map(fn ($weight) => (int) $weight)
            ->mapWithKeys(fn ($weight, $tagId) => [$tagId => max(0, $weight - ($penalties[$tagId] ?? 0))]);

        return ['profile' => $profile, 'weights' => $weights];
    }

    /**
     * WHY THE DECK IS EMPTY, ATTRIBUTED TO THE LAYER THAT EMPTIED IT.
     *
     * The deck filters four times — budget tier, region, shared interests,
     * already swiped — and "You are all caught up" was the one sentence for
     * every outcome, including for a traveller who had swiped NOTHING. That
     * was a lie with accompaniment: *nothing matched your interests* tells a
     * curator where the gaps are, *your region matched nothing* is a resolvable
     * mistake, and *you swiped everything* is a working state.
     *
     * The layers are attributed in the order `cards()` applies them, so the
     * message names the FIRST filter that emptied the pool: a region that
     * excluded everything reports the region, not "no interests", because the
     * interests never got the chance to speak. Counts are returned with the
     * reason so the view can print its own numbers, and `cards` is returned
     * when the deck is not actually empty — an honest fallback the view never
     * uses, because this method is only consulted after `cards()` came back
     * empty.
     *
     * `no_profile` never reaches the view: the controller redirects a
     * profile-less traveller to the preferences form before this service is
     * asked anything.
     *
     * Every interest test below goes through `penalisedWeights()`, so a tag
     * the traveller has passed down to zero weight is NOT an interest here
     * either: "shares your interests" would otherwise invite a traveller back
     * for cards the deck will never deal.
     */
    public function emptyState(User $user): array
    {
        $resolved = $this->penalisedWeights($user);

        if ($resolved === null) {
            return ['reason' => 'no_profile'];
        }

        $profile = $resolved['profile'];
        $weights = $resolved['weights'];

        if ($weights->isEmpty() || $weights->sum() === 0) {
            return [
                'reason' => 'no_interests',
                'tier' => $profile->budget_level,
            ];
        }

        $swipedIds = DestinationSwipe::where('user_id', $user->id)
            ->pluck('destination_id');

        $inTier = Destination::query()
            ->with('tags')
            ->where('is_active', true)
            ->whereIn('budget_level', Destination::affordableTiers($profile->budget_level))
            ->get()
            ->values();

        if ($inTier->isEmpty()) {
            return [
                'reason' => 'tier_nothing',
                'tier' => $profile->budget_level,
            ];
        }

        $region = $profile->preferred_region;
        $inRegion = $inTier->unless($region === null || $region === '', function ($collection) use ($region) {
            return $collection->filter(function (Destination $destination) use ($region) {
                $needle = mb_strtolower($region);

                return str_contains(mb_strtolower($destination->province ?? ''), $needle)
                    || str_contains(mb_strtolower($destination->municipality ?? ''), $needle);
            });
        });

        if ($inRegion->isEmpty()) {
            return [
                'reason' => 'region_nothing',
                'region' => $region,
                'tier' => $profile->budget_level,
                'tier_count' => $inTier->count(),
            ];
        }

        $sharesAnInterest = fn (Destination $destination) => $destination->tags->contains(
            fn ($tag) => $weights->get($tag->id, 0) > 0
        );

        $tagMatches = $inRegion->filter($sharesAnInterest);

        if ($tagMatches->isEmpty()) {
            $elsewhere = Destination::query()
                ->with('tags')
                ->where('is_active', true)
                ->whereNotIn('budget_level', Destination::affordableTiers($profile->budget_level))
                ->get()
                ->filter($sharesAnInterest)
                ->count();

            return [
                'reason' => 'no_interests',
                'tier' => $profile->budget_level,
                'elsewhere' => $elsewhere,
            ];
        }

        $unswiped = $tagMatches->filter(
            fn (Destination $destination) => ! $swipedIds->contains($destination->id)
        );

        if ($unswiped->isNotEmpty()) {
            return ['reason' => 'cards'];
        }

        return [
            'reason' => 'all_swiped',
            'matched' => $tagMatches->count(),
        ];
    }
}