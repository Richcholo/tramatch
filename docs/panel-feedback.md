# What the panel said

There is **no written panel report** anywhere in this repository — no
evaluation document, transcript, or feedback file, on any branch. This file is
the record, assembled from commit messages, docblocks and `AGENTS.md`, and it
marks which items are **verbatim** and which are **paraphrase**. If a real
report surfaces, replace this file with it rather than editing it.

## Verbatim

Two phrases survive as actual quotes:

> "The locations are too small"

Recorded at `AGENTS.md:1079` and `AGENTS.md:1151`, and in `app/Models/Place.php:103`.

> "where do I eat?"

Recorded at `AGENTS.md:1153` and `app/Models/Place.php:105`.

## Paraphrase

Everything below is our reading of what was said, not the words themselves. If
any of it misrepresents the panel, that is the thing to correct first.

| Criticism | Where it drove work |
|---|---|
| A traveller wants to add **where they will eat and where they will stay** | The four-shaped stop (attraction / meal / lodging / custom), the separate `places` catalogue, admin places screens |
| Travel times should be **real distances rather than estimates** | The route columns, OSRM routing, and the measured-vs-estimated distinction |
| A price means nothing without **its basis** — ₱4,500 is not an answer without "per room per night" | Nullable `price_min`/`price_max`, `price_basis`, `Place::hasVerifiedPrice()` |
| **"Is this cluster big enough to plan a trip in?"** — a row count hides that | Per-tier coverage reporting and `Place::clusterReadiness()` |
| The catalogue should grow | 65 → 100 destinations, 100 sources, 36 researched places |
| The app should **not present a fact it cannot cite** | Blank hours with a source and a note, "price not listed", `unresolved` legs |

The richest single source is commit `27fc3c6`, whose message carries more of
the panel's substance than anything in `AGENTS.md`.

## Not panel-sourced

Recorded here so the provenance stays honest. The liked-only gate that made a
seven-day trip unreachable (`ADR 0004`) was **self-found** during revision
work on 2026-10-16, not reported. `docs/plan.md` groups it with panel
criticisms because a panelist would have found it, which is a different claim
from "the panel found it".

## Status

The panel has already sat. The two weeks following it are **revisions**, and
the standard is whether the app works rather than whether it presents well.