# Revision plan

Two weeks, no demo deadline. The panel has already sat; this work is the
revision, so the standard is **does it actually work**, not does it present
well.

Criticisms trace to [panel feedback](panel-feedback.md) and the decisions they
produced trace to an ADR. Every item below says which.

Effort is days for someone already fluent in this codebase.

## How each thing gets verified

| Layer | Tool | What it can and cannot catch |
|---|---|---|
| Server logic | PHPUnit (520 and counting) | Rules, ownership, validation, and the persistence contract |
| User flows | Playwright, run manually before any deploy | Whether four journeys work end to end in a browser |
| Feel | The click-through checklist | Whether it reads well — automation cannot answer this |

The two browser layers are not substitutes. This codebase has three recorded
incidents where the server-rendered markup was correct and the feature was
nonetheless invisible or dead: an element carrying Tailwind's `hidden` class
while the script toggled the `hidden` attribute, rendered rows missing the
`[data-field="day-id"]` attribute the drag code rewrites, and rows cloned from
a template that were never wired for dragging. All three passed every HTTP
test. Playwright exists to catch that class; the checklist exists to catch
everything it cannot.

## Phase 0 — The net (0.5 day)

- [ ] **Playwright smoke suite** over four flows: register → preferences →
      deck → generate → edit → reflow. Write it against the app **as it is
      today**, so it is a regression net for everything below rather than a
      test of the finished thing. No CI; a script you run before deploying.

## Phase 1 — The core five

The gaps a reviewer reaches in five minutes, plus the share link.

- [ ] **Break the liked-only gate** — `ADR 0004`. *(M)* The generator considers
      every active destination in the area and budget, ranked by preference,
      liked ones marked and first. **First**, because the wider candidate pool
      is what makes every later item worth doing.
- [ ] **Hours-aware scheduling** — answers *"real distances rather than
      estimates"* by refusing to schedule against hours we cite and ignore.
      *(S)* `hoursForDay()` in the generator and reflow; badges distinguishing
      closed-day, registration window and ungated. Same files as the gate
      change, so do it while they are open.
- [ ] **Meals and lodging in generated trips** — answers *"where they will eat
      and where they will stay"*. *(M)* One meal per day into the lunch block
      that exists and is never filled, one lodging per night, same area and
      tier, blank prices staying blank.
- [ ] **Anchor day 1 to lodging, show the free time.** *(S)* A real trip
      starts at the hotel. Free time is a sentence, not a chart.
- [ ] **Order each day geographically.** *(M)* Nearest-neighbour seed plus
      2-opt over the OSRM legs already cached. Reordering is arithmetic over
      cached legs, so unlike the deliberately flat generation gap it costs no
      latency.
- [ ] **Add to itinerary from `/destinations/{slug}`.** *(M)* Closes the
      discovery → planning loop; today that page references no itinerary.
- [ ] **Public read-only itinerary link.** *(S)* Signed token, no auth, no
      edit. The artifact that outlives a defence.
- [ ] **Honest empty states.** *(S)* The deck must distinguish *nothing matched
      your interests* from *your region matched nothing* from *you have swiped
      everything*, and must never tell someone who has swiped nothing that
      they are "all caught up". No fallback cards — an honest empty state with
      two ways out.
- [ ] **Reversible swipe, and skips that count.** *(S)* Liked is the
      shortlist; a pass removes the card and penalises its tags; both undoable.
      Today `skipped` rows are written and never read, so "no opinion" and "no"
      are indistinguishable.

## Phase 2 — The cheap list

- [ ] **Peso budget check** — nothing currently says whether a trip fits.
- [ ] **Explain the match score** — every input for the sentence already
      exists; a bare number is the only reason it reads as a black box.
- [ ] **Novelty slot + per-tag cap** — so five suggestions aren't five
      museums, and something is occasionally unlike everything you picked.
- [ ] **`.ics` export and a directions link per stop.**
- [ ] **Bulk edit in admin**, scoped to `budget_level`, tags, `is_featured`,
      `is_active` — the four fields that gate visibility, and the cheapest way
      to fix the 75/13/12 tier split if we decide to.
- [ ] **PDF**: verified vs unknown totals, per-stop kind and cost basis,
      unresolved legs labelled as unresolved, every place's source cited.

## Phase 3 — Discovery assist

Last, and dropped silently if Phase 1 overruns.

- [ ] Gemini client against a **free-tier AI Studio key**, Flash model,
      configured — not a code constant.
- [ ] Candidate retrieval; structured output; **every returned id validated
      against the catalogue and the traveller's budget tier**. A prompt is not
      enforcement.
- [ ] An honest state for exhausted quota, since a free tier is 5–15 RPM and
      roughly 1000 requests a day.
- [ ] Rewrite `config/planning.php`'s `authority.model_decides`, which still
      describes the abandoned drafting model ("day assignment, order") rather
      than discovery.
- [ ] Decide the fate of `is_locked` and the proposal/repair machinery: under a
      discovery role nothing automated edits a day, so they are currently a
      column wired to nothing.

## Carried, not sequenced

- [ ] **Scripted walkthrough** — numbered steps through the whole flow. A
      regression gate, now that the panel has sat, not a pitch.
- [ ] **README**: one honest sentence on how coverage grows, with no date.
- [ ] **Click-through checklist** before each deploy.

## Explicitly not doing

Search discovery (`ADR 0002`), weather and season context, group travel,
conversational revision, and any booking or payment capability (`ADR 0003`).
These are deferrals, not accidents, and at least one of them — search
discovery — has been deferred twice and should argue against `ADR 0002` if it
comes up a third time.