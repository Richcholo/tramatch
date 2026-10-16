# TraMatch

TraMatch recommends and plans Luzon trips from a curated catalogue of real
places, then hands the traveller a day-by-day itinerary they can edit, price
and take away. It exists to answer "where can I actually go, and what will it
cost" with facts someone checked, rather than with guesses.

## The catalogue

**Destination**:
A place a traveller visits and spends time at — an attraction, a viewpoint, a
museum, a church. It has an entrance fee, a visit length, and opening hours.
_Avoid_: Place, spot, listing, POI

**Place**:
A business a traveller uses rather than visits — somewhere to eat, somewhere to
stay. It has a price and a basis for that price, never a visit duration.
_Avoid_: Destination, attraction, listing

**Custom stop**:
Something the traveller adds themselves that is in neither catalogue, typically
a hotel they already booked. It carries only what the traveller types.
_Avoid_: Freeform stop, manual stop, other

**Cluster**:
A named group of destinations and places within one province, close enough to
drive between, chosen for density rather than for size.
_Avoid_: Region, grouping, zone

**Budget tier**:
One of three spending levels a traveller's profile and every catalogue row must
match **exactly**. A row filed under the wrong tier is invisible to a traveller
in the other two.
_Avoid_: Price band, budget level, class

**Featured**:
A destination chosen by a human to appear in the stage. This is curation and
nothing else.
_Avoid_: Highlighted, promoted, starred

**Published**:
A destination is live for travellers. Publishing is not curation: a destination
can be published and unfeatured.
_Avoid_: Active, live, visible

## Trust

**Curated**:
Entered and checked by a person, from a source they actually read. The default
state for every catalogue value.
_Avoid_: Verified

**Verified**:
Curated *and* re-checked against the cited source on a recorded date. Only a
re-check moves the date — renaming or retagging does not.
_Avoid_: Checked, confirmed, sourced

**Source**:
The specific page a value was read from. A row with no readable source carries a
note saying why instead of a number.
_Avoid_: Reference, link, citation

**Measured**:
A distance or travel time that came from the routing provider for that exact
pair of positions.
_Avoid_: Real, actual, routed

**Estimated**:
A travel time that could not be measured and fell back to a flat allowance.
Always labelled as an estimate, and never presented as a distance.
_Avoid_: Default, generic, approximate

**Unresolved**:
A leg the routing provider could not answer for. It stays unresolved rather
than being filled in with an estimate, and it says so on screen.
_Avoid_: Unknown, missing, failed

## The trip

**Stop**:
One planned thing on one day, in order. Exactly one of four shapes: an
attraction, a meal, lodging, or a custom entry.
_Avoid_: Item, activity, event, task

**Leg**:
The drive from one stop to the next. It belongs to the stop it arrives at,
which is why the first stop of a day has no leg at all.
_Avoid_: Segment, hop, gap

**Day window**:
The hours a day is planned inside, with a lunch block inside that. One
implementation, shared by generation, editing and reflow, so the three cannot
disagree.
_Avoid_: Schedule, hours

**Reflow**:
Re-laying a day out from its durations, restarting at the start of the day
window. A preview: it writes nothing, and it deliberately ignores typed start
times.
_Avoid_: Reschedule, recompute, auto-schedule

**Locked stop**:
A stop the traveller has pinned, which automated planning must not move or
replace.
_Avoid_: Pinned, fixed, frozen

**Proposal**:
A suggested change to a day that the traveller accepts or rejects. Never applied
on its own.
_Avoid_: Suggestion, draft, patch

## The traveller

**Travelogue**:
What TraMatch produces: a plan, a cost breakdown and a citation for every fact
in it. It is not a reservation, a quote, or a transaction — nothing here books
anything or takes payment.
_Avoid_: Booking, itinerary booking, reservation

**Traveller**:
The person planning a trip. Owns their profile, swipes, trips and reviews.
_Avoid_: User, customer, account

**Travel profile**:
A traveller's budget tier, group size and trip length, plus their weighted
interests. Saving it deliberately resets the deck.
_Avoid_: Preferences, profile, account

**Swipe**:
A yes, a no, or an undo on one destination. A yes makes it part of the
traveller's shortlist and keeps it out of the deck; a no takes it out of the
deck and counts against its tags; either can be undone, because a decision the
traveller cannot take back is not a decision.
_Avoid_: Like, vote, rating

**Recommendation**:
A destination from the traveller's shortlist, ranked by how strongly their
weighted interests match its tags. Recommendations are always liked; what
generation considers is a wider set of candidates.
_Avoid_: Suggestion, match, result

**Match score**:
The figure a recommendation is ranked by. A calculation, never a claim about
quality.
_Avoid_: Rating, score, confidence

**Candidate**:
A catalogue row the planner may choose. Candidates are every active
destination in the traveller's chosen area and budget, ranked by preference
score — the ones the traveller liked are marked and ordered first, but liking
one does not make the others ineligible. Only candidates can be chosen; the
planner cannot invent a place.
_Avoid_: Option, suggestion, input

**Shortlist**:
The destinations a traveller has liked. It ranks the catalogue and never gates
it — a trip can be planned from places the traveller never swiped.
_Avoid_: Wishlist, saved, shortlist of undecided places