# Generation ranks the catalogue; swipes rank within it

The generator used to accept **only destinations the traveller had already
liked**. That gate made the reachable itinerary length
`liked destinations in one province ÷ 3`, and Benguet — the densest province in
a 100-row catalogue — has 13 rows total. Verified on 2026-10-16: a traveller
who liked **all thirteen** Benguet destinations and asked for 7 days got days
1–4 full, day 5 with one stop, and days 6 and 7 empty. A seven-day trip was
unreachable by a traveller doing everything correctly.

The gate was an implementation shortcut — recommendations were already
liked-only, so reusing that set as the generator's input pool was free — and it
hardened into a product ceiling. A swipe is a **ranking signal**, not an
eligibility rule: it says "rank this higher", not "nothing else is allowed".

The generator now considers every active destination in the chosen area and
budget, ranked by preference score, with liked destinations marked and ordered
first. Swipes still matter and are still rewarded; they just no longer decide
whether a trip can exist.

Consequences: `RecommendationService` keeps its liked-only rule, because a
traveller's own shortlist genuinely *is* only the places they liked;
`ItineraryGenerator`'s "no longer valid recommendations" error becomes "nothing
in this area at your budget", which is the honest version of the same failure;
and the empty-day copy can stop apologising for a limit the product no longer
has.