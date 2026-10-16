# The demo is a new account, not a seeded one

The panel evaluates the app by registering their own account and playing with
it, rather than logging into a curated demo user we prepared. This makes the
**first-run path the product**: every empty state, every gate between "signed
up" and "holding a complete itinerary" is load-bearing surface, not polish to
be added later.

A curated demo trip was rejected because it exhibits our work while hiding
whether a real traveller can reach it. A seeded shared-password account was
rejected because 25 guessable accounts with one published password on a public
domain is a credential exposure, not a convenience.

**The panel has since sat.** The decision holds — but the reason it matters has
changed. Walking the first-run path is now a **quality test** rather than panel
preparation, and it has already paid for itself: it is how a traveller who
ticked *Romantic, Beach* and *Shopping* at the mid-range tier was found landing
on "You are all caught up" with nothing swiped, and how a request for 7 days
was found returning 2 empty ones. Those were found by walking the path, not by
a test.

Consequences: `BetaUserSeeder` stays opt-in and stays commented out of the
default seed; the registration → preferences → deck → recommendations →
generation → edit → reflow path is walked by hand and by browser test on a
schedule rather than once before a defence; and empty states are designed, not
left as absence of data.