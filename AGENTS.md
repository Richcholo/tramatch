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
- **THE FAN IS DRAGGABLE**, and four pieces of it are load-bearing, each failing
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
- **`DrainQueue` stops on the first job that throws outside its own handling.**
  Releasing puts the row back with `available_at` in the past, so the next
  `pop()` returns the same job and a permanently failing job never leaves the
  queue — the drain spun on one job until its wall-clock cap, burning the cron
  slot every minute and draining nothing. The suite measured it: 278s before
  the `break`, 0.5s after. This is the same shape as the `retry_after` bug
  below, reached from the other direction.
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
