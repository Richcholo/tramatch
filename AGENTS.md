# TraMatch — agent notes

Laravel 13 + Breeze (Blade) travel-itinerary recommender for Luzon destinations.
Deployment is deliberately on hold (polish/QA phase).

`README.md` covers setup and the product flow, but it predates the
destination-source crawling feature described below. Where prose and code
disagree, trust the code and this file.

## Commands

```sh
composer install
npm ci --include=optional          # .npmrc sets ignore-scripts=true

php artisan dev                    # serves :8000, runs Vite on :5173 AND a
                                   # queue worker — use this instead of three
                                   # separate terminals (composer run dev too)
npm run dev                        # Vite only, if you run things separately
php artisan serve                  # app on :8000 — use this URL, not :5173
npm run build                      # -> public/build (gitignored)
php artisan queue:work             # standalone worker, only if not using `dev`

php artisan migrate --seed         # sessions/cache/queue all use the DB driver
php artisan migrate:fresh --seed   # destructive: drops all local data
php artisan sources:crawl {id}     # fetch one approved source, create proposals
php artisan sources:check          # measure whether each source page is readable
```

`php artisan dev` starts three processes (server, queue worker, Vite). Check
them with `php artisan dev:list`. The worker's default is
`queue:listen --timeout=0` — no per-job timeout — so
`AppServiceProvider::register()` re-registers it as `--timeout=300`; keep that
override or a hung crawl blocks the worker forever. That number must stay
**below** `queue.connections.*.retry_after` or the same crawl runs twice — see
the deployment section. A standalone `php artisan queue:work` is unaffected:
`CrawlSourceJob::$timeout` outranks any worker flag. `inline()`, `tabs()` and
`stream()` are no-ops on Windows, so all three processes share one window.
`php artisan dev` is a dev tool only; production needs a real process manager.

Tests are PHPUnit (no Pest):

```sh
php artisan test
php artisan test --filter=RecommendationServiceTest
php artisan test tests/Feature/ItineraryGenerationTest.php
```

No CI workflows, no pre-commit hooks, no lint/typecheck gate.
`vendor/bin/pint` is installed but the tree is **not** Pint-clean
(110 files / 48 style issues as of this writing) — never mass-format, and do
not make `pint --test` a pass/fail gate.

## Environment gotchas (verified on this machine)

- `php artisan test` runs against sqlite `:memory:` (`phpunit.xml` sets
  `DB_CONNECTION=sqlite`, array cache/session, sync queue) and needs
  `pdo_sqlite` enabled in `php.ini`. Never point it at MySQL to work around a
  driver problem — `RefreshDatabase` would drop the development database.
- The suite is currently **352 passing**. It is also the only thing that
  migrates from scratch, so it is the only check that a fresh clone can
  migrate — your dev database cannot detect a broken migration chain,
  because every migration in it has already run.
- `tests/Feature/Admin/SourceCrawlingTest.php` covers the crawl queue: single
  and bulk dispatch, no double-dispatch, stalled rows, the status endpoint, and
  a DOM check that no admin view nests a form inside another. Rendering a view
  or dispatching a job by hand does **not** exercise a controller action — the
  single-crawl path stayed broken for exactly that reason, so add a feature
  test whenever a controller's request path changes.
- Local `.env` uses MySQL (`DB_CONNECTION=mysql`, `DB_DATABASE=tramatch`,
  XAMPP). `.env.example` ships sqlite defaults, so trust `.env`, not the example.
- `APP_TIMEZONE=Asia/Manila`, and `config/app.php` must keep reading
  `env('APP_TIMEZONE')` — Laravel 11+ ships a hardcoded `'UTC'` there, so
  setting the env var alone does nothing. Timestamps written *before* the switch
  are UTC wall-clock and will read 8 hours early; anything written after is
  correct.
- **The app layout assumes a logged-in user** — `layouts/app.blade.php` calls
  `auth()->user()->isAdmin()` for the admin nav link, which fataled every
  public page for guests until it was guarded with `auth()->check()`. Any new
  `auth()->user()` call in a shared layout needs the same guard.
- Bulk crawl selection spans pages: the header checkbox selects the visible
  page, then a banner offers "Select all N matching sources", which posts
  `select_all=1` with `filter_search` / `filter_status` so the server resolves
  the whole filtered set. Those hidden inputs deliberately avoid the names
  `search` / `status` so they cannot be confused with the GET filter form.
- HTTPS crawling depends on the machine's CA bundle. On Windows,
  `curl.cainfo` and `openssl.cafile` in `php.ini` must point at a real bundle
  (`C:\php84\extras\ssl\cacert.pem`); if the file is missing or unparseable every
  crawl dies with `cURL error 60: unable to get local issuer certificate`.
  Fix the trust store — never disable verification with `Http::withOptions(['verify' => false])`.
- **A running PHP process keeps the `php.ini` it booted with.** Two servers are
  usually running at once here: XAMPP Apache on port 80 and
  `php artisan serve` on port 8000. If `php.ini` gains a CA bundle *after* a
  server started, that server keeps an empty trust store and every crawl from
  the web fails with cURL error 60 while `php artisan sources:crawl` and other
  fresh CLI processes succeed. This looks like a flaky host but is perfectly
  deterministic per process. **Restart the server after editing `php.ini`.**
  `EthicalSourceFetcher` appends an explanatory note to the recorded error when
  it detects a process with no CA bundle configured.
- Retries on `cURL error 60` do **not** fix a broken trust store, and this repo
  has no confirmed flaky-upstream host. `EthicalSourceFetcher::fetch()`
  retries `ConnectionException` only (2 attempts from the admin button, 3 from
  the command and the queue) purely to absorb one-off network resets; a
  deterministic certificate failure will still fail after every attempt. A
  transport failure always happens before a snapshot is written, so retrying
  cannot duplicate one. Diagnose a repeating error 60 with the checklist above
  before assuming the source is at fault.
- `UserFactory` is the only factory. Tests construct `TravelProfile`, `Tag`,
  `Destination`, and `DestinationSwipe` by hand under `RefreshDatabase`.
- `.editorconfig`: 4 spaces, LF, final newline. No `pint.json` overrides.

## Architecture worth knowing

- Controllers are thin; behavior lives in `app/Services`.
  - `RecommendationService` — returns **only** destinations the user liked,
    requires an exact `budget_level` match, score = `preference * 0.7 + 30`.
  - `SwipeDeckService` — excludes already-swiped ids, optional
    `preferred_region` `LIKE` match against province/municipality.
  - `ItineraryGenerator` — every selected destination must already appear in the
    user's recommendations or generation throws. It owns only `MAX_DESTINATION_COST`
    and `MAX_MATCH_SCORE`; every timing rule lives in `ItinerarySchedule`.
  - `ItinerarySchedule` — the single home for the 08:00–18:00 day window, the
    12:00–13:00 lunch block, the 45 min travel gap and the 3 stops/day cap, as
    `DAY_START_MINUTES`, `DAY_END_MINUTES`, `LUNCH_START_MINUTES`,
    `LUNCH_END_MINUTES`, `TRAVEL_MINUTES`, `MAX_STOPS_PER_DAY`. The generator,
    the editor and the reflow endpoint all call it, so changing a window cannot
    leave one of the three disagreeing. `parse()`/`format()` are the only
    place minutes-since-midnight and `H:i` strings convert.
- Weighted interests live in the `user_preferences` pivot keyed by
  `travel_profile_id` (not `user_id`), composite PK with `tag_id`:
  `$profile->tags()->attach($id, ['weight' => 1..3])`. `PreferenceController`
  accepts weights 0–3 and drops zeros.
- `PreferenceController::update` runs `$user->destinationSwipes()->delete()` —
  saving preferences wipes every swipe. That is the "reset the deck" behavior,
  not a bug to fix.
- Admin gate is the `admin` middleware alias registered in `bootstrap/app.php`
  → `App\Http\Middleware\AdminMiddleware` (`User::isAdmin()`, `role = 'admin'`).
  No roles/permissions package.
- Archive is `is_active = false`; there are no soft deletes. Permanent delete
  manually removes reviews, swipes, itinerary items, and tag pivots inside a
  transaction (`Admin\DestinationController::destroy`).
- Shared behaviour lives in traits next to its users, not in a `Helpers` class:
  `HasOpeningHours` (`app/Models/Concerns`, Destination + Place),
  `ReadsCatalogueRows` (`database/seeders/Concerns`, both CSV seeders —
  mechanics only, never honesty policy),
  `HandlesPostedHours` (`Admin\Concerns`, posted-hours normalisation and the
  verification fingerprint). A second copy of any of these is the failure.
- `ItineraryStop` (`app/Services`) is the seam every itinerary stop resolves
  through: `preload()` reads both catalogue tables once per draft,
  `fromFields()`/`forItem()` resolve one row, and name/position delegate to
  the item's own readers via an unsaved related item — so they cannot drift.
  Validation (liked-only, active-only, no-repeat) stays in the editor, and
  `snapshotCost()` returns null for unknown prices rather than zero.

### Editing a generated itinerary

`/itineraries/{id}` is both the read view and the editor — one URL, so the map
and everything else stays mounted while you edit. `resources/js/itinerary-editor.js`
flips between the two; the markup for both lives in
`resources/views/itineraries/show.blade.php`.

- **The payload is keyed by item id, never by array position.** Reordering
  therefore never renumbers a form field, and a field name identifies the stop
  it belongs to. Browser-invented keys are **negative** (`-1`, `-2`, …) and
  become new stops, so existing and added rows share one field-name shape and
  the drag code does not special-case either. The JS half of this contract is
  the regex in `draftPayload()`; change it and the Blade field names with it.
- **`UpdateItineraryRequest::after()` is the ownership boundary.** A positive
  key must be an item of this itinerary and a negative key must match
  `/\A-[1-9][0-9]{0,5}\z/`; `day_id` is checked against this trip's own days.
  Without this a traveller could reassign a stop onto somebody else's day.
- **Cross-day drag works by rewriting `day_id`, and the field it rewrites is
  `[data-field="day-id"]`.** Every rendered row and the row template must carry
  that attribute. The template had it and the rendered rows did **not**, so a
  stop that arrived with the page could be added and reordered but could never
  change day — and no HTTP-level test noticed, because the field was always
  submitted with the correct day; it just could not be edited.
  `test_every_rendered_stop_row_exposes_its_day_id_to_the_script` pins it.
  Renaming the attribute in Blade without the JS silently disables the feature.
- **`ItineraryEditingTest` names its tests `test_*` and imports no PHPUnit
  attributes.** A `#[Test]` added there is an *unresolved attribute*: not an
  error, silently ignored, so the method never runs and the file still reports
  green. Check the test count, not just the tick.
- **Reflow is server-side**, on `POST itineraries/{itinerary}/schedule`,
  because the day window, lunch block and travel gap then have exactly one
  implementation. Do not port those rules to JS. It is a preview: it writes
  nothing, and it deliberately ignores typed start times and restarts at
  08:00. It also deliberately does not refuse an over-long day — it returns the
  finish time and the browser warns.
- **A price is not editable.** There is no cost field in the form, and
  `ItineraryEditor::costFor()` ignores a posted `estimated_cost` outright — a
  stored stop keeps what it was generated with, a newly added one takes the
  destination's own figure. `UpdateItineraryRequest` has no rule for it either,
  so `validated()` already drops it at the boundary; the service ignores it
  anyway because `apply()` is reachable from anywhere and would otherwise be one
  careless caller away from rewriting the catalogue. Do not add the input back
  because a total "looked wrong" — the header total is
  `refreshTotals()` summing stored costs, so it is right by construction.
  `test_a_posted_cost_is_ignored_because_prices_are_not_editable` pins the
  request layer and `test_the_editor_itself_ignores_a_cost_in_the_draft` pins
  the service.
- **Typed times are stored verbatim.** `travel_minutes_from_previous` is
  derived from the real gap and clamped at 0, so an overlap after a reorder is
  the traveller's own doing and Reflow is the escape hatch. A stop saved with
  no usable times takes `ItinerarySchedule::nextSlot()` and the destination's
  `recommended_minutes`.
- `nextSlot()` uses the **latest end on the day**, not the end of the last
  row. After a reorder those differ — a stop dragged to the front can finish
  long before one above it — and following the last row placed a new stop
  *inside* a stop already there. Two real bugs came out of a manual
  walkthrough here; keep that regression test.
- The add picker reads `DestinationSwipe` directly rather than going through
  `RecommendationService`. That service also requires a travel profile with
  weighted tags, which made a liked-but-unscored place **addable but
  invisible**, and left the empty state claiming the traveller had liked
  nothing. The picker and `ItineraryEditor`'s accept rule must agree; they
  both key off swipes, active destinations only.
- **Trip length is chosen in two places, and they are not the same mechanism.**
  The generation form has a `days` field; the itinerary page has an
  **Add a day** button that POSTs to `itineraries.days.store`. Pre-generation
  sets the length up front and the generator lays out all the days;
  `ItineraryGenerator::durationFor()` takes the posted value and falls back to
  `$profile->trip_duration_days` when it is blank or absent, so every existing
  caller that never posts `days` still works.
  `Itinerary::MAX_DAYS` (30) is the single ceiling and matches what
  `PreferenceController` validates on the profile.
- **Add a day is its own endpoint, deliberately not a field on the editor
  form.** A stop posts `items[<key>][day_id]`, and `UpdateItineraryRequest`
  checks that against the trip's own day ids, so a day has to exist as a row
  before any stop can be filed against it. Inventing the row in the browser
  would mean posting a day id the ownership check is built to reject.
  `ItineraryEditor::addDay()` creates the row, dates it from the trip's
  `start_date` with the same arithmetic the generator uses (undated trips stay
  undated), and bumps `trip_duration_days` so the header count cannot drift
  from the days on screen. It adds exactly one day and refuses past
  `MAX_DAYS`.
- **Removing a day is its own endpoint too, and it is deliberately refused
  while the day holds stops.** `itineraries.days.destroy` takes an
  `ItineraryDay`, so `ItineraryEditor::removeDay()` deletes a *day* and never
  its items: silently taking three stops off someone's trip because they asked
  for one fewer day is the kind of loss nobody recovers from and everybody
  blames on the app. The refusal names the day. Two other refusals: a day that
  is not this trip's (403, in the controller, because route model binding
  resolves an `ItineraryDay` from the whole table and nothing about a posted
  day id is specific to the URL's trip) and the last remaining day, which would
  leave the header reading "0 day(s)".
  `renumberDays()` then slides the survivors up from 1 and **re-dates** them
  from the same `start_date` arithmetic. That is the part to keep: leaving the
  old numbers alone would show a trip that appears to begin on its second day,
  and leaving the old dates alone would show day 2 on the date day 3 used to
  be. It walks the days **in ascending order** precisely because
  `unique(['itinerary_id','day_number'])` means each target number must already
  have been vacated by the row ahead of it — a descending walk collides.
- **A day's Remove button is inside the editor form; its form is outside it.**
  The button has to sit on the card it removes, so unlike the add-a-day button
  it cannot just live in the section header. It is bound to a form rendered
  after `</form>` with the HTML5 `form="remove-day-{id}"` attribute — the same
  escape hatch `admin/sources/index` uses. These forms carry Tailwind's
  `hidden` **class**, which is correct here and is not the toggle trap in the
  frontend section: nothing ever shows them.
  `test_each_days_remove_button_points_at_a_form_for_that_day` pins the binding
  and the `@method('DELETE')`, and asserts there is one bound button per day.
- **`test_every_form_posts_to_a_route_that_accepts_its_method` needs a
  two-day trip to cover this.** Its fixture was a one-day trip, and the Remove
  button is suppressed on a one-day trip, so the new DELETE route rendered no
  form at all and the guard stayed green while checking nothing. Widen the
  fixture the next time a control appears only above one day.
- **The add-a-day form is a sibling of the editor form, never inside it.** It
  sits in the section header next to the Edit toggle. The nested-form trap in
  the frontend section applies with full force here: a form inside
  `data-editor-form` would have its start tag discarded and its `</form>` close
  the *editor*, taking every stop field out of the payload with it.
  `test_the_add_a_day_form_is_not_nested_inside_the_editor_form` pins both the
  form's presence and its position.
- Days with no stops render "Nothing planned for this day yet. Edit the
  itinerary and add a stop." That copy replaced "No destinations fit this day's
  schedule.", which was true when a day could only be empty because the
  generator ran out of room, and became a lie the moment a traveller could
  create an empty day on purpose.
- No migration was added — every column it needs already exists.
- **What is verified and what is not.** `ItineraryEditingTest` (49 tests)
  covers the whole HTTP contract, and a manual walkthrough against MySQL
  exercised login → reorder → reflow → add → remove. A later read of
  `itinerary-editor.js` found three more bugs the tests could not see: rows
  cloned from the template were never wired for dragging, the dialog's
  "Add to Day N" radios were ignored in favour of whichever day opened the
  dialog, and reopening the dialog kept the previous batch ticked so the same
  place could be added twice. Those are fixed and their markup contract is now
  pinned. A fourth only surfaced when the user clicked the button: the edit form
  carried Tailwind's `hidden` class while the script toggled the `hidden`
  attribute, so it could never be shown (see the frontend gotchas below). Every
  HTTP-level test passes with the editor permanently invisible, which is the
  argument for reading the rendered CSS, not just the markup.
  **Verified manually by the owner on 2026-10-05**, on the live site: drag,
  arrows, the add dialog and reflow were all clicked through, including
  **cross-day dragging** (added that day). There is still no browser automation
  in this repo, so PHPUnit cannot protect any of it — the tests pin the markup
  contracts and the server-side logic, and the gesture itself rests on that one
  manual pass. **Re-click after any change to `itinerary-editor.js` or the
  editor's markup**, because nothing in the suite will notice a break.

### What was verified, and what was not

Recorded because AGENTS.md previously carried a blanket "unproven" note that was
both wrong and unhelpful. Provenance matters: these were clicked by a human in a
browser, not asserted by a test.

| Verified by hand, 2026-10-05 | Still only covered by markup/server tests |
|---|---|
| Itinerary drag, incl. across days | Drag gestures on a viewport other than the one used |
| Arrow reorder, add dialog, reflow | — |
| Destination photo carousel: scroll, touch, keyboard, dots | Previous/Next enabling as the slide changes |
| Budget tier auto-fill and mismatch warning | — |
| The navigation loading overlay | Timing under a slow connection |

The photo-carousel row is **historical**: that carousel was deleted when its
photographs became stage panels. It is left in the table because the provenance
it records is still true of everything else there — see the destinations stage
section for what has replaced it and for its own "verified by hand" list, which
is currently empty.

The loading overlay is the one that behaves differently by design: it appears
only when a navigation takes longer than 350 ms, so a click-through on a fast
connection proves it does **not** appear, not that it works. Throttle the network
before judging it.

### The destinations stage

A dark editorial theatre, and it is **the whole `/destinations/{slug}` page**.
Mounted on that route only. A fanned carousel of photographs opening on the
destination you have arrived at, on one continuous Deep Volcanic Teal surface
from the nav bar down.

`App\Domain\Destinations\DestinationCarousel` is the presenter.
`components/destinations/stage.blade.php` is the component.

- **ONE ROUTE ONLY, AND THAT IS A DECISION.** The stage opens on the destination
  you have ARRIVED at, with that destination's own photographs in the fan. On
  `/destinations` it would march 65 destinations' photographs past above a grid
  of 9 of the same destinations, and its chevrons — which navigate to the next
  destination's page — would be taking you away from the one page whose whole
  purpose is to let you choose between them. There is **no `?slide=` parameter**
  either, because there is no second route to centre.
  `the_stage_is_on_the_destination_page_and_nowhere_else` asserts it in BOTH
  directions and also asserts the listing still has its search field and its
  cards — asserting only the stage's absence would pass just as happily on a
  listing that had lost everything else.
- **THERE IS NO LONGER A TWO-PAGE SEAM TO KEEP IN STEP.** An earlier version of
  this stage rendered on both routes from one presenter, and the continuity
  feature was that they could not drift. With one route there is nothing to
  drift, and `DestinationCarousel::slides()` takes a **required** slug rather
  than a nullable one — the nullable default was flexibility nothing used.

- **A SLIDE IS A PHOTOGRAPH, NOT A DESTINATION.** Within one destination the order
  is fixed: its hero photograph (`destinations.image_url`) first, then its uploads
  (`destination_images`) in `sort_order`. This is why the destination page's hero
  block and its scroll-snap photo strip were both deleted: their photographs are
  panels now, and an admin's uploads stay reachable from the public page instead
  of living in a strip a redesign could quietly orphan.
  `a_destinations_own_photographs_run_hero_first_then_in_sort_order` pins the order.
