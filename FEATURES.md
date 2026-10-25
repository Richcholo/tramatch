# TraMatch — committed features checklist

State of `main` as of **2026-10-25** (after the per-province catalogue
propagation). Every item here is committed code pinned by the PHPUnit suite
(611 tests) unless explicitly marked otherwise. "Clicked" means a human
verified it in a browser — the repo has no browser automation, so anything
unmarked rests on markup/server tests only.

## Catalogue

- [x] 160-destination Luzon catalogue from `database/data/luzon-locations-clean.csv`
      (65 curated → 100 famous-spots expansion → 160 after the 2026-10-25
      per-province propagation, rounds 1+2)
- [x] Per-province propagation round 1: Metro Manila 13 (ties Benguet), Rizal 10,
      Ilocos Norte 10, Cavite 10
- [x] Per-province propagation round 2: Aurora, Batangas, Zambales, Cagayan,
      Laguna, Ilocos Sur, Quezon, Batanes all to 10 — 13 provinces now at 10–13
- [ ] Provinces still below 10 rows (remaining propagation work): Mountain
      Province 4, Bataan 2, Ifugao 2, La Union 2, Tarlac 2, Pangasinan 2,
      Pampanga 2, Bulacan 2, Albay 2, Kalinga 2, Nueva Ecija 1, Abra 1
- [x] Honesty policy: "Hours not listed" beats a wrong number; blank hours
      cells clear stale values on every reseed
- [x] `hours_kind` disambiguation (`always_open`, `per_day`,
      `registration_window`, `reservation_required`, `alert_dependent`)
- [x] Per-day hours with weekday/weekend splits; closed-day lists
- [x] Every row with hours carries a cited, verified source URL + label + note
- [x] Budget tiers as exact-match filters (ADR 0002): economy ≤₱500,
      mid-range ₱501–999, premium ₱1000+
- [x] Tag vocabulary (17 tags via `TagSeeder`), weighted 0–3 in
      `user_preferences` pivot

## Discovery (swipe deck)

- [x] `/discover` deck: one place at a time, fanned stack, dark theatre style
- [x] Three decisions: Liked / Pass / Decide later (skip flies down, never scored)
- [x] Pass penalty: one weight step per passed place's tags, floored at 0
- [x] Undo pops the traveller's most recent row (row deleted, not flipped)
- [x] Diversity: max 3 cards per matched tag (cap reorders, never filters);
      one stretch card last, labelled "Outside your interests"
- [x] Honest empty states: names the first filter that emptied the pool, with
      two ways out; reset deals the passed places again (likes stay)
- [x] Keyboard: ← pass, → like, ↓ skip; press feedback on primary actions
- [x] Drag swipe with fly-off, threshold a fifth of the card, native-drag guards
- [x] Ground cross-fades to the hovered card's photograph (hit-test based)

## Recommendations & itineraries

- [x] `/recommendations`: liked-only, exact budget-tier match,
      score = `preference × 0.7 + 30`
- [x] Match explanation (`App\Domain\Matches\MatchScore`): explains the floor,
      the interest points, and never recomputes the score
- [x] Itinerary generation (ADR 0004): candidates are every active destination
      in the area at the traveller's tier — the ceiling is no longer the swipes
- [x] Nearest-first day layout by haversine (order only; displayed legs are OSRM's)
- [x] Hours-aware scheduling via `ItinerarySchedule` (08:00–18:00 window,
      12:00–13:00 lunch, 45 min default gap, 3 stops/day) — single home,
      shared by generator, editor and reflow
- [x] Permanent vs day rejections: a Monday-closed destination retries Tuesday
      instead of vanishing from the trip
- [x] Hotel anchors day one's first stop (and doesn't count toward the stop cap);
      lunch guard measured and kept (`test_an_afternoon_only_day_still_gets_its_lunch`)
- [x] Free-time sentence per day (`ItineraryDay::freeTimeSentence()`)
- [x] Editor at `/itineraries/{id}`: drag reorder (incl. cross-day), arrow keys,
      add dialog, remove, reflow preview (server-side, per-day estimated-gap report)
- [x] Add a day / remove a day (refused while a day holds stops; renumber + re-date)
- [x] Four stop shapes: attraction | meal | lodging | custom (own address, optional pin)
- [x] Add a destination to a trip from its own page (same `apply()` rules;
      records the interest first)
- [x] Peso budget ceiling (`itineraries.budget_amount`): own endpoint, blank clears,
      equality fits, "verified prices so far" wording
- [x] Read-only share links (`/itineraries/{id}/shared/{token}`): guest minting
      impossible, rotation = revocation with confirm, zero forms on the page
- [x] Calendar export (.ics): UTC times, all-day tentative events for untimed stops,
      octet-based folding, UID derived from stop id
