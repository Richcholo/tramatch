<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\DestinationSwipe;
use App\Models\DiscoverySuggestion;
use App\Services\Planning\DiscoveryAssist;
use App\Services\SwipeDeckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SwipeDiscoveryController extends Controller
{
    public function index(SwipeDeckService $deck): View|RedirectResponse
    {
        $user = Auth::user();

        if (!$user->travelProfile) {
            return redirect()->route('preferences.edit')
                ->with('status', 'Complete your travel profile before discovering destinations.');
        }

        return view('discover.index', [
            'cards' => $deck->cards($user),
            'emptyState' => $deck->emptyState($user),
            // Only the undo button needs this: it renders when a decision
            // exists to take back. A full deck and a decided-all deck both
            // pass through here; the deck itself derives its state. This
            // counts rows the traveller owns, never another user's.
            'swipedCount' => DestinationSwipe::where('user_id', $user->id)->count(),
            // A presence CHECK, not the key itself — the view never prints
            // config, so the button can be offered only when a suggestion can
            // actually be asked for. A control that cannot work should not be
            // rendered.
            'assistAvailable' => trim((string) config('services.gemini.key')) !== '',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'destination_id' => ['required', 'integer', 'exists:destinations,id'],
            'action' => ['required', 'in:liked,passed,skip'],
        ]);

        $destination = Destination::findOrFail($validated['destination_id']);
        $profile = Auth::user()->travelProfile;

        abort_unless($destination->is_active && $profile, 404);
        abort_unless($destination->budget_level === $profile->budget_level, 422);

        DestinationSwipe::updateOrCreate(
            [
                'user_id' => Auth::id(),
                'destination_id' => $destination->id,
            ],
            ['action' => $validated['action']]
        );

        return response()->json([
            'message' => 'Swipe saved.',
            'action' => $validated['action'],
        ]);
    }

    /**
     * THE TAKE-BACK. Pops the traveller's MOST RECENT decision — like, pass
     * or skip, all three undoable — and reports which destination was freed,
     * so the browser can put the card back where it was instead of
     * re-fetching the whole deck.
     *
     * The row is DELETED, not flipped: an undone pass is "no opinion" again,
     * which is a state the row must represent by not existing — reusing the
     * row with a null action would reintroduce the indistinguishability this
     * feature exists to remove.
     *
     * Ordering is `updated_at` desc, then `id` desc: `updateOrCreate`
     * re-likes the same destination in place, so the latest decision can
     * carry an old id, and a second-resolution `updated_at` can tie — the id
     * tie-breaker keeps the pop deterministic.
     *
     * 204 when there is nothing to undo, which is an honest answer for "you
     * have not decided anything" — an empty deck page never reaches here,
     * but a stale tab with a hot undo button can.
     */
    public function undo(Request $request): JsonResponse
    {
        $swipe = DestinationSwipe::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if (! $swipe) {
            return response()->json(status: 204);
        }

        $freed = $swipe->destination_id;
        $swipe->delete();

        return response()->json(['destination_id' => $freed]);
    }

    /**
     * Clearing the deck is CONFIRMATION, NOT A PAGE STATE.
     *
     * It used to flash `session('status')`, which the layout renders as a
     * banner inside `<main>`. On this route that was wrong twice over:
     *
     *  - `main` is deliberately `w-full` here so the deck can be one dark
     *    surface edge to edge, which means it carries **no padding**. The banner
     *    therefore rendered full-bleed -- flush against both viewport edges,
     *    hard under the nav bar, and outside the field every other element on
     *    this page sits in.
     *  - it is a **light** banner on a Deep Volcanic Teal page, so it read as a
     *    slab that had escaped the design rather than as part of it.
     *
     * Wrapping the banner in the page's own field was tried first and was the
     * wrong fix: it made the notice fit while keeping a large light rectangle
     * pushed the deck down the screen. Nothing about resetting a deck changes
     * the page it lands on.
     *
     * `toast_message` is the key the existing `<x-flash-toast />` component
     * reads, and `discover/index.blade.php` includes it. Bottom-right, dismisses
     * itself in 5s, and takes no vertical space. The two keys never fire
     * together, which a test pins.
     */
    public function reset(): RedirectResponse
    {
        DestinationSwipe::where('user_id', Auth::id())->delete();

        // RESET FORGETS THE NUDGE TOO. The remembered batch chose the front
        // of a deck that no longer exists; leaving it would re-promote picks
        // from before the reset, so "start fresh" would arrive still holding
        // the old opinion about what to see first.
        DiscoverySuggestion::where('user_id', Auth::id())->delete();

        return redirect()->route('discover.index')
            ->with('toast_message', 'Your discovery deck was reset.');
    }

    /**
     * THE NUDGE. One request to Gemini over the deck's own candidate list,
     * every returned id validated against the catalogue and the tier, and the
     * result rendered on the page a form POST lands back on — JavaScript off
     * by default here, the same as every other reset-and-redirect affordance
     * on this page.
     *
     * A result is CONTENT, so it does not ride `toast_message`, which the
     * layout renders as a full-bleed light banner on this deliberately bare
     * route (see reset() for that history). It rides its own session key,
     * which the view turns into the suggestion panel. Failure is CONFIRMATION
     * about a click, not page state, so it uses the toast and leaves the deck
     * untouched — exactly the rule reset() already follows.
     */
    public function assist(DiscoveryAssist $assist): RedirectResponse
    {
        if (! Auth::user()->travelProfile) {
            return redirect()->route('preferences.edit')
                ->with('status', 'Complete your travel profile before discovering destinations.');
        }

        $result = $assist->suggest(Auth::user());

        if (($result['status'] ?? '') === 'ok') {
            return redirect()->route('discover.index')
                ->with('assist_result', $result);
        }

        // THE FAILURE IS FLASHED TWICE, AND THE TWO ARE NOT REDUNDANT.
        //
        // `toast_message` is the bottom-right five-second notice, which is what
        // a traveller sees when the feedback dialog cannot open -- scripting
        // off, or a browser without `<dialog>`. `assist_message` is the same
        // sentence read by the dialog that covers the button they pressed.
        //
        // Both carry `assistMessage()`'s exact words, from this one call, so
        // the corner and the centre cannot describe one failure two ways. A
        // test pins that they are equal.
        $message = $this->assistMessage($result);

        return redirect()->route('discover.index')
            ->with('toast_message', $message)
            ->with('assist_message', $message);
    }

    /**
     * THE FAILURE COPY, ONE SENTENCE PER HONEST STATE. Named by status so a
     * test can pin the words a traveller actually reads; none of them quotes
     * internals like model identifiers or quota numbers nobody can act on.
     */
    private function assistMessage(array $result): string
    {
        return match ($result['status'] ?? '') {
            'unconfigured' => 'Suggestions are not set up on this deployment yet.',
            'cooldown' => 'You just asked for a suggestion — try again in '
                .$result['retry_after'].' seconds.',
            'throttled' => 'The suggestion model is out of free requests right now — try again in a minute.',
            'upstream', 'unreachable' => 'The suggestion service is not answering right now. Try again shortly.',
            'unparseable' => 'The suggestion did not come back in the shape we asked for. Try again.',
            'no_picks' => 'It did not name any place from the catalogue. Try again.',
            default => 'There are no places left to choose a suggestion from.',
        };
    }
}