- **THE STAGE HOLDS ONE DESTINATION. IT USED TO HOLD ALL 65, AND THAT WAS WRONG.**
  It fanned across every featured destination with the one you arrived at in the
  middle, and the chevrons walked to the others. That reads as the richer idea and
  it is the failure the caption's one-slide rule *cannot* defend against, because
  the wrong thing on screen is a different **destination** — not a second slide.
  Arriving at Fort Santiago showed a photograph of Aguinaldo Shrine under Fort
  Santiago's caption and backdrop. Two places at once, both rendering correctly,
  which is the worst kind of wrong: nobody can describe it precisely enough to
  report.
  `DestinationCarousel::slides($slug)` now filters the cached payload down to one
  slug. **The payload is still the whole catalogue** — filtering an in-memory
  array of scalars is free and a query per page render is not.
  `the_stage_holds_this_destinations_photographs_and_nobody_elses` pins it.
- **THE FAN OPENS ONE-SIDED, AND THAT IS CORRECT.** Offsets restart at zero, so
  the hero photograph is active and everything else is to the **right**. There is
  nothing on the left because there is no earlier photograph of this place. A fan
  that wrapped, or that centred the middle photograph and opened on an upload
  instead of the hero, would be symmetrical and would be lying about which picture
  you arrived at. Three photographs give: hero centred, second right, third
  further right — the ordinary "current and what follows" arrangement.
  `the_hero_photograph_is_active_and_the_offsets_restart_at_zero` asserts that no
  offset is ever **negative**, because a negative one would mean another
  destination's photograph had crept in.
- **THE SECONDARY DESTINATION SORT (`sort_order`, then `name`) IS NO LONGER
  OBSERVABLE** on this route, and that is fine: it orders the cached payload, and
  the payload is filtered to one slug before a slide is returned. It still matters,
  because two requests in the same page load must agree on the payload they are
  both filtering. **Do not "fix" a test for its absence** — assert a destination's
  own photographs instead, as `every_featured_destination_gets_a_stage_at_least_once`
  now does by asking each destination for its own stage and requiring all of them
  to be non-empty.
- **PHOTOGRAPHS REACH THIS APP THROUGH EXACTLY ONE DOOR: AN ADMIN UPLOAD.**
  The CSV has no image column (32 columns, none of them an image), the seeder only
  ever *clears* `image_url` for a new row and never sets it, and
  `database/data/luzon-locations-clean.csv` is the source of truth for the
  catalogue. **So a catalogue with no photographs is the NORMAL state, not a
  broken one**, and the stage has to be designed for that rather than treated as
  the exceptional case. This was learned the hard way: the stage shipped
  rendering an empty slab on every page, because every one of the 65 destinations
  had a NULL `image_url` and an empty `destination_images`.
- **EVERY FEATURED DESTINATION CONTRIBUTES AT LEAST ONE SLIDE, and this decision
  has been got wrong TWICE in opposite directions.** The first version skipped
  destinations with no photograph — no panel, no placeholder. That was defensible
  when the stage was one block on a page that still had a hero, fees and hours
  below it. It became indefensible when the stage became **the page's opening**,
  because a destination with no photograph then rendered a screen with nothing in
  it. It also had a quieter cost: `is_featured` is meant to be CURATION, and a
  photograph-less destination was being excluded by a *photograph* rather than by
  a decision, so curating 65 rows and watching some vanish for want of an upload
  is the opposite of what the flag says it does.
  So a destination with no photograph gets **one slide with an empty `imageUrl`**,
  and `stage-panel-inner` renders that as a **TYPOGRAPHIC PLATE** — province,
  name and municipality set in type on a lifted ground.
  **The plate must never imitate a photograph**: no `<img>`, no empty `src`
  (which browsers render as a broken-image glyph), no flat colour pretending to be
  a photo behind a scrim. The fan's rule that every panel is a real photograph
  governs the panels that *are* photographs.
  `every_featured_destination_appears_in_the_stage_at_least_once` is the
  invariant, and `a_destination_with_no_photograph_gets_a_typographic_panel` is
  the plate.
- **`is_featured` IS THE ONLY CURATION SWITCH.** Active + featured is the stage's
  membership test, and nothing else. Do not reintroduce a photograph test.
- **THE ACTIVE INDEX COMES FROM THE URL.** `show()` passes the destination's
  slug, which resolves to that destination's **first** photograph. That is what
  makes a hard page load land in the same visual state as an in-page advance, so
  deep links, Back/Forward and a shared link all replay.

#### The page is ONE surface, and that inverted the colour rule

There is no Palawan Sand on this page. It began as a dark theatre set into warm
paper with the heading and the filtered bar on the paper above it; when the stage
was asked to be the whole page rather than a panel on it, **the paper went with
it** — a sand surface under a teal one is two surfaces, and the composition only
reads as one world with one ground. The layout's own header is already
`bg-volcanic-teal`, so the page continues the nav bar rather than starting a new
surface.

**Which means the whole colour rule inverts.** Gold and Boracay Turquoise were
forbidden as small text because they fail on SAND — gold is 1.8:1 and turquoise
2.9:1 there. On this teal ground the same gold is 8.7:1 and the same turquoise
carries white or teal text, so gold is now the accent colour. What must not
appear is the **DARK** palette: `text-benguet-charcoal`, `text-volcanic-teal`,
`bg-palawan-sand` and `bg-island-white` are all effectively invisible here.

- **THE HEADING HAD TO CHANGE COLOUR, and it is the detail to remember.** It was
  `text-volcanic-teal` because it was on sand. Deep Volcanic Teal on Deep Volcanic
  Teal is 1:1, so leaving it would have made the destination's name vanish.
  `the_whole_page_is_one_dark_surface_with_the_stage_as_its_opening` asserts
  Island White **and** asserts the teal class is absent, and
  `no_dark_palette_text_survives_on_the_teal_ground` caught it independently when
  this was falsified.
- **DEEP VOLCANIC TEAL AS TEXT IS STILL LEGAL IN ONE PLACE**: on a filled button
  (turquoise fill, teal text). So the palette guard is "dark text only on a fill",
  **not** a blanket ban — a blanket ban would forbid the one legal use.
- **THE GUARDS ARE TOKEN-WISE, NOT SUBSTRING-WISE.** The page's one filled
  button hovers to `hover:bg-island-white` and the layout's mobile nav is
  `max-w-[calc(100vw-2.5rem)]`, so a `str_contains` check would have had to be
  wrong about a legitimate case to be useful. Class tokens are compared
  individually and the `100vw` ban is scoped to `main`.
- **THE GOLD BADGE IS A MODIFIER HERE.** `.tm-gold-badge` is a gold wash with
  charcoal text, which is right on every light surface and unreadable here — a
  22% gold wash over teal is a dark background. `.tm-gold-badge--dark` exists for
  the destination page alone, because **ten other pages render that class and all
  of them are light**; a global change would have fixed one page by breaking ten.
- **THE FILTERED BAR IS BORACAY LIGHT, NOT GOLD.** Gold is legal here and is used
  for the accents, but a decorative label is the one place a colour has no job,
  and spending the accent there devalues it everywhere else.
- **CARD SURFACES ARE TRANSLUCENT.** `.tm-panel` is `rgb(255 255 255 / 0.04)`
  with an inset hairline, not an opaque light card: an opaque card on this ground
  is a bright rectangle in the middle of a dark page, which is the flat colour
  block the stage's panels are forbidden from being.
- **STATUS BADGES KEEP THEIR LIGHT PILLS.** The open/closed/hours-kind badges are
  light chips with dark text on the dark ground. They do not tint the background,
  and none of them is colour-alone — each says "Open now" / "Closed now" / "This
  is a registration window, not opening hours".
- **`.tm-primary-button` was fixed too**: Benguet Charcoal on turquoise is 3.98:1
  and Deep Volcanic Teal on turquoise is 5.0:1. The darker grey looked more
  "correct" against the palette, which is exactly why it went unnoticed. Its
  `hover:bg-boracay-dark` already landed on white.

#### The opening screen

`.tm-page__opening` fills `calc(100svh - 6rem)`: `svh` rather than `vh` because
mobile browser chrome changes the viewport height mid-scroll, and `- 6rem`
because `main` begins *under* the nav bar. **`min-height`, never `height`** — the
stage's height follows from `--u` and the composition's aspect ratio, so a fixed
height would crush the fan on a short viewport instead of letting the opening
grow past it. `justify-content: space-between` puts the heading at the top and the
stage on the floor of the screen, which is what makes it read as a stage rather
than a block that happens to be tall.

**`every_fact_the_page_carries_is_still_here` is a LIST of twenty-one facts** —
name, municipality, province, tags, prose, entrance fee, estimated cost, visit
length, the hours label, a per-day row, the weekend split, the hours note, the
timezone, the last-checked date, the cited source, the map and its coordinates,
the budget tier, the reviews, the empty state, the actions, the form. It exists
because the page was restyled from paper to dark, and a restyle is exactly when
a section quietly stops rendering: "assert the description is present" passes on
a page that lost its map. **Add to the list when you add a fact.**

#### `data-offset` is the whole mechanism

**This was inverted once and the stage shipped looking frozen.** The first
version wrote each panel's offset from PHP into a static `data-stage-offset` and
never touched it again, so advancing changed which panel was "active" and never
where the panels were. State updated; layout did not; and **no test failed**,
because the server-rendered markup was genuinely correct.

`data-offset` is signed and relative to the active panel — 0 is active, −1 is the
one to its left — and **every** geometric property is derived from it in
`app.css`. The script changes one integer (`index`), Alpine rewrites the
attribute on every panel, and the CSS transition interpolates.

- **The Blade-written offset is the STARTING STATE ONLY.** It is what makes the
  page correct with JavaScript disabled. Never write a second offset from PHP —
  that is the frozen-stage bug.
- **THE PAYLOAD ISLAND MUST BE A DESCENDANT OF THE ELEMENT THAT READS IT.**

  THE BUG THIS EXISTS FOR. `readPayload()` reads the island with
  `this.$el.querySelector('[data-stage-data]')`, and `$el` is the
  `<section x-data="destinationStage">`. The island used to be rendered as a
  SIBLING of that section, one line further down the same Blade file, which
  reads as tidier and is outside the subtree the script searches. The query
  returned null, `.textContent` threw, and the `catch` returned `slides: []`.

  The failure is SILENT and TOTAL, and it is worth writing down in full
  because every symptom looks like a different bug:

  - `count` is 0, so `autoplayable()` is false and autoplay never starts;
  - `index` clamps to 0, so `offsetOf()` returns `i - 0` and the fan
    re-indexes itself onto whatever the FIRST panel in the DOM is -- a
    photograph of a completely different destination;
  - `go()` clamps every target to 0 and returns early because the target
    already equals the index, so the dots and the chevrons all do nothing;
  - the offsets have been rewritten to 0, 1, 2 ... and then never change,
    so nothing animates;
  - and the caption, the backdrop and the server-rendered offsets all still
    describe the destination that was actually asked for.

  So the page showed one destination's photographs under another
  destination's backdrop, with every control inert and no motion, and
  nothing anywhere reported an error. Every HTTP-level test passed
  throughout, because the server-rendered markup was genuinely correct.

  Fixed by putting the island INSIDE the section, and defended twice over:
  - `data-stage-start` on every panel records the server's offset in an
    attribute the script NEVER writes, so an unreadable payload leaves the
    stage as rendered instead of scrambling it;
  - `go()` refuses to move and `autoplayable()` refuses to start when the
    payload is unusable, because a DEGRADED stage and a WRONG stage look
    identical from outside and only one of them shows the wrong photograph.

  `the_payload_island_is_a_descendant_of_the_element_that_reads_it` and
  `an_unreadable_payload_freezes_the_fan_where_the_server_put_it` assert both
  halves of this, and both were falsified by moving the island back out of the
  section and by reintroducing a duplicate `offsetOf` definition underneath
  the real one (in an object literal the last definition silently wins).
- **All slides stay in the DOM**; offsets past the reach are pushed off-stage with
  `data-far` (`visibility: hidden`, `pointer-events: none`). A re-rendered
  five-element window cannot animate: the panels would be new elements with
  nothing in the compositor.
- **NEVER HIDE A SLOT FROM THE SCRIPT.** No `display: none` on a narrow screen
  and none under `prefers-reduced-motion`. A slot the script cannot see is one it
  steps onto, and the fan then looks frozen on a phone. Push it further out
  instead — which is what the narrow container query does.
- `the_fan_actually_moves` asserts all four conditions at once (binding,
  computed-from-index, geometry keyed on the attribute, a transition on it)
  because dropping any one of them reproduces the freeze. It was falsified by
  deleting the binding.

#### The geometry, and why the stage is 68rem

`--u: calc(100cqw / 1088)`. One `--u` is 1/1088th of the stage's width, so every
dimension is written once as a number and scales as one piece. At the reference
the stage is exactly 1088px, so `--u` is 1px and the numbers are literal pixels:
a **320 × 570** centre panel (`width: calc(320 * var(--u))` plus
`aspect-ratio: 9 / 16`), the flanking pair **300** units out at `scale(0.79)` and
`rotateY(∓7deg)` nudged 8 down, the outer pair **532** out at `scale(0.58)` and
`rotateY(∓14deg)` nudged 14 down.

- **THE GAPS ARE SOLVED, NOT EYEBALLED.** The centre panel's half-width is 160
  and the flanking panel's scaled half-width is 126.4, so 300 leaves a **13.6px**
  gap. The outer panel's near edge at 532 is 443.2 against a flanking far edge of
  426.4 — a **12.8px** gap, and at the 536 the reference first suggested it would
  be 16.8px, over the band. Both sit in the 10–16px window.
- **THE 68rem CAP IS WHAT CROPS THE OUTER PAIR.** Its far edge is 624.8 from
  centre, so a stage narrower than 1250px cuts it, while the flanking pair's far
  edge at 426.4 needs at least 853px to stay whole, and 1088px sits in that
  window. `overflow: hidden` on `.tm-stage` is therefore load-bearing.
- **THE SLAB SITS ON ITS OWN COLOUR NOW**, so it is carried by depth rather than
  by hue: the blurred backdrop, its own radial vignette, and the `box-shadow` on
  `.tm-stage-wrap`. Remove the shadow and the fan floats in a void with no edge.
- **`cqw`, never `vw`** — the page container runs to 1600px, and viewport units
  would keep growing the fan past the cap.
- **`--u` must be declared one level BELOW `container-type`.** An element cannot
  resolve container query units against its own container; on the container
  element they read the nearest *ancestor*, which here would be nothing.
- **THE STAGE MUST DECLARE A WIDTH, NOT ONLY A MAX-WIDTH, AND THIS COST A
  STAGE.** `.tm-stage-wrap` is a flex ITEM in `.tm-page__opening`, which is a
  *column* flex container — so the cross axis is horizontal, and on the cross axis
  **`margin-inline: auto` disables the default `align-items: stretch`**. The item
  stopped being stretched and was sized by its content instead. Every panel inside
  is `position: absolute`, so the stage's content width is zero, and
  shrink-to-fit of zero is zero.
  The consequence ran the whole chain: `.tm-stage` at `width: 0` → `--u` of
  nothing → `.tm-fan { height: calc(660 * var(--u)) }` → `0px` → every
  absolutely-positioned panel clipped out of sight by the stage's own
  `overflow: hidden`. The panels were in the HTML, every image URL answered 200,
  the stylesheet carried every geometry rule, and **all 352 tests passed**. The
  page rendered a photograph-free stage and reported nothing, because the defect
  was computed GEOMETRY and **no test in this repo measures any**.
  It worked before the stage became the page because the stage was in BLOCK
  layout then, where `margin-inline: auto` + `max-width` does exactly what it
  looks like. **Rule: any `margin-*: auto` on a flex item needs a definite width
  beside it.** `width: 100%` + `max-width` + `margin-inline: auto` is correct in
  both contexts.
  `the_stage_declares_a_width_and_not_only_a_max_width` asserts the declaration
  and `the_stage_wrapper_is_a_flex_item_of_the_opening_column` asserts the
  flex-item relationship that makes it load-bearing; both were **falsified** by
  deleting `width: 100%`.
- **A BRACE INSIDE A CSS COMMENT BREAKS BRACE-MATCHING READERS.** Legal CSS, and
  it shipped here while the stage was broken for an unrelated reason — but a `}`
  in a comment terminates any tool that reads `app.css` by matching braces, which
  is how a build gets "verified" against a rule that was never parsed. Comments
  are prose; prose does not need braces.
  `no_comment_in_the_stylesheet_contains_a_brace` is the guard, and it is falsified
  by putting one brace in a comment.
- **THE ROTATION SIGN MUST MIRROR.** A same-sign rotation tilts the fan into a
  `>` instead of the shallow V.
- **NO STAGGER.** One formation, 620ms, one ease-out.
- Side panels are dimmed by a **teal overlay inside the scrim element**, not by
  panel `opacity` — an overlay keeps the photograph opaque, and a see-through
  panel is the glass look the design forbids.

#### The caption: one slide, never two

This is the guard to read first. Slides are photographs **grouped by
destination**, so two adjacent slides can be two different places and the fan can
be sitting on any of them. A caption assembled from "the active panel" and "the
previous active panel" would eventually show one destination's name over another
destination's peso figure, with both halves rendering correctly and every test
passing.

So **every caption binding calls `active()` exactly once and reaches for nothing
else.** The guard asserts that directly rather than matching `active().` as a
substring — a substring match accepts `active().name + previous().name`, which is
the exact bug in a form that looks correct in a template. That guard was
falsified with a two-slide binding, and **the first version of it did not catch
it**, which is why it asserts the invariant now.

