# The demo is a new account, not a seeded one

The panel evaluates the app by registering their own account and playing with
it, rather than logging into a curated demo user we prepared. This makes the
**first-run path the product**: every empty state, every gate between "signed
up" and "holding a complete itinerary" is load-bearing surface during the
demo, not polish to be added later.

A curated demo trip was rejected because it exhibits our work while hiding
whether a real traveller can reach it. A seeded shared-password account was
rejected because 25 guessable accounts with one published password on a public
domain is a credential exposure, not a convenience.

Consequences: `BetaUserSeeder` stays opt-in and stays commented out of the
default seed; the registration → preferences → deck → recommendations →
generation → edit → reflow path must be walked by hand before the panel sees
it; and empty states are designed, not left as absence of data.