- [x] Per-stop driving-directions link (previous pinned stop as origin; no pin, no link)
- [x] Hand-rolled PDF (`ItineraryPdf` + `PdfWriter`): Windows-1252 punctuation,
      "PHP" instead of ₱ (WinAnsi has no peso), vector logo from the Blade component,
      typographic plates for photo-less stops, cited sources on place prices,
      qualified totals, final page never dropped
- [x] Map with Leaflet + OSRM driving line (dashed fallback on failure)
- [x] Destination weather widget (Windy embed, `w-[22rem]` cap)

## Places (meals & lodging)

- [x] `places` table separate from destinations; 4-shaped stop editor
- [x] Nullable prices (`price_min`/`price_max`) with `price_basis` units
      ("per room, per night" is never multiplied by group size)
- [x] Per-tier cluster coverage report (`Place::clusterCoverage()`),
      readiness gaps vs CLUSTER_MINIMUMS (6 food / 3 lodging)
- [x] Three pilot clusters: baguio, antipolo, paoay

## Routing (OSRM)

- [x] `RouteService` + `OsrmClient`: road legs on the public demo server,
      configurable base URL, no key required
- [x] Named User-Agent (Guzzle 7 sends none; the demo 403s anonymous requests)
- [x] Every failure is null → `unresolved` leg, never a guess and never a failed save
- [x] Legs cached 30 days as scalars (object-in-cache class known and guarded);
      cache failures logged (`routing.transport_failed`, `cache_unreadable`,
      `cache_stale`, `cache_unwritable`) and never fatal a save
- [x] Unmeasured legs labelled "drive not measured" on page and PDF; reflow
      reports per-day estimated-gap counts; first stop of a day is never one
- [x] Generation routes legs too (`WritesLegs` is the one implementation),
      bounded by `MAX_ROUTED_LEGS`

## Gemini (the one live surface)

- [x] `POST itineraries.llm-suggest`: one structured-output request per click,
      candidates are food/lodging `places` rows in the trip's own province,
      every returned id re-read from the catalogue
- [x] Every failure is an empty panel, never an error page; truncated replies
      named as their own kind (`MAX_TOKENS`), thinking budget covered by
      `PLANNING_ASSIST_OUTPUT_TOKENS` (8192)
- [x] Server-side key only; presence check, never the key itself, reaches a view
- [x] Default model `gemini-3.8-flash` (retired-model trap recorded);
      upstream refusals logged with status and provider message, key never logged
- [x] No traveller personal data leaves the app (no name/email/account fields)

## Admin

- [x] Destination CRUD, hours editing with verification fingerprint,
      archive (= `is_active = false`), permanent delete inside a transaction
- [x] Photo uploads (stage panels + listing); `is_featured` = curation,
      `is_active` = publishing
- [x] Bulk edit (page-scoped, javaless): budget tier, featured, active, tags —
      attach/detach never `sync()`, add+remove in one pass refused by name,
      summary counts changes not rows
- [x] Places CRUD; reviews moderation; user management
- [x] Form/method contract test walks every page (`@method` trap guarded)

## Platform

- [x] Auth (Breeze): register/login/reset, profile editing, photo upload/remove
- [x] Travel preferences: budget tier, group size, trip length, weighted interests
- [x] Dashboard; destination listing with search; place-nav strip (text names,
      quickhop GET form, `sort_order, name` ordering, archived places excluded)
- [x] Destinations stage: one dark surface, per-destination photo fan, typographic
      plates, chevrons/dots/keys/drag move the fan, autoplay with mandatory pause
- [x] PWA (hand-written sw/manifest/offline), navigation loading overlay (350 ms
      delay, 8 s cap), guest-safe layout
- [x] Deployment tooling: `deploy.sh` (guard, worker install, cron command print),
      `setup-website.sh` layouts A/B, `queue-worker.sh` + `queue:drain`
      (throwing jobs retired, never re-owned by one bad host), `.htaccess` deny
      rules duplicated across both layouts, `public/build` committed
- [x] Suite: 611 PHPUnit tests green on sqlite `:memory:`; migrations verified on
      MySQL in both directions

## Verified by hand (browser, 2026-10-05 unless noted)

| Clicked | Still untested by any hand |
|---|---|
| Itinerary drag incl. cross-day, arrows, add dialog, reflow | Drag on other viewports; deck skip/undo choreography |
| Stage width bug (console geometry probe) | Stage autoplay timing, count-up, reduced-motion path, dark restyle |
| Budget tier auto-fill warning; loading overlay existence | Loading overlay under a slow connection |
| — | PDF and .ics opened as documents (bytes only, never rendered) |

Re-click `/destinations/{slug}` and `/discover` after any change to
`stage.js`, `swipe.js`, or their markup — the suite pins contracts, not gestures.