- **It is stage-level, never a child of a panel, and it sits BELOW THE FAN
  IN FLOW, between the fan and the pager** (a caption inside a panel is
  clipped by that panel's `overflow: hidden`). It used to be absolutely
  positioned over the lower third of the photographs, which was the
  reported "text clashes with the images" — no scrim carries a headline
  over a full-brightness photograph without reading as a band across it.
  The `pointer-events: none` that went with the overlap went with it:
  nothing is under the caption any more, so there is nothing to click
  through and the text is selectable like any other caption.
- **IT IS AN `h2` AND NEVER AN `h1`.** The page's heading is on the ground above.
- **The name and the province are two block-level spans**, so the heading breaks
  onto exactly two lines.
- **THE STANDFIRST AND THE BODY DESCRIPTION ARE A PAIR.** `standfirst()` is the
  description's first sentence and the caption prints it; `bodyDescription()`
  prints the rest in section 01. Printing the description in both places put the
  same opening sentence on screen twice within one screenful. **A one-sentence
  description returns an EMPTY standfirst** — taking it would empty the page.
- **THE GOLD KICKER IS AN INTEREST TAG, NOT A REGION.** There is no `region`
  column, and the province already appears twice in the caption. A PSGC
  province→region mapping belongs in a seeder with a source behind it, not
  inferred in a view. `kicker()` sorts tags by name because `destination_tag` is
  a bare composite key with no ordering column.
- **THE TWO NUMBERS ARE SCRIPT-OWNED AND CARRY NO `x-text`** — the count-up writes
  their `textContent`. `font-variant-numeric: tabular-nums` is load-bearing: a
  number that changes width while it changes is worse than one that does not
  animate.
- **THE CURRENCY SYMBOL IS DECLARED ONCE, IN THE MARKUP, AND THE SCRIPT
  ONLY READS IT.** The cost metric's value is animated, so `syncNumbers()`
  formats it as `costPrefix() + number`, and `costPrefix()` reads
  `data-stage-prefix`, which `stage-caption.blade.php` sets to `₱`. It used to
  ALSO emit a hard-coded `₱` of its own, and the caption read `EST. COST` and
  **`₱₱225`**. Only after the stage hydrated, too: the server-rendered fallback
  comes from `CarouselSlide::peso()`, which adds exactly one, so the markup was
  right and the script was wrong and nothing else on the page disagreed. A test
  asserting the caption "shows the estimated cost" passes on both.
  `the_currency_symbol_is_declared_once` pins the invariant, and it strips block
  comments from `stage.js` before searching — the `syncMetrics()` docblock quotes
  `₱1,250` as prose about what a stray separator looks like, and an unstripped
  search reads the comment and concludes the code is wrong. The positive half is
  load-bearing too: without it, "fix" the doubling by deleting `costPrefix()`
  and leaving the hard-coded sign, which renders correctly and makes
  `data-stage-prefix` dead markup nobody notices is now the currency's only
  declaration.
- **THE MATCH SCORE IS EMPTY FOR A GUEST**, which is the honest answer rather than
  a missing feature. The label **and the separator that follows it** are hidden
  together, or the row reads "· EST. COST ₱450". It comes from
  `RecommendationService`, so the stage quotes the same figure the
  recommendations page does.

#### Controls

- **CHEVRONS, DOTS, ARROW KEYS AND DRAG ALL DO THE SAME THING.** They all move
  the fan through this destination's photographs. The chevrons used to be **links
  to the neighbouring destination**, which was correct while the stage fanned
  across the catalogue and is wrong now that it does not: a control that navigates
  off the page you are reading, shaped exactly like the controls that move the
  pictures in front of you, is a control that lies about what it does. There is no
  wrap-around, and no `neighbours()` any more.
- **THE CHEVRONS ARE RENDERED AT BOTH ENDS AND `disabled`d — NOT OMITTED.** This
  is the opposite of what they used to do, and the reason is the drag: omission was
  right for a link whose target does not exist, and wrong for a button that
  disables. A keyboard user's focus can be sitting on a control that is about to
  stop existing, and the stage visibly rearranges itself every time it reaches an
  end. They keep their position and carry `disabled` plus `aria-hidden` from the
  script. `inert` is not used: not universal enough to rely on alone.
  `the_chevrons_move_the_fan_and_stay_mounted_at_both_ends` pins both halves, and
  `a_one_photograph_destination_has_no_chevrons_and_no_pager` pins the other case —
  a destination whose owner has uploaded nothing has **no** chevrons, which is the
  normal state, not a missing feature.
- **THE CHEVRON `disabled` BINDING IS A PLAIN BOOLEAN, AND THAT IS A
  TRAP WITH A SCAR.** It was once written as
  `index === 0 ? true : count - 1` with its twin
  `index >= 0 ? true : count`, and the stage shipped **two permanently
  disabled chevrons with the suite green**: `index >= 0` is always true,
  and `count - 1` is a positive NUMBER, which Alpine treats as truthy
  when it is bound to `disabled` — a truthy non-boolean disables a
  button. The expression is now `$side === 'prev' ? 'index === 0' :
  'index === count - 1'`, asserted verbatim by the test. Any ternary
  with a non-boolean arm in a `x-bind:disabled` is this bug again.
- **THE FAN IS DRAGGABLE**, and five pieces of it are load-bearing, each failing
  silently on its own:
  - **`touch-action: pan-y`** on `.tm-fan`. Without it a swipe on a phone is a coin
    toss between scrolling the page and moving the carousel, and the browser
    delays every `pointermove` until it has decided.
  - **`setPointerCapture` ON THE FAN**, not on the panel the press started on. The
    panels are narrower than the fan, so a drag travelling more than a panel's
    width leaves that element — and without capture the gesture dies halfway and
    the fan snaps back under a finger that is still moving.
  - **A CAPTURE-PHASE CLICK INTERCEPTOR ON THE STAGE.** By the time a click event
    exists there is no drag left to cancel: the browser has already synthesised it
    from the same pointer sequence. Every panel is an `<a>`, so without this every
    drag that ends over a card navigates away. It must be *capture* phase and on an
    **ancestor** of the anchor; `stopPropagation` there means the anchor never sees
    it.
  - **THE TRANSITION IS SUSPENDED WHILE DRAGGING** (`.tm-fan.is-dragging`) so the
    fan tracks the pointer instead of lagging behind it, and restored on release
    so a cancelled drag eases home rather than jumping.
  - **THE BROWSER'S OWN DRAG IS CANCELLED.** The photograph
    and the link wrapping it are both draggable by default, so
    a horizontal pull starts a native drag-and-drop: a ghost
    image follows the pointer, `pointercancel` fires, and the
    fan's gesture dies halfway. `draggable="false"` on both
    elements is the first line of defence and works without
    JavaScript; a `dragstart` listener on the fan is the net
    under it, because `dragstart` bubbles and so cancels the
    gesture for every descendant whatever it started on.
    `user-select: none` on the fan takes the text-selection
    path too.

  `--tm-drag-x` is the only thing the script writes; it feeds a transitioned
  `transform`, and the transition applies to the **resolved** value, so a cancel is
  just setting it back to `0px`. No `@property` registration and no frame juggling.
  A **6px dead zone** keeps a tap from counting as a gesture, and a **distance**
  threshold commits an advance rather than a velocity — a flick has one pointer
  event and a drag has thirty, and they must not disagree about the same
  physical distance. **The threshold is a fifth of the PANEL in front of you
  (floored at 48px, capped at 120px), not a share of the fan's width.** It was
  once 15% of the fan, which was defensible while the fan was capped at 68rem
  and is not now that the stage fills the page: at a 1500px fan that is 225px
  of travel before a release commits, which is exactly the "hard to grab" the
  stage was reported as. A threshold that grows with the empty space around
  the photographs grows with the wrong thing. Resistance is `* 0.75`,
  deliberately light — heavier makes a swipe feel like wading once the
  threshold is panel-sized, and `cursor: grab` on the fan is what tells a
  visitor the photographs can be pulled at all.
  `the_fan_is_draggable_and_the_gesture_has_the_parts_that_make_it_usable` pins all
  four.
- **THE PAGER SITS ON THE TEAL**, not on the paper: its dots are Island White and
  Island White on Palawan Sand is invisible, so the slab extends to include the
  control strip. The dots show a **window** the size of the fan's reach — the point
  is that a destination has a handful of photographs, and a dot per photograph in
  the whole catalogue is a texture, not a pager.
- **AUTOPLAY IS ON AND THE PAUSE CONTROL IS MANDATORY.** This reverses an earlier
  decision on this page, which had shipped the stage with neither ("a timer that
  keeps shifting the fan under someone reading it is worse than no timer"); it was
  changed on request. WCAG 2.2.2 still applies.
  `DestinationController::STAGE_INTERVAL_MS` is **7000**, not the customary four
  seconds: the stage's own motion is 620ms of panel travel plus an 800ms backdrop
  cross-fade, and a shorter dwell reads as an interruption rather than as rhythm.
- **HOLD REASONS, NOT A BOOLEAN** — `held` is a list, and only FOCUS
  and DRAG belong to it. Hover used to hold it too, and that was the
  reported "it doesn't auto-play": the stage is the whole opening
  screen, so a desktop visitor's pointer is over it for exactly as long
  as they are reading it, and a timer held for the entire dwell is a
  timer that never fires while anyone is watching. The pause control is
  the WCAG 2.2.2 mechanism for stopping content that moves without being
  asked — it is always in the accessibility tree and always visible —
  and that is where the decision belongs, not under the visitor's cursor
  by accident. A hidden tab also stops it.
- **THE FOCUS HOLD IS FOR KEYBOARD FOCUS ONLY, AND IT WAS REPORTED AS
  "AUTOPLAY ISN'T WORKING" FOR THE SAME REASON HOVER WAS.** Every
  control on the stage is focusable and **focus does not leave on its
  own** — once a click lands on a dot, a chevron or the pause button,
  focus sits there until the visitor clicks somewhere else. So a plain
  `focusin` → hold is a timer that stops on first interaction and is
  restarted by nothing the visitor is doing. `focusIn($event)` now tests
  `:focus-visible`, which is the line the platform already draws: a
  pointer click does not set it, a Tab does. A browser without
  `:focus-visible` holds, which is the safe direction to fail in.
- **`toggle()` RELEASES THE FOCUS HOLD, OR THE PAUSE CONTROL CANNOT
  RESUME.** The hold is a reason not to move the fan under a keyboard
  visitor working the dots; it is not a reason to ignore an explicit
  request to start again. Without the release, the click on the pause
  button had itself pushed `'focus'` onto `held`, so `autoplayable()`
  was false **on the very call that was supposed to resume** — the
  control's label read "Resume the slideshow", the glyph showed play,
  and the stage sat still. Every part of the interface agreeing that
  autoplay was running is what made it read as broken rather than
  paused.
- **AUTOPLAY NEEDS MORE THAN ONE PHOTOGRAPH, AND THE STAGE HOLDS ONE
  DESTINATION.** `count` is *that* destination's photographs — hero
  plus uploads — because the payload is filtered to a single slug. A
  destination with one photograph has nothing to advance to, so
  `autoplayable()` is false and the pager (which carries the mandatory
  pause control) does not render either. **So "autoplay isn't working"
  is very often a statement about the catalogue, not the timer**: check
  the destination's photograph count before touching `stage.js`. It is
  `1 + COUNT(destination_images)` per destination.
- **THERE IS NO `x-cloak` RULE IN `app.css`**, so the pause/play glyph swap is CSS
  keyed off `aria-pressed` — the attribute the control needs anyway — rather than
  `x-show`, which would paint both glyphs for a frame. `element.hidden` on the
  match metric is fine for the same reason: the `hidden` *attribute*, never a
  Tailwind `hidden` *class*.
- **`settled` is ONE flag driving TWO fades**, the caption's and the pager's, so
  they cannot disagree about when the fan settled.
- **`init()` PUSHES STATE INTO STEP RATHER THAN TRUSTING THE RENDER.** It calls
  `syncImages()`, `syncNumbers()` and `syncMetrics()`, which look redundant — the
  server already renders the preload window, the opening numbers and the hidden
  guest metric — and are not. `index` is **clamped** to the payload's slide
  count, so a stale cache entry shorter than the rendered markup lands the fan on
  a panel that was never given a photograph. Three idempotent passes over a couple
  of hundred nodes take "the server thought so" off the critical path, which is
  the same shape of fault as the width bug below: everything the server said was
  true and only the computed reality was wrong.

#### `stage.js` is an Alpine component, not a Vite entry

Registered in `app.js`, **before `Alpine.start()`**. Both halves matter and both
have bitten: registering after `start()` leaves `x-data` unevaluated, so the
panels render and the script never attaches — indistinguishable from a frozen
stage and reporting nothing; and a standalone entry whose export nobody imports
gets tree-shaken, which produced a 0.00 kB bundle once already.
`the_alpine_component_is_registered_before_alpine_starts` strips comments before
searching, because the note above the registration in `app.js` contains the
literal text `Alpine.start()` and an unstripped search reads the comment and
concludes the code is wrong.

#### Motion, and the path that is not motion

620ms panels, an 800ms backdrop cross-fade between **two** layers (one `<img>`'s
`src` can only be swapped, not cross-faded), and a 450ms caption cross-fade with
a 6px rise **delayed until the panels settle** — `PANEL_MS` in `stage.js` must
match the 620ms in the stylesheet, because that delay is the caption's cue. Under
`prefers-reduced-motion` the arc transforms are **removed, not shortened** (the
fan's whole depth cue is carried by them), the panels stack on the centre, and
everything except the active panel fades — which, because they are all in the
same place, is a cross-fade rather than a hard swap.

#### The cache

`destinations.stage.v2` holds **arrays of scalars**, not objects, and holds the
**whole catalogue's** slides — a page only needs one destination's, and filtering
an in-memory array is free where a query per render is not. The cache driver is
the database, entries are serialised, and they **survive a deploy**: an object
graph outlives the class that built it, unserialises to `__PHP_Incomplete_Class`,
and behind a strict return type takes the page down instead of costing one rebuild.
`CarouselSlide::fromCache()` returns null for anything unexpected, which the
caller treats as a **miss**. Bump the key when changing the payload's shape — it
went to **v2** when the stage stopped fanning across every destination and the
`destinations` order list went with it, so a live `v1` entry is a miss rather than
a silently wrong page.

It is busted from **two** models, because they are two tables: `Destination` and
`DestinationImage` (a photograph IS a slide). Without the second, an admin
uploads a photo and the stage does not show it for ten minutes; without the
first, an archived destination stays in the stage while its own page 404s.

**`is_active` is PUBLISHING; `is_featured` is CURATION.** Both default so all 65
rows participate. Do not merge them.

#### Removed, deliberately

`resources/js/gallery.js`, `destinations/partials/carousel.blade.php`,
`.tm-no-scrollbar`, the destination page's hero block, the `header` slot on the
destination route, **and the stage's include from `destinations/index.blade.php`**.
Do not re-add any of them.

**Verified by hand: the WIDTH BUG, and nothing else.** No browser automation
exists in this repo, so the choreography, the autoplay timing, the count-up, the
chevron hover states and the reduced-motion path have not been clicked, and
**neither has the page-wide dark restyle**. The tests pin the order, the
mechanism, the composition and the markup/CSS seams; the motion and the look need
a click-through that has not happened.

**What a console probe DID establish**, and it is worth recording because it is the
only measurement of this stage that has ever been taken: reading
`getComputedStyle` on `.tm-stage`, `.tm-fan` and `[data-offset="0"]` reported
`stageWidth: 0`, `fanHeight: 0px`, `cardWidth: 0px` — which located the flex
auto-margin trap instantly after twenty greps of the served HTML had found
nothing. **When a stage bug resists the markup, go and read the computed
geometry; do not keep grepping the HTML.** A dump of
`{ stageWidth, fanHeight, cardWidth, cardHeight, u, imgSrc, imgDataSrc, imgBox,
imgComplete, imgNatural }` is about eight lines and settles it. The fix is not
verified until someone sees photographs on the page, which has not happened yet.
**Click through the whole page after any change to `stage.js`, the
`[data-offset]` rules, the panel markup or `.tm-page`.**

### The discover deck

`/discover` speaks the stage's language now — one dark surface
from the nav bar down, the two cards behind the top one fanned
out like the stage's flanking pair — but it keeps its own job:
one place at a time, a binary decision, the card flies off and
the next one takes its place. It is a restyle plus a fanned
stack, not a stage conversion: the product flow (decide on each
place) is untouched, and **autoplay is deliberately NOT
ported** — a timer that swipes a traveller's decisions for them
is not a showcase, it is a loss of data.

Contracts worth knowing:

- **The layout's `main` is bare on this route** (`w-full` via
  `request()->routeIs('discover.index')` in `layouts/app.blade.php`),
  so `.tm-page` needs none of the destination page's `-mx-*`
  bleed. Copying those classes here overruns the viewport —
  there is no padding for them to cancel.
- **The deck's frame is deliberately WIDER than the card**
  (`max-w-[44rem]` frame, cards `inset-x-4 sm:inset-x-16`).
  A horizontal fan is only visible where the frame exceeds the
  front card — the stage's flanks show for the same reason
  (the stage is far wider than its centre panel). A frame
  exactly as wide as its front card crops its own fan to
  nothing, which is why the deck is not `max-w-xl` any more.
  **The deck deliberately does NOT clip.** The fanned cards
  are meant to continue past the frame into the page's own
  padding, and a swipe has to FLY off the screen —
  `overflow-hidden` on the deck would cut the dragged card
  off at the frame's edge, an invisible border the gesture
  dies against (reported verbatim as "the swiping is getting
  cut off by an invisible border"). The page's own
  `overflow-hidden` (on `.tm-page`) is the scroll guard, and
  a clipping ancestor is a clipping ancestor wherever it
  sits.
- **`FAN_STEPS` in `swipe.js`** is the formation: one card
  each side (`x: -14` / `x: 14`), rotated, scaled down and
  dimmed through `filter: brightness(...)`. The restack animates
  through `.tm-swipe-card`'s transition on `transform`,
  `filter` and `opacity` — the script manages the DRAGGED
  card's transitions inline (off while the finger is down),
  every other card moves through the stylesheet rule.
- **The swipe threshold is a fifth of the card** (`dragThreshold()`,
  floored 48px, capped 120px), never a fixed pixel count —
  a fixed 110px was a third of the card on a phone.
- **The native-drag protection is the same three layers as the
  stage's**: `draggable="false"` on every card photograph, a
  `dragstart` cancel on the deck (bubbles, so it covers every
  card), and the `pointercancel` → `dragEnd` wiring that was
  already there. Do not ship a draggable surface in this app
  without all three — it was fixed twice.
- **A photo-less destination gets a typographic plate**
  (`.tm-swipe-plate`), never the gradient it used to carry —
  the stage's rule: a flat colour pretending to be a photograph
  is a photograph the app does not have.
- **The palette inverts exactly like the destination page**:
  gold and Boracay Turquoise are the accents; `text-benguet-charcoal`,
  `text-volcanic-teal`, `bg-palawan-sand` must not appear. The
  exceptions are light surfaces that are the point: the count
  pill and the reset dialog stay light chips with dark text.
- `InteractionMarkupTest::primary_actions_carry_press_feedback`
  pins `active:scale-[0.98]` and the "Like" label on this page —
  keep both on the Like button when restyling.
- **THE PAGE'S GROUND IS A BLURRED, DARKENED PHOTOGRAPH**
  (`.tm-discover-backdrop`, two layers — the destinations stage's
  backdrop mechanism moved to the deck; `data-discover-backdrops`
  is the page's first child, `aria-hidden`, two `src`-less
  layers). It cross-fades to the main photo of whichever card
  the pointer is over: the script paints the hidden layer with
  the hovered card's photograph, then the two swap `is-shown`,
  so the ground cross-fades to the photograph instead of
  jumping to it, and returns to the plain teal when the pointer
  leaves. **The card under the pointer is found by HIT-TEST
  (`mousemove` on the deck + `document.elementFromPoint`), not
  by per-card `mouseenter`** — a swipe PROMOTES a card under a
  stationary pointer, and no `mouseenter` ever fires for a card
  that moved under the cursor, so the per-card wiring lost the
  ground after every swipe ("the bg sometimes disappears").
  `positionCards()` therefore re-runs the hit-test after every
  re-formation. **Showing the layer is not conditional on the
  paint**: a layer keeps its `src` after the ground fades back
  to plain teal, so re-hovering the same card must still bring
  the wash back — the old early return skipped the show and the
  ground stayed blank. **Mouse-only on purpose**: a touch has no
  hover to follow, which is why the tracking event is
  `mousemove`, an event a touch never raises. **`z-index: -1`,
  not the stage's `0`** — this page's children are not all
  positioned, so inside the page's `isolate`d stacking context a
  negative layer sits above the teal and below every word; a `0`
  here would put the wash on top of the words. A card with no
  photograph (the typographic plate) changes nothing: there is no
  photo to show, and fading the ground out for the length of a
  hover reads as a fault, not a feature.
- **THE CARD IS AN OPAQUE SLAB of the page's own ground**
  (`.tm-swipe-card`, `background-color: rgb(11 37 43)` — full
  opacity, no alpha). The ground behind the card is a moving
  wash, and the translucent 0.82 version let it bleed through
  the words — the readability complaint. Opaque in the ground's
  own colour, the card reads as a slab of the ground itself,
  lifted by the inset hairline and the shadow: the wash shows
  around the card, never through it.

`tests/Feature/DiscoverDeckTest.php` pins all of the above
(12 tests, including the backdrop's cross-fade mechanism, its
behind-every-word `z-index: -1`, the fly-off swipe, and the
opaque card).

### Moving between places

The destination page has no chevrons of its own — the stage's own chevrons move
the FAN — so this strip, in `components/destinations/place-nav.blade.php`, is
how a visitor reaches another place.

- **THE STRIP IS TEXT AND NAMES, NEVER ARROWS.** The stage's chevrons already
  own the arrow vocabulary on that page and they move the FAN — one
  destination's photographs, no navigation. Two sets of arrows meaning
  different things is the confusion this app has already had one of: the
  chevrons used to navigate to a neighbouring destination while looking exactly
  like the ones that moved the carousel. "Baguio" is also the information a next
  control owes you, and an arrow cannot tell you where you are going.
- **IT SITS BELOW THE STAGE, IN FLOW, AFTER THE PAGER**, so there is a visible
  gap between "this controls the photographs" and "this changes which
  destination you are on".
- **THE ORDER IS `sort_order, name`, NOT THE LISTING'S `latest()`.**
  `latest()` sorts on `created_at` and a bulk CSV import writes many rows
  carrying the same timestamp, so "the next place" would be effectively
  arbitrary and two requests in the same page load could disagree about it. It
  is the same total order the stage's payload is built on, so the strip and the
  carousel describe one catalogue.
- **NOTHING WRAPS.** The first place has no previous, the last has no next, and
  the missing one says so visibly rather than disappearing — a visitor needs to
  know WHY there is no Next. It is `aria-hidden` in the markup: an empty control
  is not information, and announcing "Last place in Luzon" on every page load is
  noise where the absence of a link would have said nothing at all.
- **QUICKHOP IS A PLAIN GET FORM TO ITS OWN ROUTE**, declared **before**
  `/{destination:slug}`. After it, route-model binding resolves "quickhop" as a
  slug, finds nothing and 404s — which reads as a broken search box rather than
  as an ordering mistake (the same trap as `admin/sources/status`). A redirect,
  not a results dropdown: it works with scripting off, cannot drift from the
  listing's search, and lands on the place's own URL so Back/Forward and a
  shared link replay the place. Suggestions are a native `<datalist>`, so they
  cost no JavaScript and no request.
- **EXACT NAME BEATS EXACT PROVINCE BEATS NAME-PREFIX BEATS CONTAINMENT**, in
  one query ordered by a `CASE` expression. And `%`/`_` are escaped: a visitor
  who types `%` is not asking for all 65 places, and unescaped it would match
  every one of them and land somewhere arbitrary.
- **AN ARCHIVED PLACE IS NEITHER A NEIGHBOUR NOR A QUICKHOP RESULT**, and is not
  counted in the total — `is_active = false` destinations 404 on their own
  page, so a link to one is a dead end dressed as a working control.
- **THE STRIP'S CSS LIVES IN `app.css` UNDER `.tm-place-nav__*`, and restoring
  `app.css` from an older commit silently strips it.** The component renders and
  every HTTP test passes while the strip is unstyled, because nothing here
  measures CSS. The tell is a class in the markup with no rule beside it. **When
  reverting a commit that shares a file with a feature you are keeping, diff the
  shared file's rule names against the kept component's markup.**

`tests/Feature/PlaceNavigationTest.php` (11) pins all of it.

### Complete itineraries: places, meals, lodging, and an AI planner

A panel review said "the locations are too small" and that a traveller adds where
they will eat and where they will stay, with real distances rather than
estimates. This section is the two-week revision answering that.

**DAY 1 IS LANDED AND NOTHING ELSE IS.** Schema, models, config and the test
suite. **There is no HTTP client, no job, no route, no seeder and no view.** No
code reads `services.gemini.*` or `planning.*` except one test that asserts the
absence of a planning endpoint, because a client arriving before its test and its
quota budget is the thing this repo keeps warning about. Days 2-14 are listed at
the bottom of this section.

#### `places` is a separate table and must stay one

`Destination` is a curated **attraction** catalogue: `budget_level` is an exact
match against the traveller's profile, `recommended_minutes` is a visit length,
and `hours_kind` is written for gates and registration desks. A hotel has a room
rate and a check-in time, not a visit duration. Filing one as a destination makes
the recommendation score, the swipe deck and the PDF's "EST. TOTAL" describe a
hotel as an attraction.

It also preserves the crawler's boundary. The crawler may propose fees and hours
and nothing else; discovering restaurants and hotels needs a different extraction
and a different approval queue, which is Day 2-3's work.

#### A PRICE IS NULLABLE HERE, AND THAT IS THE POINT

`destinations.estimated_cost` is `decimal(10,2) default 0`, so on that table
"nobody verified this" and "it costs nothing" are **the same stored value**. That
is defensible for an attraction whose fee is published. It is not defensible for
a hotel: a cluster of rooms filed at 0 sums into a trip total as free, and the
app then asserts the trip fits a budget.

`places.price_min` / `price_max` are **NULLABLE**, `Place::hasVerifiedPrice()` is
the only way to cost a place, and `Place::costFor()` returns **null** when the
price or the basis is unknown. An unpriced place prints "Price not listed", which
is a real answer. `test_a_place_price_can_be_unknown_rather_than_zero` and
`test_a_room_night_is_not_multiplied_by_the_number_of_travellers` pin both.

**`price_basis` IS NOT DECORATIVE — it is the panel's budget question.** `₱4,500`
means nothing without "per room per night" beside it. See `Place::PRICE_BASES`.
A **room rate is never multiplied by group size**: six people in one room is
still one room, and the traveller decides how many rooms they need. A meal rate
*is* per head.

#### Coverage is reported PER BUDGET TIER, and never as a total

`RecommendationService`, `SwipeDeckService`, `ItineraryGenerator` and
`SwipeDiscoveryController` all filter `where('budget_level', $tier)` for
**equality**. A place filed under the wrong tier is **invisible** to a traveller
in the other two, not merely ranked lower.

Measured from the CSV: Benguet has **8** destinations, which reads as the
strongest province in the catalogue, but the split is 5 economy / 3 mid-range. A
mid-range traveller can see **three** of the eight. A province-wide count
therefore overstates what any one traveller can plan with.

`Place::clusterCoverage($province)` returns per-tier counts, skips a tier nobody
declared rather than inventing it into the nearest, ignores archived rows, and
counts `priced` separately. `Place::clusterReadiness()` returns **the gaps**, not
a bare false, because the question is always "for which tiers, and what is
missing". `CLUSTER_MINIMUMS` is 6 food / 3 lodging; lodging is the binding
constraint, because food can be left unpriced and a cluster with nowhere to sleep
cannot be planned at all.

#### The three pilot clusters, and why those three

`Place::CLUSTERS` is **baguio** (Benguet), **antipolo** (Rizal), **paoay**
(Ilocos Norte). Chosen on **density**, because "the locations are too small" is a
complaint about coverage and coverage is not a count: sixty attractions scattered
across Luzon raises the number and leaves every trip unable to answer "where do I
eat?".

- **Baguio** — five of the eight Benguet destinations are inside Baguio City
  itself (Burnham, Wright Park, Camp John Hay, Mirador, the Botanical Garden), so
  a two-day trip is short drives rather than four hours of road.
- **Antipolo** — three inside Antipolo City (Cloud 9, the Pintô, Hinulugang
  Taktak) plus four in Tanay, which is the same ridge. Metro Manila also has the
  densest food and lodging coverage in the country, so sourcing is easiest.
- **Paoay** — Paoay (Sand Dunes, San Agustin Church) and Burgos (Cape
  Bojeador, Kapurpurawan) are one coastal run. Six is the smallest of the three
  and it is the best coastal test case.

Deliberately **not** Aurora or Cagayan: five and four destinations but no second
municipality worth driving to, so a trip there is one day out and one day back.

It is a **curation label, not a filter**. Nothing refuses a place because its
cluster is unlisted; the constant exists so the coverage report can say whether
one is ready. `test_the_three_pilot_clusters_point_at_provinces_the_catalogue_
actually_has` reads the **CSV**, not the database, so it fails if the real
catalogue loses Baguio rather than checking its own fixtures, and it asserts the
density premise rather than just the names.

#### Opening hours are SHARED, not copied

`app/Models/Concerns/HasOpeningHours.php` holds all of it — the `HOURS_*`
constants, `daySlugs()`, `normalisedDailyHours()`, `hoursForDay()`,
`perDayHoursLabel()`, `formatTime()` and the rest. It was moved out of
`Destination` **verbatim**: 420 tests passed before and after, which is the only
evidence that an extraction did not also change behaviour.

`Destination::daySlugs()`, `Destination::formatTime()` and `Destination::HOURS_*`
are read as **static** calls by the seeder, the crawler, the admin Blade form and
the tests, and a trait serves all three on the using class exactly as a class
member does.
`test_the_hours_api_is_still_reachable_statically_on_destination` pins that
separately, because the failure would be a fatal in the crawler rather than a
test failure.

**If a rule here is wrong, change it here once.** A copy-pasted
`hoursForDay()` on `Place` is the failure this prevents.

#### An itinerary stop has four shapes

`itinerary_items.item_type` is **attraction | meal | lodging | custom**, and it
**defaults to `attraction`** so the ~435 existing rows need no backfill — the same
reason `is_featured` defaulted true. The discriminator is the reference, not the
type string: a `meal` with no `place_id` is malformed.

- **`custom`** is the one that matters most and the one the panel did not ask for:
  a traveller who already has a hotel booked. No foreign key at all, just the
  `custom_*` columns.
- **A custom entry with an address and no pin is legal and stays unroutable.**
  `custom_latitude` / `custom_longitude` are nullable on purpose and
  `hasKnownLocation()` requires **both**. Half a position is not a position, and a
  caller checking only latitude puts a pin at 0 degrees longitude.
- **A place deleted outright nulls `place_id`; a deleted destination still
  cascades.** `place_id` is `nullOnDelete` deliberately: a restaurant closes and an
  admin retires the row, and cascading would silently delete stops out of a
  traveller's saved trip — the exact loss `ItineraryEditor::removeDay()` refuses to
  make. The stop survives, keeps its type, and prints "Place no longer in the
  catalogue" rather than rendering blank.
- **Read a stop through `placeName()`, `latitude()`, `longitude()`.** A meal has no
  destination and a straight `$item->destination->name` throws on a perfectly
  valid row.

#### The route columns are the panel's "distance, not estimates"

`travel_minutes_from_previous` is a **flat `ItinerarySchedule::TRAVEL_MINUTES`
(45)** for every leg in the app regardless of the actual distance. That is the
estimate being objected to. `route_distance_meters`, `route_duration_minutes`,
`route_provider` and `route_status` are where the real answer goes on Day 6-7.

**`route_status` defaults to `unresolved`, and `routeSummary()` returns null.** It
deliberately does **not** fall back to `travel_minutes_from_previous`: that is the
lie the panel called out. A leg the provider could not resolve says so. A
`resolved` row with no numbers reads as unresolved too, so the half-written state
cannot format a null.

`ItineraryItem::durationLabel()` is the one rounding rule for the item, the map
tooltip and the PDF: `45 min`, `1 h`, `1 h 20 min`.

#### Road-derived legs (Days 6-7)

`App\Services\Routing\RouteService` is the one way to ask how far two pins are
apart by road, fronted by `OsrmClient` and bound in `AppServiceProvider`
(because scalar constructor args are invisible to the container).

- **OSRM with no key, by decision.** The config names OpenRouteService, but no
  key exists — and a routing feature that only works after a signup is one the
  demo cannot show. The client speaks plain OSRM, so `ROUTING_BASE_URL` takes a
  self-hosted endpoint with no code change, and the provider switch is still
  there for the day a key exists. The default base URL is the **public demo
  server**, which is a documented compromise: fine behind a month-long cache
  for a school project, not production infrastructure. Run your own OSRM
  before promising these numbers at scale.
- **Every failure is null.** 500, timeout, empty routes, DNS: all resolve to
  `unresolved` on the item, never to a guess and never to an exception that
  fails the save.
- **Cache first, no table.** Pins barely move, so legs cache for 30 days
  (`ROUTING_CACHE_DAYS`) on whatever driver the app runs. No migration was
  added — a route table would be a second source of truth for tossable data —
  and no geometry column either: `routing.js` already draws the map line in
  the browser, so storing polylines would pay kilobytes per leg to duplicate
  it.
- **Reflow meets the road; the generator does not.** `ItinerarySchedule::reflow()`
  takes an optional per-stop `travel` and the editor fills it from routed legs,
  so a reflowed day is laid out on real gaps. The generator keeps the flat gap
  and `unresolved` legs: routing six legs synchronously inside a generation
  request is the wrong latency trade, and the first edit or reflow resolves
  them. Times the traveller typed are never rewritten on save — `route_*`
  sits alongside, and the read view prefers it.
- **The gap belongs to the leg INTO the stop.** `reflow()` once advanced with
  the current stop's travel, charging every leg one stop late so a day of
  short roads still laid out on the flat estimate. The one-line fix and the
  comment saying why are both in the method.

#### **AN UNRESOLVED LEG IS NOT THE SAME AS NO LEG, AND REFLOW WAS NOT TELLING THEM APART**

Reported live as "Reflow times uses generic rather than actual distance time
average" — correct, and the most important defect in the routing work, because
it is the panel's complaint reproduced inside the feature built to answer it.

**A day laid out on estimates and a day measured on real roads produce
IDENTICAL TIMES.** `reflow()` had to substitute `TRAVEL_MINUTES` where the road
could not answer (it cannot refuse a schedule because one leg is unknown), and
it had no way to say so: an explicit `travel: null` and a caller that supplied
no travel at all took the identical branch. So a reflow that never reached the
road looked exactly like one that measured every leg, and the only evidence was
the user's own eye.

`reflow()` now returns `'measured' => bool` per slot, and
`estimatedGapCount()` counts the unmeasured ones. The endpoint returns
`estimated_gaps` **per day** — not per itinerary, because one unroutable leg in
a week does not make the other six days suspect — and `reflowNotice()` in the
editor states it: "*N* gaps are an estimate, not a measured drive". The
measured case claims what it is too ("timed on real driving distances"), or
the two notices would differ only in tone.

**THE FIRST STOP OF A DAY IS NOT AN ESTIMATED GAP.** It has no leg into it,
which is not a failure. Counting it would report at least one estimated gap on
*every* day including fully-measured ones, and a warning that is always on is a
warning nobody reads. `test_a_days_first_stop_is_never_an_estimated_gap` exists
only for that rule and is falsified by dropping `$previousEnd === null ? true`.

**A CACHED LEG IS STILL A MEASURED LEG.** Pinned deliberately in
`test_a_reflow_reports_its_estimated_gaps`: with a dead road and a warm cache
the count stays `0`, and only a `Cache::flush()` turns it into an estimate.
Reporting a memoised real distance as an estimate would be its own lie.

- **ROUTING FAILURES NOW LOG, AND THAT IS THE POINT.** Both layers swallow by
  design — that is what makes an unreachable road a stored `unresolved` rather
  than a failed save — but a swallow with **no log** is how this reached live
  with nothing anywhere explaining it. `routing.transport_failed` and
  `routing.cache_unreadable` are the two lines that make the host diagnosable
  without a code change. **A guard that turns a loud failure into a quiet
  fallback must replace the noise with a signal, or it has only moved the
  problem.** That is exactly what the cache guard in `73715a9` got wrong, and
  why "the save works now and every leg is generic" was indistinguishable
  from "routing is switched off".

#### **GUZZLE 7 SENDS NO USER-AGENT, AND THE DEMO SERVER ANSWERS 403**

The cause of every leg being an estimate, found on 2026-10-15. **Routing had
never worked on any host.** Every leg was null, so every day was laid out on the
flat 45 — which is why the panel's complaint survived the feature built to
answer it.

Measured, one variable at a time, against the public demo server:

| Request | Result |
|---|---|
| PHP cURL, **no User-Agent header** | **403** |
| `GuzzleHttp/7` | 200 |
| `Laravel Framework` | 200 |
| `curl/8.14.1` | 200 |
| `TraMatch/1.0 (+mailto:support@tramatch.site)` | 200 |

**GUZZLE 7 SETS NO DEFAULT USER-AGENT.** `Client::configureDefaults()` has no
`headers` key at all (Guzzle 6 had `'User-Agent' => default_user_agent()`), so
Laravel's `Http` client went out anonymous and the demo server refused it. The
system `curl` binary on the same machine got 200 at the same moment, which is
what made this look like a CA or network problem for an hour: **the CLI and PHP
disagreeing about a remote host is a client-header difference until proven
otherwise.**

**IT WAS NOT THE CA BUNDLE.** `curl.cainfo` and `openssl.cafile` are empty in
this repo's local `php.ini` too, and PHP's cURL reaches the demo server fine —
the empty values fall back to the system bundle. Do not chase the CA store
because the ini values look blank; probe with PHP's cURL directly, not with the
`curl` binary.

**`test_a_provider_that_refuses_anonymous_requests_still_routes` REPRODUCES THE
PROVIDER'S BEHAVIOUR** — its stub answers 403 to a request with no
`User-Agent` and 200 otherwise — rather than asserting the header exists.
Asserting `$request->hasHeader()` passes while the provider keeps refusing us,
which is exactly the bug. Falsified by removing the header.

Two traps in writing it:

- **`Http::fake()` hands the stub an `Illuminate\Http\Client\Request`, not the
  Guzzle PSR-7 message.** A `GuzzleHttp\Psr7\Request` type hint fails the stub
  for a reason unrelated to routing.
- **The refusal is not a transport failure and gets its own log line**
  (`routing.refused`, with the status and the UA sent). A 403 is not the
  provider having a bad day and no backoff fixes it — the remedy is a header.
  Folding it into "unresolved" is what made this take a live round-trip.

The default UA names a real mailbox for the same reason `crawling.token` does:
an anonymous bot on somebody's free demo server is what gets demo servers
switched off for everyone.
- **The road is never on the test path.** `tests/TestCase.php` stubs the OSRM
  host with a fixed 8.4 km / 10 min leg, because otherwise every two-stop save
  in the suite issues live HTTP. A second `Http::fake()` does NOT override the
  first — the factory honors the first matching stub (`filter()->first()`) —
  so tests needing another answer use `stubOsrm()`, which swaps the standing
  stub's responder instead. The old reflow tests stub a 45-minute road on
  purpose: they prove day-window logic, and a 10-minute stub would move their
  assertions into testing the stub.

#### **THE CACHE WAS OUTSIDE THE GUARD, AND IT FAILED EVERY LIVE SAVE**

Shipped in `08af87c`, found on the host within a day. Every itinerary save
failed with "The itinerary could not be saved" and Reflow filled nothing, and
the two symptoms had one cause.

**`OsrmClient::route()` guarded its transport, and `RouteService::leg()` guarded
nothing.** The `Cache::remember()` call sat *outside* any try/catch, so the
cache store could throw straight out of `leg()`, through
`ItineraryEditor::routedLeg()`, into `writeLegs()` — which runs **inside the
save's transaction**, so an unreadable cache took the whole trip down.

**THE ARRAY CACHE DRIVER CANNOT FAIL AND THE DATABASE DRIVER CAN.** The suite
runs `array`, production runs `database`, and the road layer is the only thing
that calls the cache on every single leg. So the untested layer was exactly
the layer that threw: no test could ever have reached it. **A layer the suite
cannot exercise is the layer that breaks on the host** — the same shape as the
CA-bundle-per-process trap, one layer down.

The fix is at the layer that owns the promise, not the layer that happened to
throw: `RouteService::leg()` wraps `Cache::remember()` in its own
`catch (Throwable)` and returns null. The docblock already promised "every
failure is null" and did not keep it for its own machinery.

**`test_an_unreadable_route_cache_cannot_fail_a_save` FALSIFIES IT**, by
registering a cache store that throws for `route:` keys and driving a real
save. Without the guard: `cache table is not readable`, save failed. With it:
saved, leg `unresolved`. The failure is scoped to the `route:` prefix on
purpose — a store that throws for everything also breaks the throttle
middleware and the session, and the test then fails for reasons that have
nothing to do with routing.

Two harness lessons from writing it, both the checker being wrong first:

- **`Cache::extend()` registers a creator keyed by DRIVER, and the store needs a
  `cache.stores.<name>` entry.** Setting only `cache.default` is
  `Cache store [x] is not defined`.
- **A custom creator must return a `Repository`, not a bare `Store`.**
  `CacheManager` wraps built-in stores itself and expects a custom one
  finished. Returning the store hands a `Store` to every
  `Repository`-hinted dependency (it surfaced as a fatal in
  `DestinationCarousel::__construct`) — a failure that looks nothing like the
  cache being tested.

#### The AI plans; Laravel checks

Written into `config/planning.php` as **data**, so the client cannot drift from
the claim the demo makes, and pinned by
`test_the_model_is_recorded_as_choosing_while_the_server_checks`.

| The model chooses | Laravel decides |
|---|---|
| selection, day assignment, order, a reason | coordinates, price, opening hours, duration, distance, travel time, feasibility |

"Only use our sources" in a prompt **is not enforcement**. An unknown candidate id
and a server-side overwrite are. Prices, hours and coordinates are read from the
database and never from the model's reply.

- **No grounding claim rests on a key.** A Gemini subscription for the Gemini app
  is a **separate billing arrangement** and creates no API entitlement. The key
  comes from AI Studio for the developer API, and free-tier content is used to
  improve Google's products, so **no traveller name, email, booking reference or
  private note may be sent.**
- **The key is server-side only.** It must never reach a `@vite` entry, a `<meta>`
  tag or a data attribute.
  `test_the_gemini_key_never_reaches_a_rendered_page` walks a rendered page for it.
- **One transport mode: DRIVING, and labelled as such.** A driving route is not a
  jeepney, a bus or a boat journey. Travel time stays an **estimate** even with a
  provider: traffic, the clock, transfers and road works are not in it.
- **One request per generation, at most two attempts (proposal + one repair).
  Never a loop.** Opening, editing and exporting a **saved** itinerary costs
  nothing.
- **`config/planning.php` uses HTTP timeouts, not job `$timeout` properties**,
  because the Hostinger worker's `queue:drain` calls `$job->fire()` directly and
  does not enforce a job's timeout the way `queue:work` does. Same reason the
  crawl job's own timeout is not sufficient on this host.

#### What is NOT verified yet

**Nothing here has been clicked in a browser or rendered on a page except by
the suite.** The admin manages 36 researched places, and the itinerary renders
meals, hotels and custom entries through the editor — but no hand has dragged a
meal row, opened the custom-stop form, or watched the incomplete-total notice
appear. The models, the schema and the markup contracts are exercised by
`tests/Feature/PlacesCatalogueTest.php` (31),
`tests/Feature/ItineraryEditingTest.php` (60+) and
`tests/Feature/ItineraryStopTest.php` against SQLite, with the schema also
proven against a throwaway **MySQL** database, because the two disagree about
dropping a column in a foreign key and about nullability introspection.

#### The places catalogue (36 rows, 2026-10-14)

Researched in three parallel batches, one per cluster: 12 Baguio + 11
Antipolo/Tanay + 12 Paoay, plus A Hotel Baguio added afterwards for the economy
lodging gap. Every row carries a real, non-Wikipedia `source_url`, and the
seeder imports 36 with zero rejected.

**Four candidates were replaced rather than invented**, and the replacements are
the evidence the process works: Go Hotels Baguio (no Baguio branch exists —
replaced by Citylight Hotel), Macy's Diner and Emilio's Pagudpud (unverifiable /
does not exist — replaced by the two Batac empanada stalls and three Pagudpud
spots), Golden Valley Hotel Antipolo (Cebu only — replaced by Loreland Farm
Resort), Casa Peregrine (San Mateo, outside the cluster — its replacement Casa
de Robles has **no published coordinates and was left out entirely**, because a
row without a pin cannot be routed or mapped and the seeder rejects it anyway).

**Most prices and hours are blank, and that is the honest state.** Only 9 of 36
carry verified prices (Le Chef buffet, Microtel, Azalea, Manor, Tahanan,
Luljetta's, La Preciosa buffet, Saud Beach, A Hotel) and 10 carry verified
hours. Restaurant menus and hotel rates are mostly unpublished or aggregator-only,
so the catalogue prints "Price not listed" rather than a figure nobody published.

**Coverage per tier after this round** (the seeder prints it on every run):
Baguio economy 5 food / 2 lodging, Antipolo economy 5 food / **0 lodging**,
Paoay economy 5 food / 1 lodging. No tier in any cluster meets the 6/3 minimums
yet. The binding constraint is **economy lodging**: rooms under ₱1,000/night are
rare with published rates, and the candidates the searches surfaced (Boso-Boso,
Casa Azul, 3B Rest House) sell day/cottage/group packages rather than per-room
nights, so they do not fit `per_room_night` without lying about the unit. That
gap is open curation work, not a schema problem.

**One seeder fix came out of this round:** `normalizeMinutes()` forced every
blank or zero visit length to 60, which would have scheduled each overnight stay
as a one-hour attraction. It now takes the place type and keeps 0 for lodging.

#### Days 2-14, in order

| Day | Work | Gate |
|---|---|---|
| 2-3 | `places` seeder from a CSV, admin CRUD, the three clusters curated | a cluster can support a real trip; every row has a source |
| 4-5 | `item_type` through the editor, overnight base charged once, cost basis, custom entries, PDF | a complete trip can be built **by hand**, Gemini unused |
| 6-7 | Routing client, cache, `ItinerarySchedule` takes real legs, map geometry | changing a stop updates every downstream leg |
| 8-9 | Gemini client, structured output, id validation, generation status | an invented id or a failed call cannot save a plan |
| 10 | Feasibility repair, failures, duplicate submissions, locked stops | two clicks cannot produce two plans |
| 11 | PDF, map, sources, unknown-cost labels | every surface tells the same story |
| 12-14 | Tests, Hostinger end-to-end, demo rehearsal | a demonstrable release |

**Defer first if time slips:** conversational revision, automated search
discovery, and geographic expansion. Keep the manual complete trip, routing,
grounded generation and validation.

### Destination-source crawling

`destination_sources` → `destination_source_snapshots` (raw HTML on the `local`
disk under `crawl-snapshots/`) → `destination_update_proposals`.

The `local` disk roots at `storage/app/private`, **not** `storage/app/public`,
so snapshots are never web-reachable and `public/storage` has nothing to do
with them. `DestinationSourceSnapshot` reads them back with `Storage::disk('local')`.
Admin UI: `/admin/sources`, `/admin/proposals`. CLI: `sources:crawl {sourceId}`.

- `RobotsPolicyService` honors `robots.txt` (cached 24 h, `Crawl-delay`, 5 s
  floor). `EthicalSourceFetcher` refuses non-HTTP(S) schemes, loopback/private
  hosts, non-HTML responses, and bodies over 2 MB; `ETag`/`If-Modified-Since`
  make a `304` resolve to status `unchanged`.
- A crawl sleeps ≥ 5 s and makes two HTTP requests, so **every** crawl is
  dispatched as an `App\Jobs\CrawlSourceJob` — the admin button, the bulk
  button, and nothing crawls inside a web request. `sources:crawl {id}` is the
  synchronous path.
- Do **not** try to detect a missing queue worker from the page state. It was
  tried and removed: "jobs waiting while nothing is `crawling`" is true in the
  gap between dispatch and pickup, and between jobs, so it false-alarmed on
  every click. The crawl queue panel shows running vs waiting, and a short
  always-true note explains that a source waits briefly before it starts.
- `DestinationSource::isCrawlStale()` treats `crawling` **and** `queued` older
  than `crawling.stale_after_minutes` as stalled, and stalled rows stay
  retryable — never add a check that skips them, or a source becomes
  uncrawlable forever. `hasCrawlInFlight()` prevents double-dispatching.
- The admin progress bar and queue list poll `GET admin/sources/status`
  (`SourceController::status()`), declared **before**
  `admin/sources/{source}` so the route-model binding does not swallow it.
- Transport failures must be persisted as `status = 'failed'` +
  `error_message` before being rethrown. Otherwise the source silently stays
  `pending` and `/admin/sources` cannot explain what went wrong.
- The crawler User-Agent and contact address come from `config/crawling.php`
  (env `CRAWLER_CONTACT`). `RobotsPolicyService` matches robots groups on
  `crawling.token`, which must stay a prefix of `crawling.user_agent`.
  `CRAWLER_CONTACT` is `support@tramatch.site` in **all three** of `.env`,
  `.env.example` and `.env.production.example`, deliberately not a placeholder:
  a bot identifying itself as `+mailto:admin@yourdomain.tld` is a mailbox nobody
  can complain to, which defeats the point of an identifiable crawler. **The
  server's own `.env` predates that change and still needs editing**, then
  `optimize:clear`, or the old address keeps shipping from the cached config.
- **A `.sh` file in the document root is refused by both `.htaccess` copies.**
  It was not, and a copy of `queue-worker.sh` was readable at `/queue-worker.sh`:
  the `RedirectMatch` rules only cover *named directories*, and the
  front-controller `RewriteRule` has `!-f`, so any real file next to `index.php`
  is served as-is. The worker is the one file here that gets copied to wherever
  cron points, and the document root is the natural place to put it.
  `HtaccessRulesTest` now pins this, plus that the two copies refuse the same
  directories — the duplication is deliberate, since only one file is read per
  layout, so drift leaves half the installs unprotected. **Any new file type
  added to one copy must be added to both.**
- `DestinationSourceParser` only *proposes* changes. Nothing touches
  `destinations` until an admin approves.
- **The crawler may only propose hours and fees**: `entrance_fee`,
  `entrance_fee_display`, `opening_time`, `closing_time`, `operating_status`.
  `name`, `description`, `latitude` and `longitude` are owned by the CSV seeder
  and are deliberately *not* extractable — do not re-add them. They produced a
  false proposal (a page title captured as the destination name) and can only
  ever fight curated data.
- `destination_sources.details_url` overrides the crawled page, because seeded
  `source_url` values point at marketing homepages while fees and hours live on
  a sub page (e.g. BenCab's `https://bencabmuseum.org/location-info/`).
  `DestinationSource::crawlUrl()` resolves it and the fetcher, the robots path
  check and the URL guard all use it.
  **This is not a nicety — it is the difference between data and no data.**
  Measured on 2026-09-27: `bencabmuseum.org/` extracts *nothing* (20,123 bytes,
  0 fields) while `/location-info/` extracts `opening_time` 09:00,
  `closing_time` 18:00 and `operating_status` — matching the curated CSV
  exactly. BenCab's seeder row had lost its `details_url`, so the one source
  that ever yielded data was crawling a homepage.
  **48 of 65 sources point at a bare homepage**, where "no data" is the correct
  answer rather than a fault. `DestinationSourceCrawl::emptyReason()` says which
  case you are in — homepage (set a details URL) or a readable page that
  published nothing — instead of the old "Nothing extractable", which gave an
  admin nothing to act on.

### The crawl target is the cited hours URL

`DestinationSourceSeeder` sets `details_url` to the destination's
`hours_source_url` when the row does not hardcode one. Every one of the 65
destinations has a cited, verified hours URL, and **63 of 65 differ from the
hardcoded `source_url`** — which is a marketing homepage or a dead LGU domain.
Crawling the page the hours were actually read from is the whole point of the
column, and it roughly doubles yield.

Measured before and after across all 65 sources:

| | hardcoded `source_url` | cited `hours_source_url` |
|---|---|---|
| Crawl success | 38 | **55** |
| Failures | 27 | **10** (all Cloudflare / WAF refusals) |
| Sources extracting data | 8 | **18** |

Three citation URLs rotted and are fixed in the `$hours` map: Mirador lost a
`-2` suffix on its WordPress slug, Kapurpurawan's pinaywise.com article was
deleted outright (no sitemap, no slug variant, so it now cites the Ilocos Norte
provincial site), and Las Casas' `/Faq` never existed — that site is a Laravel
Inertia SPA where `/`, `/faq`, `/about` and `/contact` are the only routes and
all but `/` 404, so it now cites the root. **There are no dead source domains
left in the catalogue.**

- **Of the 18 extracted windows, 13 agree exactly with the curated value.** That
  is the real payoff: the crawler independently re-derives the curated hours
  from the cited page without being told the answer, which validates the
  hand-curation rather than replacing it.
- The 5 that differ are all cases where the curated value is better, and the
  guards above already suppress them: Wright Park, Sagada and Anawangin Cove
  are `always_open` (the pages describe an office or a tour, not a gate);
  Taal Basilica is `per_day` (whose stored per-day value is 05–18 daily, i.e.
  the crawler actually agrees, but the guard cannot see that through the
  per-day shape).
- **Piat Basilica is the one genuine disagreement** (page 04:30–17:30 vs curated
  05:30–22:00) and it reaches the proposals queue. That is correct: Piat runs
  seven separate daily Mass windows and is listed below as not fully
  expressible, so it is exactly the case a human should settle.
- **`en.wikipedia.org` is never crawled** (`UNCITABLE_HOSTS`). Five rows cite a
  Wikipedia article, robots.txt permits the article path, and the parser found
  nothing on any of them — so excluding it costs no yield and avoids scraping
  CC BY-SA text into product data. Those five fall back to their hardcoded
  `source_url`.
- The citation and the crawl target are deliberately separate columns. Changing
  what the crawler reads must never change the link shown to a traveler under
  the hours, and vice versa.
- **Every crawl attempt is logged** to `destination_source_crawls`, not just the
  successful ones — that is the only way to tell "refused" from "never ran". It
  records outcome, HTTP status, bytes, duration, whether the page hash changed,
  the fields the parser read, and how many *new* pending proposals appeared.
  `proposals_created` counts only genuinely new rows, so a re-crawl of an
  unchanged page correctly reports 0. The detail page renders this log; the
  list flags sources whose last run extracted nothing.
- `config/crawling.min_delay_seconds` is the politeness floor. It is set to 0
  via `CRAWLER_MIN_DELAY` in `phpunit.xml`, otherwise the suite sleeps five
  seconds per fetch.
- The parser *can* extract an hours range from page text, and it is the only
  extraction that has ever produced data. It only accepts a range carrying a
  real time marker (`:00` or `am`/`pm`) and only proposes when exactly one
  window is found — bare ranges like "aged 6 to 12" and phone numbers are
  ignored, and two disagreeing windows propose nothing. In practice it still
  yields nothing across the seeded sources, so **hours are curated in the CSV,
  not crawled** — see "Opening hours (curated, not crawled)" below.
  `hours_source` and `hours_verified` travel with each value so a reviewer can
  re-check it later.
- `DestinationSourceSeeder` holds the source URLs (the CSV does not), passes
  `details_url` through, and resets `fetchability` on re-seed so a fresh clone
  reproduces the reachability state.
- **Fees are never auto-parsed.** `DestinationSourceParser::feeNotice()` returns
  the raw fee block for the admin to compare against the stored value, because
  fees are tiered and multi-product (BenCab lists museum admission *and* an
  EcoTrail tour with a guide fee). A bot that guesses 200 when the real figure
  is the guide fee is worse than no bot.
- Measured yield: LGU/WordPress sites publish no usable structured data
  (0 `offers`, 0 `openingHours`, 0 peso amounts across 9 snapshots), and about
  half the seeded hosts refuse crawling (403 bot walls, unevaluable robots.txt,
  dead URLs). Check a page yourself before assuming a source will yield data.

- **`unchanged` MUST BE DECIDED BY `content_changed`, NOT BY AN HTTP 304 ALONE.**
  A server can answer `304 Not Modified` only if it gave us an `ETag` or a
  `Last-Modified` to echo back, and most of the seeded LGU and WordPress hosts
  send neither. They answer `200` with byte-identical bytes every time, so every
  re-crawl was filed as `success` — the dashboard's "Updated" — and an admin
  checking for drift was told all 65 pages had been updated when nothing had
  moved.

  The hash already made this knowable: `content_changed` was computed and
  stored on every crawl log row and **nobody read it for the outcome**. It is
  now the thing that decides, and a `200` whose bytes match the previous
  snapshot records `unchanged` — with NO `304` involved, which is the case that
  was mislabelled. The snapshot is still written either way: "we checked and it
  was the same" is itself the result worth keeping. `unchanged` already counts
  toward `finished`, so the progress bar is unaffected.

  This is the whole of the routine-drift capability, and it is deliberately
  small. **There is no `sources:sweep` and no `sources:drift` command.** They
  were built and then removed, because the audit of all 65 sources measured
  only 6 as first-party and machine-readable — for most destinations nobody
  official publishes hours at all, so there is no coverage for a bulk re-crawl
  to recover. Re-crawling individual sources from `/admin/sources` remains the
  tool, and `sources:check` still reports why a page can or cannot be read.

### Redirects, dead hosts, and what the crawler may not propose

A real crawl on 2026-09-27 exposed five defects, all now fixed. Do not
reintroduce them:

- **Redirects must be followed** (cap 5, `strict`). `allow_redirects => false`
  made every `301`/`302`/`307` a hard failure, which killed 6 seeded sources
  outright — `campjohnhay.ph` (301 to `johnhayhotels.com`), `vigancity.gov.ph`
  ×2, `visitclark.com` (302), `sanpablocity.gov.ph` (307) and
  `mayonskydrive.com`. All six return 200 once redirects are followed, and
  Mayon SkyDrive then extracts hours. Following a redirect is not a free pass:
  `reviewRedirect()` re-runs `validateUrl()` on the final URL (so a redirect
  cannot walk into loopback or a private range) and re-checks robots for it
  statelessly via `robotsVerdictFor()`. A redirect into a disallowed path is
  refused. The crawl log keeps `requested_url` and `final_url` so the admin can
  see the configured URL is stale.
- **A non-2xx robots.txt is `unknown`, never `disallow`.** `npdc.gov.ph` and
  `www.mwss.gov.ph` both answer robots.txt with **403**; that was being reported
  as "Blocked by robots.txt", which is the exact lie the tri-state verdict
  exists to prevent. La Mesa's real problem is a **403 bot wall on the page**,
  not robots — it now says so.
- **A non-resolving host is not transient.** `RobotsPolicyService::check()`
  used to *throw* on a DNS failure, and the outer loop then retried it 3× with
  sleeps. `classify()` now maps transport failures to a fetchability and a
  retryable flag: DNS → `dead` and no retry; certificate → `unreachable`, no
  retry, and the message says to fix the trust store; anything else →
  `unreachable` and retry.
- **The transport error is owned by one place.** The inner `catch` in
  `attemptFetch()` only writes the crawl-log row; `fetch()` owns
  `status`, `error_message` and `fetchability`. Two writers produced a message
  that named cURL error 6 and a curl.se URL instead of "the host does not
  resolve".
- **A 200 clears a stale reachability verdict.** `clearedFetchability()` nulls
  `dead` / `unreachable` / `bot_wall` / `robots_blocked` on success, because a
  200 disproves all four. It deliberately leaves `fetchable`, `thin_page` and
  `js_rendered` alone — those describe yield, not reachability. Without this a
  host that came back stayed flagged "Host unreachable" forever.
- **An HTTP failure is classified too**, so the reachability column is honest
  without running `sources:check`: 404/410 → `dead`, 401/403 → `bot_wall`,
  5xx → `unreachable`.
- **A 403 is not automatically "the site blocks crawlers."** The first version
  of that note asserted a cause it had not checked, and it was wrong for every
  real case. `describeRefusal()` → `identifyWall()` now inspects the `Server`
  header and the first 600 bytes of the body and says which of three things
  actually happened, measured on 2026-09-27:
  - **Cloudflare challenge** (7 sources: Camp John Hay, Calle Crisologo, Vigan
    Heritage Site, Aguinaldo Shrine, Daranak Falls, Sierra Madre Mountain View,
    Dicasalarin Cove) — the body is the "Just a moment..." interstitial. The
    site is **not** refusing crawlers by policy; it wants a browser to solve a
    JavaScript challenge. No header change reads it, and the rejected
    headless-renderer decision is what makes it unfixable.
  - **Azure WAF** (Quezon Protected Landscape) — an application firewall. These
    rules commonly reject a data-centre IP, so the page may still be readable
    from a home connection. Not a robots decision.
  - **Plain access denied** (Pintô Art Museum) — nginx with a bare 403 and no
    explanation. Honest `Accept` and `Accept-Language` headers are already sent
    and were measured to change nothing, so the note says "find a different
    source" instead of blaming our own User-Agent.
  Verified by fetching all eight with and without the polite headers: identical
  status every time. **Never claim a refusal is about our User-Agent unless a
  header experiment proved it.**

The parser has three guards that stop it proposing curated data away:

- **No-op proposals.** `old_value` came back as `09:00:00` (MySQL `TIME`) and
  `proposed_value` as `09:00`, so the raw string compare never matched and
  **every** hours proposal against a `time` column was a guaranteed no-op.
  `sameValue()` normalises both through `Destination::formatTime()`.
- **Never narrow an `always_open` or `per_day` row.** A curated `00:00–23:59`
  means ungated; a text snippet saying "tourist office 8am to 10pm" is about
  something else. A `per_day` row (Fort Santiago) would be flattened to one
  pair, which is the collapse the per-day schema exists to prevent. Both kinds
  are skipped for `opening_time` / `closing_time`. `operating_status` is still
  proposed.
- **A window under 2 hours is not opening hours.** Mayon SkyDrive's page
  yields "departs 7:00 AM to 8:00 AM", which the parser accepted as closing time
  08:00. `MIN_WINDOW_MINUTES` rejects it.

State after the fix, 65 sources: **38 success, 27 failed** — 11 bot walls,
8 dead, 8 unreachable, 6 of the successes having followed a redirect. 6
pending proposals remain, all of them honest disagreements for a human.

### Source reachability (`fetchability`)

`sources:check` does **one** polite request per source and records *why* a page
can or cannot be read: `fetchable`, `js_rendered`, `bot_wall`,
`robots_blocked`, `dead`, `unreachable`. It exists because most sources can
never yield data, and the admin dashboard was showing them all as equally
crawlable. Filter by it on `/admin/sources`; it is a filter, **not** a skip —
never make `dispatch()` refuse a source because of `fetchability`, or a
temporarily-down host becomes permanently uncrawlable.

- The check is deliberately **one** request, not a crawl, and it does not
  write snapshots. It never dispatches a job.
- `RobotsPolicyService::robotsVerdictFor()` is tri-state (`allow` /
  `disallow` / `unknown`). `unknown` means robots.txt itself could not be
  fetched, and the checker then tries the page anyway, recording the real
  reason. Do not collapse `unknown` into `disallow`: a DNS-dead host reported
  as "robots.txt disallows this path" is simply a lie, and that mistake
  mislabelled 13 of the 83 seeded sources before it was fixed.
- Under 700 characters of visible text is **never** `fetchable`. A framework
  marker on top of that means `js_rendered`; without one it means `thin_page`
  (a "Coming soon" stub that answers 200 and yields nothing). Sparseness is
  the primary signal and the framework marker only picks the label — requiring
  *both* to agree mislabelled a 29-character page as readable, which is exactly
  the lie an admin cannot detect from the dashboard.
  A `<noscript>Please enable JavaScript</noscript>` on an otherwise text-rich
  page is not a JS shell, so never key on that phrase alone.

### Opening hours (curated, not crawled)

Hours are **hand-curated in `database/data/luzon-locations-clean.csv`**, never
extracted. CSV columns: `opening_time`, `closing_time`, `closed_days`
(comma-separated slugs), `daily_hours` (JSON, per-day windows), `hours_kind`,
`operating_status`, `hours_source`, `hours_verified` (seeds
`last_verified_at`), `hours_source_url`, `hours_source_label`, `hours_note`.

- **Every row with hours must carry a source.** `hours_source_url` renders
  under the hours on `/destinations/{slug}` with `rel="noopener noreferrer
  nofollow"` and a "checked on" date, so a traveler can verify. An
  unverifiable row stays blank; "Hours not listed" beats a wrong number.
- The seeder rewrites all 11 columns on every run, so **a blank cell clears the
  value**. Deliberate: it stops a withdrawn number surviving as a stale "Open
  now". Never fill a cell you have not verified against a source.
- `hoursFingerprint()` (compared by `hoursChanged()`) covers the base pair,
  `closed_days`, every per-day window, kind, status, source URL and note — so
  editing any of them stamps `last_verified_at`, while renaming or retagging
  does not.
- **Precedence is per-day, then `closed_days`, then the base pair.**
  `hoursForDay()` returns the per-day window if one exists, else `null` if the
  day is in `closed_days`, else the base pair. A day absent from `daily_hours`
  deliberately falls back to the general hours.
- `openState` has four states: `open`, `closed`, `closed_today` (a day the
  model knows is shut) and `unknown` (no hours at all). It never reports
  `closed_today` when the destination has no hours data — that would be a
  guess. Overnight windows (`open > close`) are handled: the place is open
  after midnight *or* before the close time.
- `Taal Basilica` is stored per-day even though every day is identical. That
  is deliberate: it is the shape to use for a weekday/weekend split, and
  `perDayHoursLabel()` renders it as "Mon–Fri 05:00–18:00 · Sat–Sun
  05:00–18:00". Collapse a day back to the base pair only when it genuinely
  matches it.
- `normalisedDailyHours()` discards any day whose window is unparseable or
  whose open equals close, and `closedDayList()` intersects with `daySlugs()`,
  so junk in a column degrades instead of breaking the page.
- `perDayHoursLabel()` collapses to "Mon–Fri 08:00–22:00 · Sat–Sun
  06:00–22:00" when the week splits cleanly, else lists each day.

### `hours_kind` — what the window actually means

A bare `08:00–17:00` is ambiguous: it might be when the gate is open, when
registration closes, or when a hazard level permits entry. `hours_kind` records
which, and `hoursKindLabel()` renders it under the hours. Consts live in
`App\Models\Destination` and the list is `HOURS_KINDS`.

| Value | Advisory | Meaning |
|---|---|---|
| *(null)* | no | Normal opening hours — 34 of 65 rows |
| `always_open` | no | `00:00–23:59` means ungated, not a 24-hour business |
| `per_day` | no | The week differs; a per-day table is rendered |
| `registration_window` | **yes** | A sign-up cut-off, not opening hours |
| `reservation_required` | **yes** | Walk-ins are refused |
| `alert_dependent` | **yes** | Access depends on the current hazard alert level |

- `hoursKindIsAdvisory()` drives the amber styling in
  `destinations/show.blade.php`; `hoursKindIsInformational()` is the neutral
  counterpart. A registration window must not read as "you can walk in at 5am".
- The seeder's `normalizeHoursKind()` takes an explicit `hours-kind` cell
  verbatim, then falls back to `per_day` when `daily_hours` is set and
  `always_open` for a `00:00`/`23:59` pair. An unrecognised value is dropped
  to `null`, never stored — a bad cell must not invent a kind.
- Do **not** add a test that every destination with hours has a kind. Plain
  `open_hours` is the default and correctly has none; the column exists to
  disambiguate the exceptions. The real invariants are that no stored value is
  outside `HOURS_KINDS`, and that the known fringe cases are labelled —
  `test_the_fringe_cases_are_labelled_rather_than_left_implicit` asserts both.
- **Still not fully expressible:** multi-window days (San Agustin Church of
  Paoay 08:00–11:55 / 13:30–17:00, Piat Basilica's seven Mass windows) and
  seasonal schedules. Per-day hours fix weekday/weekend splits only; genuinely
  seasonal or multi-window schedules need OSM `opening_hours` syntax.

### Coverage

Verified 2026-09-27, **all 65 rows**, each with a cited source. There are no
blank rows left: the 20 that could not be sourced were **removed** from the CSV
and the database rather than shipped with empty hours. Notable rows and the
caveats that travel with them:

- `00:00–23:59` (ungated, reachable at any hour) — Bangui Wind Farm, Bantay
  Abot Cave, Caliraya Lake, Anawangin Cove, San Juan La Union, Nagsasa Cove,
  Calle Crisologo, Vigan Heritage Site, Burnham Park, Wright Park, Camp John
  Hay, Baguio's Baler Hanging Bridge, Sabang Beach, Banaue Rice Terraces,
  Kiltepan, Sagada, Ipo Dam View, Mayon Volcano Natural Park, Quezon
  Protected Landscape, Clark Freeport. **The reason differs every time** and
  that is the point — it goes in `hours_note` (private resort vs public street
  vs no-fee road viewpoint vs a mountain with a 300-climber daily cap).
- Split per-day — **Fort Santiago** (Mon–Fri 08–22, Sat–Sun 06–22) and
  **Taal Basilica** (05–18 daily, stored per-day as the shape to copy).
- Closed Mondays — Pinto Art Museum, Masungi, Aguinaldo Shrine, La Mesa
  Eco Park, Hinulugang Taktak.
- `registration_window` — **Mt. Pinatubo** 05:00–07:00 (registration cut-off
  at Sta. Juliana; you cannot hike unaccompanied), **Mt. Daraitan** 03:00–18:00,
  **Mt. Ulap** 04:00–17:00 (Ampucao registration, 04:00 weekends / 05:00
  weekdays, guide mandatory, eco-trail reopened 16 Sep 2026 under EO 2026-71),
  **Palaui Island** and **Cape Engaño Lighthouse** (permit and boat/guides,
  not a gate).
- `alert_dependent` — **Taal Volcano View** is the free Tagaytay ridge
  (`00:00–23:59`, ungated), *not* the crater: Volcano Island access follows the
  PHIVOLCS alert level and is typically closed at level 2 or above.
  **Mayon Volcano Natural Park** for the same reason.
- `reservation_required` — **La Mesa Eco Park**, no walk-ins.
- Contested or narrowing — Aguinaldo Shrine (NHCP 08–16 vs map listings
  09–16), Zoobic Safari (16:00 vs 18:00, closed on most holidays), Dicasalarin
  Cove (06–18 / 07–16 / 08–17 across sources, stored at the safe middle),
  **Callao Cave** (closed by Cagayan province in Sept 2025 for a safety
  review), **Tagaytay Picnic Grove** (06–22 outer window).
- **Sagada** is a municipality, not a site: its note says the tourism office
  is 08–17 and every cave route needs a registered guide.

### The 18 removed rows

Removed from the CSV and the database on 2026-09-27 because no source
published hours a traveler could verify. Each lost its `destination_sources`
row too, so the seeder count is now 65 destinations / 65 sources.

- Private resorts that publish only an FAQ for day tours — Anilao, Anilao
  Labac, Calatagan Beach, Laiya Beach, Lobo Beach, Cavinti Underground River
  and Caves.
- Small barangay islands with no published hours — Jomalig Island, Alibijaban
  Island, Caramoan Islands, Calaguas Islands, Casapsapan Beach, Dinadiawan
  Beach, Dinapigue Coastal Area, Ditumabo Mother Falls, Dingalan Mountain
  View.
- **Enchanted Kingdom** — its 2026 schedule changes monthly (closed Mon–Thu in
  August, Mon–Wed in September, Mon–Tue from late September, and it opens 13:00
  in October), so any value written is wrong within days. It is the clearest
  case for OSM `opening_hours` syntax and for a real booking feed.
- Two entries where a single pair is simply wrong — **Intramuros** (a district
  whose Visitors Center is 08–17 but whose sites each keep their own hours)
  and **Pamulaklakin Forest Trail** (a Subic trail with no published window).

If any of these comes back, re-add the row to the CSV *first* — the seeder's
`firstOrNew(['slug' => ...])` will create it, but only with the columns the CSV
carries.

### The 100-row expansion (2026-10-14)

A panel review said the catalogue was too small, so 35 famous spots were added:
65 → 100 destinations and 100 sources. Chosen for **source availability** —
famous places have official pages with published hours, which is the only thing
that makes a new row verifiable rather than fabricated.

- **Baguio/Benguet +5** — Mines View Park, the Mansion (official `.gov.ph`
  hours, Tue–Sun 09–18), Baguio Cathedral, Strawberry Farm, Diplomat Hotel.
  Benguet is now 13 rows, the densest province.
- **Cordillera +3** — Buscalan Village (registration window), Bomod-ok Falls
  (official Sagada Tourism trek window), Kaparkan Falls (reservation-required,
  seasonal Jul–Oct, coordinates from a Plus Code — verify on the ground).
- **Metro Manila +5** — Manila Ocean Park, San Agustin Church and Museum,
  Manila Cathedral, Binondo Church, National Museum of Natural History.
- **Central Luzon +6** — Mt. Samat, Monasterio de Tarlac (closed Thu), Manaoag
  Church (Mass times only, **no closing time published — the cells stay blank**),
  Barasoain Church, Ma-Cho Temple, Mt. Arayat (no official hours found).
- **Cavite/Tagaytay +5** — Sky Ranch (hours conflict across sources — blank),
  People's Park in the Sky, Caleruega, Corregidor (tour-dependent access, no
  regular public ferry — blank), Puzzle Mansion.
- **Batangas/Laguna/Quezon +4** — Fantasy World (**seeded as
  `temporarily_closed`**, reported shut 2021–2026), Pagsanjan Falls, Villa
  Escudero (no gate hours published — blank), Kamay ni Hesus.
- **Ilocos +3** — Malacañang of the North (official Ilocos Norte fee page),
  Baluarte (no LGU hours — blank), Bantay Bell Tower (ungated — blank).
- **Batanes +3, a new province** — Basco Lighthouse, Marlboro Hills, Valugan
  Boulder Beach. All ungated with no LGU hours, so all three stay blank with a
  note; every Batanes visitor pays the ₱405 provincial registration regardless.
- **Zambales +1** — Inflatable Island (official 07:30–18:00).

**THE STANDARD HELD:** 19 of the 35 carry verified hours; 16 stay blank with a
source and a note saying why, because "Hours not listed" beats a wrong number.
Every row has a real, non-Wikipedia `hours_source_url` and a
`destination_sources` entry, so the seeder reports 100/100 with zero skipped.
`test_the_csv_seeder_keeps_every_place_name_intact` now asserts 100.

**What is still unmeasured:** the crawl-yield figures elsewhere in this file
(38 success / 27 failed, 8 bot walls, etc.) were measured on the original 65.
The 35 new sources have never been crawled or `sources:check`ed — run both
before trusting any `fetchability` verdict on them. Coordinates for Kaparkan,
Inflatable Island, Diplomat Hotel and Strawberry Farm are approximate and need a
ground check.

### Regenerating the CSV from the database

`C:\Users\cholo\.opencode\plan\rebuild-csv.php` (outside the repo) rewrites all
32 columns from the current `destinations` rows plus a hardcoded `$hours` map
keyed by destination name. Run it after editing that map, then
`php artisan db:seed --class=LuzonLocationsCsvSeeder --force`. It is
deliberately **not** in the repo: it is a one-off authoring tool, and the CSV
plus the seeder are the durable artefacts.

The script holds three hardcoded maps at the top, and the ordering between
them matters:

- `$hours` — per-name window, closed days, status, source, label, note, and
  optionally `daily` and `kind`. This is the research record.
- `$kinds` — a *name → kind* map for the rows whose meaning is not derivable.
  It is applied to `$hours` **before** the row loop. Applying it after the loop
  silently does nothing, because the loop already read `$curated['kind']` —
  that bug produced a CSV with only 1 `registration_window` instead of 5.
- `$drop` — names omitted from the rewrite. The script skips them and reports
  the count, so removing a destination means listing it here *and* deleting the
  DB row. A name in `$drop` that is absent from the DB is reported as
  `(absent)` rather than silently ignored.

After the loop a final pass fills in `per_day` for any row with `daily_hours`
and `always_open` for any `00:00`/`23:59` pair, so the CSV is self-describing
even if `$hours` omits an explicit kind.

### Rewriting the CSV safely

The CSV is the source of truth for the catalogue and **is tracked in git**
(`git ls-files database/data/` lists it), so a bad rewrite *can* be undone with
`git checkout -- database/data/luzon-locations-clean.csv`. Do that before
re-seeding, because the seeder rewrites the database immediately and a
`git checkout` afterwards will not restore it. Two real mistakes made here, both
from a script that trusted positional column indexes:

1. Writing 4 new columns with `array_merge($header, [...])` while still
   reading rows by index produced a **31-column file whose `name` column held
   source labels and notes** ("NHCP — Museo ni Emilio Aguinaldo",
   "Last entry 8:00 PM…"). Re-seeding created 7 junk destinations.
2. A later repair used a regex filter to drop the bad rows and **silently lost
   8 legitimate ones**, including every accented name (`Pintô`,
   `Cape Engaño`) because the character class did not cover them.

Rules: resolve column positions by **name lookup**, never a hardcoded index;
assert the row width equals the header width before writing; and after any
rewrite, check `Destination::count()` still equals the row count and that no
`name` looks like a note. `test_the_csv_seeder_keeps_every_place_name_intact`
now guards the last one, and the seeder's own `firstOrNew(['slug' => ...])`
means a junk slug creates a *new* row rather than corrupting a good one — which
is how it stayed recoverable.

`updatedOn` on the public page prefers `last_verified_at` over
`price_verified_at`.

### Measured facts about free data sources (do not re-litigate)

Checked against live services, not assumed:

- **OpenStreetMap/Overpass is not a substitute.** In an Intramuros bounding
  box, 60 `tourism=*` elements yielded **1** with `opening_hours` and **1**
  with `fee`. "Fort Santiago" is not in OSM at all. Most entries are
  `tourism=artwork` (statues and plaques), not catalog destinations.
  `overpass-api.de` also 406s this machine; `overpass.kumi.systems` answers
  only to a browser User-Agent.
- **`data.gov.ph`'s CKAN API is dead.** `data.gov.ph/api/3/action/package_search`
  now returns the Angular SPA shell with HTTP 200 and `text/html`. A 200 here
  does not mean you got JSON — check `Content-Type` before trusting it.
- **JSON-LD is worthless for this catalog**: 0 `application/ld+json` blocks
  across every reachable source. Only text-based hour extraction has ever
  produced data (BenCab).
- **DENR's PAIS is unreachable over TLS**: `pais.bmb.gov.ph` fails with
  "unable to get local issuer certificate" — a broken server chain. Do not
  work around it with `verify => false`.
- **Most seeded LGU domains simply do not resolve** (~21 of the 83 seeded). No crawling
  work fixes a domain that does not exist; the fix is a better URL or an
  honest "no official site" state. Verified dead at time of writing:
  `banaue` (no `www` either), `santana`, `piat`, `calatagan`, `cavinti`,
  `lumban`, `luisiana`, `jomalig`, `dinapigue`, `penablanca`, `generaltinio`,
  `kalinga`. Re-pointed to working hosts: Banaue → `ifugao.gov.ph/banaue/`,
  Kalinga → `kalingaprovince.gov.ph/kalinga-tourism/`,
  Baguio Botanical → `visita.baguio.gov.ph/park-tickets/29` (the old
  `/explore/18` is a 404).
- **JS-rendered pages are unreachable by plain HTTP, and that is not fixable
  by a better parser.** `visita.baguio.gov.ph/park-tickets/29` returns 200
  with 12,207 characters of nav/footer boilerplate, zero peso amounts, and
  not even the string "Botanical" — the fee arrives from a client-side fetch.
  The Next.js RSC payload (`RSC: 1` header) does not contain it either. A
  headless renderer was evaluated and **rejected**: Chrome is not installed
  here, it would add a Node/Chromium dependency to a Laravel app, and it
  reaches only ~2 of the seeded sources. Reopen only if the user wants it and
  Chrome is available.
- **Bot walls are mostly not User-Agent sniffing.** Honest `Accept`/
  `Accept-Language` headers change nothing. `car.denr.gov.ph` and
  `calabarzon.denr.gov.ph` do yield 200 to a spoofed Chrome UA — deliberately
  **not** implemented, since impersonating a browser contradicts this
  project's stated crawler ethics. Four others (`npdc`, `kawit`, `lobo`,
  `mayonskydrive`) fail only intermittently and work with the honest UA, so
  never conclude "blocked" from a single failed run.
- Do **not** widen `isPlaceEntity()` to accept `Event`. Those blocks are
  sidebar widgets for things happening at the venue ("Pasig River Esplanade
  Bazaar", "Free Walking Tour"), and accepting them would let an event's price
  be proposed as the destination's entrance fee.
- Adding a new extracted field means editing **two** places:
  `DestinationSourceParser::extract()` and `ProposalController::APPROVED_FIELDS`
  (approval throws for any field not in that list). Approving price fields
  stamps `price_verified_at`; hours/status fields stamp `last_verified_at`.
- The admin destination form validates a much smaller field set than
  `Destination::$fillable`; the fields it omits are only reachable through
  approved crawl proposals.

### Seeding order matters

`DatabaseSeeder` runs `TagSeeder` → `LuzonLocationsCsvSeeder` →
`DestinationSourceSeeder`. The source seeder resolves every row by
`destination_slug` and merely *warns* when a destination is missing, so running
it before (or without) the CSV seeder silently registers almost nothing.
`DestinationSeeder` exists but is never called.

`database/data/luzon-locations-clean.csv` is committed and is the source of the
destination catalog. Re-seeding is idempotent (`firstOrNew` on `slug`) but it
rewrites every CSV-managed column for matching slugs — it preserves
`image_url` on existing rows and clobbers admin/crawler edits to the rest.

### Migrations

Never edit a migration that has already run; add a corrective one. The repo
depends on this: there are several `repair_*` migrations plus a second
`add_area_to_itineraries_table` that is an intentional no-op. Keep them all
committed — a fresh clone needs the whole chain to reproduce the schema.

Editing a create migration after it ran is the failure mode to fear, because
the dev database cannot detect it: it already has the corrected schema, so
`migrate` is happy while every fresh install breaks. This happened with
`create_destination_sources_table`, whose `$table->unique('source_url')` was
rewritten as a composite unique — which silently orphaned
`allow_shared_source_urls`, since that corrective migration drops
`destination_sources_source_url_unique`. It only surfaced once the test suite
could run (`no such index`), so run `php artisan test` after touching any
migration.

**DELETING A MIGRATION LEAVES A TRAP ON THE SERVER, AND IT BIT ON THIS REPO
ONCE.** `add_carousel_fields_to_destinations_table` was added for the first fan
of the destinations stage, ran on the production server, and was then deleted
when that work was reverted. When the stage was rebuilt the migration came back
under a **different filename** — so the server had both columns physically
present, a `migrations` row for a file no longer on disk, and Laravel reading the
new name as unrecorded. The deploy died on:

```
SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name 'sort_order'
```

**THE INVERSE OF THAT TRAP IS WORSE, AND IT WAS ALREADY IN THIS REPO WHEN THE
ITINERARY EXPANSION STARTED.** `itinerary_items.destination_id` is `NOT NULL` on
every database in existence, including a fresh clone, and it is supposed to be
nullable — because a meal stop, a hotel and a traveller's own booking all have no
destination. The reason is two migrations reading as though the second fixed it:

```php
// 2026_08_27_034206_create_itinerary_items_table
$table->foreignId('destination_id')->constrained()->cascadeOnDelete();   // NOT NULL

// 2026_09_05_065400_repair_itinerary_tables
if (! Schema::hasColumn('itinerary_items', 'destination_id')) {           // guard
    $table->foreignId('destination_id')->nullable()->constrained()...;   // never runs
}
```

The repair's `hasColumn()` guard sees the column the create migration already
made and skips it, **on every database, forever**. So the file reads as a fix and
has never done anything. Nothing noticed for a month because
`ItineraryEditor::writeDay()` already guarded its insert with `elseif
($destination)` — the code has always tolerated a null, and nothing ever sent
one. Adding `item_type` was the first request that could.

- **`hasColumn()` inside a repair migration is a guard, not a fix.** A corrective
  step guarded on *existence* cannot correct anything a previous migration
  already created. `2026_10_14_092000_allow_itinerary_items_without_a_destination`
  guards on **nullability**, read out of `Schema::getColumns()`.
  **Assert the property, not the column's presence.**
- **The same pattern likely hides other repairs in that file.** Every
  `if (! Schema::hasColumn(...))` branch inside
  `2026_09_05_065400_repair_itinerary_tables` is dead on any database that ran
  the create migrations first, which is all of them. Do not read them as
  descriptions of the current schema. `itineraries.budget_level` is still
  `NOT NULL` for the same reason, despite that repair's `->nullable()` — and a
  test helper that omits it fails on SQLite while working on MySQL.
- **SQLITE DROPS A COLUMN IN A FOREIGN KEY DIFFERENTLY, AND A MYSQL-ONLY GUARD
  LOOKS CORRECT UNTIL THE SUITE RUNS.** Laravel answers SQLite's missing
  `DROP CONSTRAINT` by rebuilding the table, and that rebuild fails when a column
  being dropped is still named in a foreign key definition:

  ```
  error in table itinerary_items after drop column:
  unknown column "place_id" in foreign key definition
  ```

  So `2026_10_14_091000`'s `down()` calls `dropForeign(['place_id'])` for
  **every** driver. A `mysql`-conditional guard would have failed on the test
  database and not on production, which is the worst direction for a guard to
  break in.

**The guards, not the filename, are the fix.** `up()` checks
`Schema::hasColumn()` per column, so a fresh database gets both and a server
that already has them moves on and records the migration. `down()` is guarded for
the same reason: an unguarded inverse of a guarded step fails on exactly the
environments where the guard mattered.
`the_stage_migration_is_safe_where_the_columns_already_exist` is that server as a
test, and it was **falsified by deleting the guards** — which is the only way to
know it tests the fix rather than the comment.

**Do not "fix" this by renaming the file back to the deleted migration's
timestamp.** That also unblocks this server and touches no data, but it silently
depends on which name a given server happened to record, so a server that did
*not* run the old one would skip a migration it never ran and end up without
`is_featured` at all. `hasColumn()` is correct in every case.

**The general lesson: a migration removed from disk is not undone.** Check
`select migration from migrations` on the server before assuming a timestamp is
free, and prefer a guarded `up()` over a timestamp swap whenever a column may
already exist somewhere.

## Frontend gotchas

- **A DUPLICATE METHOD IN AN ALPINE COMPONENT IS NOT AN ERROR — THE LAST
  ONE WINS.** `destinationStage()` returns an OBJECT LITERAL, and a
  duplicate key in an object literal is not a syntax error, not a warning
  and not a lint failure in most configurations: the **last** definition
  silently takes the property and the earlier one is discarded.

  It happened here, and it disabled a safety net while every test still
  passed. A text replacement anchored on `offsetOf()`'s **docblock**
  rather than on its whole body left the original method underneath the
  new one — so the `unreadable` fallback was present, commented at
  length, asserted by name, and never once executed.

  The lesson has two halves:

  - **Anchor a replacement on the whole method, never on its docblock.**
    A docblock is duplicated content by design, so matching it identifies
    a region rather than a unit.
  - **Assert the count, per method name.**
    `no_method_in_the_stage_is_defined_twice` does exactly that, and
    `the_fan_actually_moves` asserts the **LAST** statement of
    `offsetOf()`'s body rather than its first — which is the only thing
    that distinguishes the real method from a dead copy sitting under it.

  A duplicate here is the most dangerous possible edit outcome: the file
  reads correctly, the feature reads as implemented, the tests pass, and
  the browser runs the version nobody meant.
- **A NEGATIVE MARGIN THAT CANCELS A PADDING NEEDS ITS PARTNER AT THE
  SAME BREAKPOINT, AND THE PAIR SHOULD BE READ FROM THE LAYOUT, NOT
  HARD-CODED.** `.tm-page` cancels `main`'s own padding with negative
  margins — the only way a background can run under padding without a
  wrapper. The arithmetic has to be exact at **every** breakpoint, and
  it was not: `main` goes to `lg:px-12` while the page only reached back
  to `lg:-mx-8`, leaving a 1rem band of the page's own background down
  each side at the widest sizes. A white edge on a dark page, small, and
  only on a big screen, which is exactly why it gets accepted as a
  rendering quirk.
  `the_dark_surface_cancels_the_layout_padding_at_every_breakpoint`
  reads `main`'s own classes out of `layouts/app.blade.php` and checks
  each pair, so adding `xl:px-16` to the layout without a matching
  `xl:-mx-16` here fails that test instead of shipping.

- **A BLADE DIRECTIVE GLUED TO THE END OF A WORD IS NOT COMPILED, AND IT IS
  NOT AN ERROR.** Blade's directive pattern is `\B@(...)`: it requires a
  **non-word-boundary** before the `@`. In `sources checked@if (...)` the
  preceding character is `d` — a word character — so there *is* a boundary,
  `\B` fails, and `@if` was never recognised. The admin dashboard rendered

  ```
  20 of 65 sources checked@if ($summary['queued'] > 0), 0 waiting in the queue@endif.
  ```

  The `{{ }}` either side compiled normally, which is what made it read as
  *the counts being wrong* rather than *the directive being inert* — the `0` in
  the middle of the leaked source was a real, correct value. There is no
  `@verbatim` involved and no unclosed tag; `php artisan view:clear` does not
  help, because nothing is cached wrongly.

  **A Blade directive must start a token.** Give it a space, a newline, or
  build the sentence as a list in PHP. Note the JS on the same page builds this
  exact string correctly in `renderSummary()`, so the server and the client were
  computing the same thing two different ways and only one of them was right.
  **To check, compile the view and search the output for the literal `@` —
  a directive that survives the compiler never ran.**
- **Never nest a `<form>` inside another `<form>`.** The HTML parser discards the
  inner form's start tag, then the first inner `</form>` closes the *outer* form,
  so buttons silently submit the wrong endpoint (the symptom is a bogus
  "field is required" validation error) and every control after that point drops
  out of the form. For per-row actions inside a table, render the small forms
  after the wrapper and point the buttons at them with the HTML5 `form="id"`
  attribute — see `admin/sources/index` and `admin/proposals/index`.
- **A `POST` form pointing at a `PATCH`/`PUT`/`DELETE` route needs
  `@method(...)`.** Without it the browser sends a real `POST` and Laravel throws
  `MethodNotAllowedHttpException`. This happened on the proposals bulk form:
  the per-row forms beside it had `@method('PATCH')` and the wrapper did not.
  `test_every_form_posts_to_a_route_that_accepts_its_method` renders eight
  pages, resolves each `<form>`'s effective method (the `_method` hidden input
  overriding the `method` attribute) and runs it through
  `Route::getRoutes()->match()` — the same call that throws in production. It
  fails with a message naming the page, the method and the missing
  `@method(...)`. **Adding a route or a form means adding it to that test's
  page list**, or the guard silently checks less than it did. The list is keyed
  by viewer because each page needs its own user to return 200, and the test
  `continue`s on a non-200 — a page behind a missing user was silently skipped
  rather than checked. It also asserts which pages it actually rendered, for
  the same reason. Its own failure message fataled on
  `implode('/', $exception->getHeaders()['Allow'] ?? ['?'])`: Symfony returns
  `Allow` as a **string**, not a list.
- **Never put Tailwind's `hidden` class on an element JS shows with the `hidden`
  attribute.** The class is `display: none` and an author-level display rule
  beats the browser's own `[hidden] { display: none }`. `element.hidden = false`
  removes an attribute the element never had, so the class wins and the element
  stays invisible forever — with no error anywhere. The edit form shipped with
  `class="hidden"` and the script toggled the attribute, so clicking
  **Edit itinerary** hid the read view and the form never appeared: a blank
  page with nothing editable. It cost a click-through to find, because every
  HTTP-level test passes either way.
  `test_elements_the_script_toggles_carry_no_hidden_class` now pins it for
  `data-editor-read`, `data-editor-edit`, `data-editor-notice` and `data-travel`.
  Pick one mechanism per element and prefer the attribute.
- **The layout owns the flash banner.** `layouts/app.blade.php` already renders
  `session('status')` and `$errors` inside `<main>`, so no page may render them
  again — doing so stacked two copies of every message. Admin pages once had
  their own copies; a test counts occurrences per page to keep it that way.
- Transient crawl feedback uses `<x-flash-toast />` (session key
  `toast_message`, bottom-right, auto-dismiss) instead of the banner, so
  "Queued …" does not push the page around. A test pins the key so the two do
  not both fire.
- **THE DECK RESET USES THE TOAST TOO, AND IT TOOK TWO WRONG FIXES TO GET
  THERE.** "Your discovery deck was reset." went through `session('status')`,
  which the layout renders as a **light** banner inside `<main>` — and on
  `/discover` `main` is deliberately `w-full`, so the banner rendered
  **full-bleed**: flush to both viewport edges, hard under the nav bar, outside
  the field everything else on the page sits in, and a large light rectangle on
  a Deep Volcanic Teal page. It read as a slab that had escaped the design.

  **FIX ONE WAS WRONG AND IT LOOKED RIGHT.** Wrapping the banner in the page's
  own `max-w-6xl px-5 sm:px-8 lg:px-12` field made it *fit*, and the markup was
  valid, and every test passed — and the notice was still a big light slab
  pushing the deck down the screen. Matching the complaint's *geometry* without
  asking why a confirmation needed page-filling space at all.

  **THE REAL QUESTION WAS "IS THIS PAGE STATE OR CONFIRMATION?"** It is
  confirmation. A reset changes nothing about the deck it lands on, so a banner
  that pushes the page is describing a page that did not change. `reset()` now
  flashes `toast_message` and `discover/index.blade.php` includes
  `<x-flash-toast />` — bottom-right, self-dismissing, taking no layout space,
  and reusing the component the crawl pages already use.

  **WHEN A NOTICE LOOKS LIKE IT DOES NOT BELONG, CHECK WHAT IT IS BEFORE WHERE
  IT IS.** "It breaks out of the field" was a true description of a true bug and
  a misleading description of the fix.

  `the_page_renders_the_toast_once_and_only_when_there_is_a_message` counts
  `//*[@data-toast]` as an **element**: the component's inline script queries
  `[data-toast]` and carries a `data-toast-script` marker, so `substr_count`
  finds five on a page with exactly one toast.
- A new JS/CSS entry must be registered in **two** places: the
  `laravel({ input: [...] })` array in `vite.config.js` *and* the
  `@vite([...])` array in the layout that should load it.
- `layouts/app.blade.php` has a `@stack('scripts')` before `</body>`, so a
  single page can load its own entry. **`@push` must come *above*
  `<x-app-layout>` in the child view** — below it, the layout has already
  rendered the stack and the push silently does nothing.
  `itineraries/show.blade.php` is the only user of this.
- `layouts/app.blade.php` loads `app.js`, which imports Alpine, Leaflet,
  `swipe.js`, and `home.js` (Lenis + GSAP ScrollTrigger initialize
  unconditionally) and registers `/sw.js`. Smooth scrolling and GSAP therefore
  run on every app-layout page.
  `layouts/guest.blade.php` (login/register) loads only `app.css` +
  `page-transitions.js` — no Alpine, Leaflet, or service worker there.
  `welcome.blade.php` is standalone.
- Tailwind v4 via `@tailwindcss/vite`. Brand tokens (`boracay`,
  `philippine-gold`, `palawan-sand`, `volcanic-teal`, `benguet-charcoal`,
  `island-white`) and the `tm-*` component classes are CSS-first in
  `resources/css/app.css` (`@theme` + `@layer components`).
  `tailwind.config.js` is legacy and does not define the palette; file scanning
  is controlled by the `@source` directives in `app.css`.
- Blade/JS contracts that break silently if renamed: `#intro-overlay` and
  `#hero` (welcome.blade.php ↔ home.js and page-transitions.js), and
  `[data-swipe-deck|-card|-action|-count|-empty]` (discover/index.blade.php ↔
  swipe.js).
- **`resources/js/gallery.js` is GONE**, and so is the scroll-snap strip it
  drove. Its lesson is worth keeping, because the stage's script has the same
  shape of hazard: `paint()` used to disable each arrow by asking it whether it
  had `dataset.galleryPrev`, while `buildControls()` wrote `dataset[key]` where
  `key` was the *keyboard shortcut* — so the attribute written was
  `data-arrow-left`, the check was never true for either button, and **reaching
  the final photo disabled Previous as well: no way back.** One argument doing
  two unrelated jobs, with the two halves never wired to each other.
- **The destination page's hero block and its scroll-snap photo strip are both
  gone.** Both sets of photographs are panels in the stage now, and the hero
  photograph *is* the active panel. Do not re-add either. The stage's own section
  documents the fan; this is only the history of what was removed, because two of
  these attempts were tried and reverted on this page already:
  **Attempt 1 — `width: 100vw` + `margin-inline: calc(50% - 50vw)`.** Landed
  off-centre by roughly half a scrollbar: `100vw` measures the viewport
  *including* the vertical scrollbar, so the block is ~15px too wide; centring on
  `50vw` split that excess across **both** edges while the `overflow-x: clip` on
  `body` only took **one**. One edge visibly short, the hero's background showing
  where the image was cut. That is what "it doesn't look smooth" was — a
  misalignment, not a preference.
  **Attempt 2 — beside the location map.** Left half the page empty on a wide
  screen *and* shrank the photos to ~40% of the container. The dead space was the
  give-away: the aside beside it is shorter than the left column, so the whole
  right half of the viewport sat empty.
  **The photos are still knowingly soft, and that is unchanged.**
  `DestinationController::storeUploadedImage()` stores uploads **verbatim** with
  no resize and they are typically 1080–1170px phone shots. Panels are 320 units
  wide at the reference stage size, so the shortfall is now smaller than the
  ~1.3–1.4× upscale the full-width strip had — but nothing about it is fixed.
  `object-fit` is not the lever: `cover` ships and is correct, `fill` stretches the
  aspect ratio and `contain` letterboxes a portrait strip. The actual fix is
  **resizing the uploads**, which needs GD or Imagick, and **neither is
  installed** — verified on the CLI *and* under XAMPP.
- **The `-mx-*` on the destination page is a BACKGROUND BLEED for the ground,
  and there is exactly one of them.** `-mx-5 -my-10 sm:-mx-8` against the layout
  main's own `px-5 py-10 sm:px-8 lg:px-12` is exact cancellation: the element ends
  up exactly as wide as its container's content box with the background running
  under the padding. **Never put a `-mx-*` on the stage, a stage panel, or
  anything inside the page** — only on that one `.tm-page` wrapper. Also note
  that *any* `overflow-*` on an ancestor inside the stage silently defeats a
  bleed, and `.tm-stage` is `overflow: hidden` on purpose: that is what crops the
  outer pair of panels.
- **THE DARK PALETTE IS NOW ILLEGIBLE ON THE DESTINATION PAGE.** The page was
  restyled from Palawan Sand to Deep Volcanic Teal so the stage could be the
  whole page rather than a panel on it, which inverted the colour rule: gold and
  Boracay Turquoise are now the *accent* colours (they fail on sand, not on
  teal), and `text-benguet-charcoal`, `text-volcanic-teal`, `bg-palawan-sand` and
  `bg-island-white` are what must not appear. **The heading was `text-volcanic-teal`
  when the ground changed, which made the destination's name invisible at 1:1.**
  Deep Volcanic Teal as text survives in exactly one place, on a filled button.
  See the stage section; the guard is token-wise rather than substring-wise,
  because `hover:bg-island-white` on that button is legitimate.
- **`overflow-x: clip` on `body` is not available as a fallback.** It came and
  went with the `100vw` attempt. If it is ever wanted, `hidden` is the wrong value:
  **`hidden` creates a scroll container**, making `position: sticky` resolve against
  `body` instead of the viewport — silently breaking the itinerary editor's sticky
  save bar and the discover deck's sticky header. `clip` clips without a scrollport.
- **`.tm-no-scrollbar` is GONE, and `.tm-drag-rail` is all that remains of that
  pair.** It existed only for the deleted scroll-snap track and hid a scrollbar
  nothing scrolls any more. A rule with no markup behind it reads like proof a
  component still exists, which is the same failure as the `[data-page-curtain]`
  CSS noted below — and `InteractionMarkupTest::every_attribute_selector_in_the_stylesheet_is_produced_by_something`
  does **not** cover it, because that guard is about `data-*` attributes.
  `DestinationGalleryTest::the_scroll_snap_photo_strip_is_gone` asserts both the
  markup and the stylesheet and the deleted files.
- `page-transitions.js` never intercepts navigation. It only *observes* it: a
  same-origin link click or a same-origin form submit arms a 350 ms timer, and
  only if the browser has not already navigated does the full-screen
  `[data-navigation-loading]` overlay appear. There is deliberately no
  `preventDefault()` and no `location.assign()` anywhere in it, so every link
  works with JS disabled.
- **That overlay's only guaranteed exit is a timer, not a page load.**
  `LOADING_MAX_MS` (8 s) clears it unconditionally, and `beforeunload` clears
  it too, because a navigation that never completes — the browser's stop
  button, a dismissed `beforeunload` prompt, a swallowed link — produces no
  page load and therefore no other way to dismiss it. It stranded a
  full-screen spinner over a working page until this was added. Do not remove
  the cap when touching that file.
- The page-cover ("curtain") transition is **gone**: the element
  `[data-page-curtain]` and its `@keyframes page-curtain-*` were left behind in
  `app.css` after the JS that created it was deleted, so forty lines of shipped
  CSS matched nothing while reading like proof the transition existed. Do not
  re-add the CSS without the JS.
  `InteractionMarkupTest::every_attribute_selector_in_the_stylesheet_is_produced_by_something`
  now fails if app.css styles a `data-*` attribute nothing produces.
- The PWA is hand-written — `public/sw.js`, `public/manifest.webmanifest`,
  `public/offline.html`, no build plugin. `sw.js` hardcodes the authenticated
  paths it must never cache (`/dashboard`, `/preferences`, `/recommendations`,
  `/discover`, `/itineraries`, `/admin`): update that list when adding an
  authenticated route, and bump `CACHE_NAME` when changing caching behavior.
- Itinerary driving lines call the public OSRM demo API from the browser
  (`resources/js/routing.js`) and fall back to a dashed polyline on failure.

### The itinerary PDF

`GET /itineraries/{itinerary}/download` → `ItineraryController::download()` →
`App\Services\Pdf\ItineraryPdf` → `App\Services\Pdf\PdfWriter`.

**THE WRITER IS HAND-ROLLED, AND THAT IS THE POINT — two measured facts
decided it, neither of them a preference:**

- **GD and Imagick are both absent** (verified on the CLI *and* under XAMPP —
  the same finding as the photo-resize note in the frontend section). Every
  mainstream PHP PDF library resamples and recompresses images through one of
  them, so a dependency here would ship a blank rectangle where a photograph
  should be, silently.
- **The host disables `exec()` and `symlink()`**, so anything with a native or
  external-code path is a deploy-time gamble in an app that has already lost a
  deployment to a missing binary.

`PdfWriter` is rectangles, lines, bezier paths and text in the fourteen standard
fonts. Nothing is embedded, so nothing can be missing on the server.

**THE PESO SIGN CANNOT BE DRAWN, AND EVERY FIGURE SAYS "PHP" INSTEAD.** The
standard 14 are WinAnsiEncoded and that encoding has **no peso sign** — `0xA5` is
the yen. A U+20B1 written into the content stream renders as **¥450** on the
traveller's page, which is a wrong currency on a document whose whole job is
being trusted about money. `ItineraryPdf::money()` writes `PHP`, and
`PdfWriter::escape()` maps `₱`→`PHP` as the last line of defence.
`the_writer_refuses_a_peso_sign_even_from_a_caller_that_passes_one` exercises
that mapping **directly**, because the itinerary path never reaches it and a
test that only covered the happy path would let it rot unobserved.

**THE WHOLE STRING IS CONVERTED TO WINDOWS-1252 FIRST. NEVER `str_split()` THE
RAW UTF-8.** `escape()` originally iterated with `str_split()`, which is
**byte-based**, so an en dash (U+2013, bytes `E2 80 93`) became three
"characters" of 226, 128 and 147 — all below 256, so the multibyte branch never
fired and the raw bytes were emitted as glyphs. The page read:

```
08:00 â€“ 17:30   ·   Day window 08:00 â€“ 09:30 Â· 1 planned stop
```

**WinAnsiEncoding *is* Windows-1252 for every character this app emits** — en
dash `0x96`, middle dot `0xB7`, curly quotes `0x91`–`0x94`, ellipsis `0x85`, and
every Latin accent. One `mb_convert_encoding($text, 'Windows-1252', 'UTF-8')`
fixes the punctuation *and* makes `Pintô` and `Cape Engaño` print as themselves.
Unmappable characters become `?`, which is honest; dropping them silently leaves
holes in words.

**IT DID NOT LOOK LIKE AN ENCODING FAULT, AND THAT IS THE PART WORTH KEEPING.**
`widthOf()` measures real characters through `mb_str_split()`, so the **layout
was correct and the glyphs were not** — the file opened, every structural check
passed, and the bytes were a perfectly valid Latin-1 string, just the wrong one.
**A character-set bug and a font bug look identical from the rendered page.**

**ANY TEST THAT READS PDF TEXT MUST DECODE WINDOWS-1252 TOO.** Reading the
content stream as UTF-8 makes a correct en dash (`0x96`) and middle dot (`0xB7`)
look like invalid input, so the first version of the encoding test **failed
against a PDF that was already correct**. `ItineraryPdfTest::textOf()` converts
before comparing, exactly as a reader does.

**THE LOGO IS VECTOR, READ FROM THE BLADE COMPONENT.** `logoPathData()` pulls the
single `<path d>` out of `application-logo.blade.php` and emits it as PDF path
operators. One home for the mark, so a rebrand cannot leave the PDF behind.
The SVG's y axis points down and PDF's up, so the CTM carries the only flip:
`(s, 0, 0, -s, x, yBottom + 316s)`. The translation's y term is the part that
is easy to get wrong — without `+ 316s` the mark is mirrored *below* its box.

**`logoPathData()` REFUSES COMMANDS PDF DOES NOT SHARE WITH SVG.** SVG's `A`
(arc) and `Q`/`S` are not PDF's `A` and `c`/`s`; emitting them under PDF's
letters **draws a different shape rather than failing**, which is the worst
available outcome for a brand mark. The current path uses only `M L C H V Z`,
and anything else returns null so the logo is skipped, not mangled.

**A DESTINATION WITH NO PHOTOGRAPH GETS A TYPOGRAPHIC PLATE**, carrying
municipality and province — the same rule as the stage panel and `.tm-swipe-plate`.
**All 65 catalogue rows currently have a NULL `image_url`, so the plate is the
NORMAL case and a photograph is the exception.** `imageSlot()` deliberately does
not embed bitmaps yet: JPEG can go into a PDF verbatim via `DCTDecode` with no
image library at all, and that is the only photograph path available without GD.

**`always_open` DOES NOT PRINT AS `Open 00:00–23:59`.** `formatHours()` returns
exactly that for an ungated row, so the first version told a traveller the gate
was open at 3am about a mountainside viewpoint with no gate. `hoursLine()`
prints **"Open — no gate"**, and a per-day week prints `perDayHoursLabel()`.
`hours_kind` exists precisely to disambiguate this and the PDF reads it.

**THE BUG THE WRITER SHIPPED WITH, AND THE TEST THAT NOW OWNS IT:**
`addPage()` pushes the page you are *leaving*, so after drawing the final page
its content is still in the buffer and **not in the page list**. `output()`
serialised the list alone and silently dropped the last page — the colophon,
gone, in a PDF that still opened and still had a plausible page count. Appending
the buffer is not tidiness. `the_final_page_is_not_dropped_from_the_document`
falsifies it.

**`the_pdf_is_structurally_valid()` PARSES THE BYTES BACK** rather than trusting
the writer: every xref offset must land on its `N 0 obj` header and every
declared `/Length` must match the bytes present. Offsets are read from
`$lines[$id + 2]` — `$lines[0]` is `xref`, `$lines[1]` the `0 N` header and
`$lines[2]` object 0's free entry, so starting at 2 makes **every** offset read
zero and every document look broken.

**THE VALIDATOR FAILS LOUDER THAN THE CODE.** Building this took three false
alarms from the throwaway inspection script (an off-by-one line index, a
`PREG_OFFSET_CAPTURE` pair used as a string, and a `.`-vs-===` precedence bug
that reported `INVALID` on a valid file). Twice it nearly sent me to "fix" a
writer that was correct. **When a checker disagrees with the code, check the
checker first.**

`ItineraryPdfTest` (19) covers the HTTP contract, the filename header against a
hostile title, the vector logo against the component, the plate, the peso, the
cover labels, and the writer's own refusals.

**THE COVER LABELS WERE ARRAY OFFSETS IN THE FIRST VERSION.** The facts were
built as a list literal and read as a keyed map, and `array_filter()` preserves
keys — so a null date left the survivors at positions 0 and 2 and the cover
printed **`0`** and **`2`** where "LENGTH" and "EST. TOTAL" should be. Every
assertion about the trip passed, because the values were right and nobody looked
at the small gold type above them. Found by dumping the finished PDF's text, not
by reading the code. `the_cover_labels_its_facts` now owns it.

**`DateTimeImmutable::createFromInterface()` IS NOT A NO-OP.** A `date` cast
returns a **Carbon**, and Carbon's `modify()` mutates in place and returns
`$this` — so `$end = $start->modify('+1 day')` made both names the same object
and a two-day trip starting 2 November read **"3 – 3 Nov 2026"**. `asDate()`
always returns an immutable, whatever the cast handed over.

`tests/Feature/ItineraryPdfTest.php` and the two services. **Open the PDF and
look at it** — nothing in this repo renders it, so the composition, the spacing
and the cover's balance are unverified by anything but the bytes.

## Deployment (Hostinger Premium, shared hPanel, over SSH)

`DEPLOY.md` is the runbook. Read it before deploying. The guard and move
logic are sandbox-tested; the host itself has only been reached as far as a 403
caused by the app being uploaded *inside* `public_html`.

The plan is **Premium**: SSH confirmed available. Node.js is greyed out on
that tier and does not matter, because Node is only a build tool for Vite and
the runtime never executes it.

- **The host must run PHP 8.4, not 8.3.** `composer.lock` pins seventeen Symfony
  8.0.x packages requiring `>=8.4`, and `symfony/yaml` requires `>=8.4.1`. On
  8.3 `composer install` cannot satisfy the lock, so Composer re-resolves and
  rewrites it — which showed up as a permanently dirty `composer.lock` that
  blocked `deploy.sh`. `composer.json` now pins
  `"platform": {"php": "8.4.1"}` so resolution no longer varies by machine.
  Local and host are both 8.4.
- `deploy.sh` **discards a modified `composer.lock`** rather than blocking on it,
  then still blocks on any other tracked change. A server that diverges on the
  lock installs a different dependency set than the suite was tested against.
- `/public_html` and `/public_html.hostinger-backup` are gitignored because
  `setup-website.sh` creates them. They were untracked and unignored, so the
  dirty-tree guard rejected files the deployment itself had made.
  `/queue-worker.sh` used to be here too and no longer exists — see the queue
  worker section.
- **Two deployment layouts exist; both are supported.** Layout A keeps the app
  root at `~/domains/<domain>/` with `public_html` a symlink to `public/`, so
  `.env`/`vendor/`/`storage/` sit outside the web. Layout B puts the app root
  inside `public_html/` and routes via the root `.htaccess`, which is what the
  file manager forces. **The deny rules are duplicated in `public/.htaccess`
  and the root `.htaccess` on purpose** — which file the server reads depends on
  the layout, so one copy would leave the other layout unprotected. Do not
  consolidate them.
- **Never upload the project into `public_html` without the root `.htaccess`.**
  The naive version of Layout B puts `index.php` at `public_html/public/index.php`,
  so PHP never starts and the domain 403s. And `.env`, `storage/` and `vendor/`
  end up in the document root. `setup-website.sh` detects and repairs the broken
  arrangement by moving the *contents* up; `setup-public-html-layout.sh` does
  the reverse conversion. Do not re-add the
  `mv public_html public_html.hostinger-backup` the first one replaced, which
  would move `.env` and `storage/` wholesale.

- **Three deploy failures produce no error message at all.** All hit the first
  live deploy. (1) A cached config bakes in absolute paths, so after moving the
  app every cached path is stale — 500 with zero new log lines and `APP_DEBUG`
  appearing to do nothing, because the cache overrides `.env`. (2) A duplicate
  `APP_KEY=` line: `key:generate` rewrites the first match, dotenv honours the
  last, so `grep -c '^APP_KEY' .env` must print exactly `1`. (3) `storage:link`
  creates `public/storage` *before* it throws, leaving a real directory that a
  stale `storage_path` then filled — the log, database password and `APP_KEY`
  included, served at `/storage/logs/laravel.log`. `public/.htaccess` now denies
  those paths outright. Details in `DEPLOY.md`.
- **What this Hostinger account allows:** PHP 8.4 only (8.3 cannot install the
  lock); `open_basedir` unrestricted, which is what makes the above-`public_html`
  layout work; LiteSpeed *does* follow a symlinked `public_html`; `symlink()`,
  `exec()` and `proc_open()` are all disabled. Do not assume these on another
  plan — they were measured, not documented.
- `bootstrap/app.php` calls `trustProxies(at: '*')` because Hostinger terminates
  TLS in front of PHP. Removing it brings back `http://` URLs and a 419 on the
  first form post.
- **`public/build` is committed on purpose.** It used to be gitignored, which
  made `git pull` deploy PHP but no CSS or JS — the site renders unstyled on a
  host that cannot run Vite. **Run `npm run build` and commit the result
  whenever you touch `resources/css` or `resources/js`.** Do not re-add
  `/public/build` to `.gitignore`. The cost is a noisy diff.
  Stage it with **`git add -A public/build`**, not `git add public/build`: Vite
  names output by content hash, so a rebuild produces a *new* filename instead
  of overwriting, and a plain `git add` strands the previous one in the repo
  permanently. This actually happened here — the committed CSS was already
  stale when the first deploy prep ran.
- **`.env.production.example` is the template for the server's `.env`**, not
  `.env.example`. The latter is deliberately dev-tuned (sqlite,
  `APP_DEBUG=true`, mail to the log) and several of those settings are silently
  broken in production.
- **The app lives at `~/domains/<domain>/` with `public_html` symlinked to
  `public`.** Hostinger will not change a Web plan's document root, so the
  domain always serves `public_html`; the symlink satisfies that while
  `.env`, `app/`, `storage/` and `vendor/` stay outside the web.
  `deploy/setup-website.sh` does it once and refuses to run twice without
  `--force`, because it moves Hostinger's seeded `public_html` aside.
  `public/index.php` uses `__DIR__`, which PHP resolves past the symlink, so
  the autoloader is unaffected.
- **`deploy/deploy.sh` is the repeatable deploy**: `optimize:clear` →
  `git pull --ff-only` → `composer install --no-dev` → `chmod` storage →
  `ln -s` the public storage link → `migrate --force` → `optimize`. It refuses
  a dirty tree, because a deploy carrying uncommitted edits makes the server
  diverge and turns the next pull into a conflict.
- **Never use `php artisan storage:link` on the host.** Hostinger disables both
  `symlink()` and `exec()`, and `Filesystem::link()` falls back from one to the
  other, so artisan dies with `Call to undefined function
  Illuminate\Filesystem\exec()`. Use `ln -s ../storage/app/public
  public/storage` from the shell, which is what the scripts do. It is currently
  inert: crawl snapshots use the `local` disk at `storage/app/private`, and the
  only web reference is `asset('storage/' . $user->profile_photo_path)` on a
  column nothing ever writes. It becomes load-bearing if photo uploads land.
- **`optimize:clear` runs first for a reason.** `config:cache` is compiled from
  the `.env` as it was when written, so an edited `APP_URL` or database
  password has *no effect* until it is cleared. This bites hardest on the first
  deploy.
- `deploy/deploy.sh` **refuses to run when `.env` says `APP_ENV=local`.** The
  `--no-dev` composer step *removes* dev packages rather than skipping them, so
  running it on a developer machine deletes PHPUnit out from under the test
  suite. Verified by accident once already.
- **The itinerary expansion (Days 1-5) adds nothing the host cannot already
  run.** Verified, not assumed: no new PHP extension (no GD/Imagick, no new
  composer package), no `exec()`/`symlink()`/shell-out anywhere in the new
  code, no new migration since Day 1's three (all MySQL-checked), no JS/CSS
  change (so no `npm run build` needed), no new queue job or cron entry, and
  every new config key degrades to a safe default under `config:cache`. A
  standard `deploy.sh` run carries it.
- **Catalogue data does NOT arrive with `migrate`.** Both seed CSVs are tracked
  in git, but `deploy.sh` runs `migrate --force`, never `db:seed`. After the
  first deploy carrying the expansion, run once over SSH (both are idempotent
  on slug, so re-running is safe):
  `php artisan db:seed --class=LuzonLocationsCsvSeeder --force` (100
  destinations) and `php artisan db:seed --class=PlacesCsvSeeder --force`
  (36 places). Skipping this leaves the new tables empty with no error
  anywhere — the app renders "nothing planned" states, not failures.
- **The queue worker is `deploy/queue-worker.sh`, registered as an hPanel cron
  job every minute.** Shared hosting will not run `queue:work` as a daemon.
  Without a worker, `/admin/sources` queues crawls that nobody picks up and the
  page deliberately does not warn about it.
  **The script holds no job-handling logic.** It finds the app root, finds the
  PHP binary, records that cron ran, and calls `php artisan queue:drain`, which
  owns the behaviour and is the part with tests. Do not move drain logic into
  the shell: it was there before, could not be tested, and a placeholder
  `APP_ROOT` plus a silent `exit 0` survived two deployments because of it.
- **Test the worker by running the script, never with `php artisan
  queue:drain`.** They are not the same test. The script is what resolves the app
  root, picks the PHP binary and sees cron's minimal environment; none of that
  happens when artisan is invoked directly. Running `php` by hand and
  concluding the worker was broken was the mistake twice — `php` worked every
  time, the wrapper around it did not. Use the path `deploy.sh` prints; it is the
  application root by default, not `$HOME`.
- The script discovers the app root when `APP_ROOT` is unset: a directory under
  `$HOME/domains` holding **both** `artisan` and `.env`, which is true of the
  app root in Layout A and Layout B and of nothing else. There is **no default
  path** — the old literal placeholder `yourdomain.tld` is why a wrong worker
  exited 0 having done nothing. Zero or several matches is an error, logged and
  exited non-zero.
- **The installed worker is a copy, so `git pull` never updates it.**
  **`deploy.sh` installs it
  immediately after `git pull`** and **prints the exact cron command**, derived
  from the path it actually installed, so the file and the cron entry cannot
  drift apart unnoticed. It also reports whether the previous copy was stale —
  before overwriting it, because afterwards there is no way to tell. It went
  stale three times before this was automated, every time silently: the old
  script defaulted `APP_ROOT` to a placeholder and exited non-zero, so crawls
  simply sat in `queued`. **The diagnostic tell:** an error mentioning
  `yourdomain.tld` means a pre-`1bb99f2` copy is still live; the current script
  says *"no app root under `$HOME/domains`"*. Do not hand-edit the installed copy
  — fix the repo and re-run the deploy.
- **The worker is installed inside the document root**, at the application root,
  because hPanel's file manager returns 403 for anything outside `public_html`.
  That is safe because cron executes over the **filesystem**, which `.htaccess`
  does not govern, while both `.htaccess` copies refuse `*.sh` so it is not
  readable over HTTP. **Do not "fix" the deny rule by removing `\.sh`** — that is
  what makes the arrangement safe. `curl -sI .../queue-worker.sh` expecting
  403/404 is in `deploy.sh`'s closing checks and is load-bearing while it sits
  there. `bash deploy/deploy.sh --worker-dest=...` moves it out of the web root.
- `.gitignore` lists the worker at every position it can land, because
  `deploy.sh`'s dirty-tree guard keys on tracked-ness and a stray copy blocks
  every subsequent deploy **naming a file the operator never touched**. Which
  rule covers which position was measured with `git check-ignore -v`, and
  `public_html/queue-worker.sh` is covered by `/public_html` rather than by its
  own entry — that entry is deliberate redundancy, not the current protection.
  `DeployArtifactsAreIgnoredTest` asks git rather than pattern-matching, so it
  cannot pass while the real behaviour differs.
- **Two logs, deliberately, answering different questions:**
  `~/queue-worker.log` says whether cron fired and which PHP it resolved;
  `storage/logs/laravel.log` (prefix `queue:drain`) says whether the drain found
  work. The first cannot answer the second, and "cron is broken" is
  indistinguishable from "nothing was ever queued" without it.
- `DrainQueue` logs its own progress rather than printing, because **cron
  discards stdout on this host**. The line that matters is
  `found the queue empty`: it means the drain ran and the queue was genuinely
  empty, which is the one thing `status` stuck on `queued` does not tell you.
- **`queue:drain` USED TO STOP DEAD ON THE FIRST JOB THAT THREW, AND THE JOB WAS
  RELEASED BACK — SO ONE UNREACHABLE HOST OWNED THE WHOLE CRAWL QUEUE.** Reported
  as "the crawler just crawls Camp John Hay, even when it is not first in the
  queue, and only keeps crawling it".

  Three facts combine, and two of them were not the explanation I first reached:

  - `DatabaseQueue::deleteAndRelease()` **DELETES the row and INSERTS A NEW ONE
    written with `attempts => 0`.** Releasing therefore RESETS the attempt
    counter, so a `$tries` limit **can never be reached by releasing** — a job
    reads `attempts() == 1` again on every pass. `queue:work` does not have
    this problem because it counts attempts itself; `queue:drain` calls
    `$job->fire()` **directly**, bypassing the worker's attempt counting
    entirely, so `CrawlSourceJob::$tries = 1` was never enforced by anything.
    (I initially blamed `getNextAvailableJob()`'s `orderBy('id asc')` plus
    `release(0)` writing `available_at = now()`. That is a real hazard but it
    is **not** what happened, because release gives the row a NEW, higher id.
    Measure the table after a drain rather than reasoning about the ordering.)
  - The drain `break`ed on the throw, so **the jobs behind it were never
    reached in that run either**. Falsifying the fix showed 3 of 3 jobs still
    pending after one drain — the two healthy ones had never been attempted.
  - The released row was eligible again immediately, so the next cron tick
    re-popped it, it threw again, and the loop was invisible.

  **THE FIX IS TO NOT RETRY AT ALL: a job that throws is retired and the drain
  carries on.** `$job->fail()` runs the job's own `failed()` hook (which is
  what writes the source's status and error message) and deletes the queue row.
  A job-level retry adds nothing — `EthicalSourceFetcher` already makes its own
  transport attempts (3 from here, 2 from the admin button) and persists the
  failure before rethrowing, so re-running the whole job is the same request
  again. An admin re-crawls from the UI when they mean to.

- **A RETIRED JOB LEAVES NO `failed_jobs` ROW IN THIS APP.** `fail()` is still
  the right call and still deletes the `jobs` row, but
  `FailedJobProviderInterface` is **not bound in the container**, so
  `CallQueuedHandler::failed()`'s `failedJobProvider?->log(...)` silently does
  nothing. Measured with a probe that called `fail()` on a real popped job:
  `failed_jobs` stayed empty while `jobs` went to zero. **Never assert a
  `failed_jobs` row here** — assert that the job left the `jobs` table. The
  record of a failed crawl is `destination_source_crawls` plus the source's own
  status.

- **`retire()` IS NOT `fail()`** — `Illuminate\Console\Command` already has a
  public `fail()`, so a `private function fail()` is a fatal "access level must
  be public" at class-load time, not a subtle bug.
- `pop()` is called with **no argument**. The argument is a *queue* name, not a
  connection name, so `pop('database')` asked for a queue called "database" and
  always returned null — the first version drained nothing and still exited 0.
- `route:cache` works despite the closure route for `/` in `routes/web.php` —
  Laravel 11+ serialises them. Tested, not assumed.

- **`retry_after` must exceed the crawl timeout.** `config/queue.php` derives it
  from `QUEUE_WORKER_TIMEOUT` (+60s) and `AppServiceProvider` re-registers
  `queue:listen` with the same number; `CrawlSourceJob` declares
  `public int $timeout = 300`. It used to default to **90**, which is under the
  job's own 300s ceiling: a crawl taking 100s was released to a second worker
  while the first was still fetching, so one admin click produced two crawls and
  two sets of proposals, with no error anywhere. That needs two workers, which
  is exactly the production shape (cron `queue:work` alongside `queue:listen`).
  `tests/Unit/QueueRetryAfterTest.php` guards the invariant.
  `DevCommands::artisan()` only records a subprocess for `php artisan dev` — it
  does not reconfigure the `queue:listen` command, so reading the command's own
  `--timeout` default tells you 60 and means nothing.
- **`MAIL_MAILER=log` silently breaks two live flows.** Forgot-password calls
  `Password::sendResetLink()` (`routes/auth.php`) and the resend lives on
  `verification.notice` / `verification.send` — also `routes/auth.php`, not
  `web.php`. With the log mailer the traveller is told the link is on its way and
  it never arrives; the only trace is the link in `storage/logs/laravel.log`.
  Set `MAIL_MAILER=smtp` and real credentials before going live.
  Note `User` does **not** implement `MustVerifyEmail`, so nothing enforces a
  verified address even though the routes exist.
- Crawling from a host means crawling from a **datacentre IP**. The Azure WAF
  refusal noted above commonly rejects those while serving the same page to a
  home connection, so re-run `sources:check` on the host before trusting any
  `fetchability` verdict measured locally. Do not "fix" it by spoofing a browser
  User-Agent.
- Promote your own account to admin over SSH after the first deploy:
  `php artisan tinker` then
  `App\Models\User::first()->update(['role' => 'super_admin']);`
  (or `admin` — `isAdmin()` accepts both).
- The CLI PHP on hPanel can differ from the web PHP, and the CA-bundle gotcha
  above is per-process: a worker started before `php.ini` gained a bundle keeps
  an empty trust store. Verify the version and extensions hPanel's CLI actually
  uses before concluding a crawl fails everywhere.

## Git state — check `git status` before assuming HEAD matches disk

**Never push without being asked.** Commit locally and stop. Do not run
`git push`, and do not treat "deployable" or "ready" as authorisation. This was
corrected explicitly after two consecutive autonomous pushes to `origin/main`:
commit and push are separate decisions, and only the user makes the second one.
If a task seems to end in a deploy, finish the commit and say what the push
command would be.

The crawling subsystem is merged into `main`, and `main` carries the itinerary
editor and the Hostinger deployment tooling. `agents/phase-1-implementation` is
fully contained in `main`, so it is dead weight — do not branch from it.
`feature/source-crawling` points at the same commit as `main`.

Run `git status` before assuming HEAD matches disk: an earlier state of this
repo had the entire editor uncommitted on top of a dirty tree, which is easy to
mistake for "already committed".

Workflow (from README): branch off `main`, open a PR, get a review before
merging, and never force-push `main`.