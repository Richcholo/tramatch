# TraMatch is a travelogue, not a booking agent

TraMatch plans, prices and cites a Luzon trip. It deliberately does **not**
book anything, hold availability, take payment, or link to a transaction. The
traveller leaves with an itinerary they can act on themselves.

This is a scope boundary, not a missing feature. Every one of those
capabilities is a different product with different liability: availability is
real-time and goes stale, a price we published from a cited source last month is
not a quote, and taking money makes us a party to the transaction. Keeping the
boundary means our numbers can be honest — a price is either verified against a
source or it is not listed, and an unresolved drive says so rather than guessing
— and it keeps the scope to what seven days can actually finish.

Consequences: a hotel appears in an itinerary because the traveller added it,
either from our curated `places` or as their own custom stop with a price they
typed; nothing in the app implies a reservation exists; and if booking is ever
added it is a separate context that references the itinerary by id, not a
column added to a stop.