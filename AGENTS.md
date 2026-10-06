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
- The suite is currently **327 passing**. It is also the only thing that
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

The loading overlay is the one that behaves differently by design: it appears
only when a navigation takes longer than 350 ms, so a click-through on a fast
connection proves it does **not** appear, not that it works. Throttle the network
before judging it.

### The destinations carousel

`App\Domain\Destinations\DestinationCarousel` is the single presenter behind the
fanned hero on **both** `/destinations` and `/destinations/{slug}`. There is no
carousel table and no second component; the fan on a destination page is the index
fan with the active index shifted. Two routes, one order, one markup.

- **`data-offset` is the whole mechanism, and it is signed and relative to the
  active panel.** Position, size, rotation, z-index and opacity are all derived
  from it in `app.css`. The script changes one integer (`index`); every panel
  recomputes its own offset (`offsetOf(i) => i - index`), Alpine rebinds the
  attribute, and the CSS transition interpolates.
- **This was inverted once and the carousel shipped frozen.** The first version
  wrote each panel's offset from PHP into a static `data-stage-offset` and never
  touched it again, so advancing changed which card was highlighted and never
  where the cards were. State updated; layout did not; no test failed. The Blade
  writes the offset as the *starting* state only (so the page is right without JS),
  and the client binding is what makes the fan move. `the_fan_actually_moves`
  asserts all four conditions — binding present, computed from the index, geometry
  keyed on `[data-offset]`, and a transition on it — because dropping any one
  reproduces the freeze.
- **All slides stay in the DOM.** A five-element window re-rendered per slide
  cannot animate at all: the panels would be new elements, no image would be in the
  compositor, and there'd be nothing to transition. Offsets beyond ±2 stay in the
  DOM and are pushed off-stage with `data-far` (`visibility: hidden`,
  `pointer-events: none`) so they can slide in and stay out of the a11y tree.
- **Order is `sort_order`, then `name`.** The secondary sort is not decoration:
  `sort_order` defaults to 0 for every existing row, so without `name` two
  requests could disagree on the order and the two fans would not match.
- **`is_active` is the publish switch; `is_featured` is only curation.** An
  archived destination 404s *and* leaves both fans. An un-featured one keeps its
  page — it is merely not in the fan. Do not merge the two into one flag.
- **The carousel cache is busted on `saved` and `deleted`** via
  `Destination::booted()`. Without it an archived destination stays clickable in
  both fans for up to ten minutes and leads to a 404. There are no Observers in this
  project, so this lives on the model rather than in a new directory plus a
  registration.
- **`is_featured` and `sort_order` are not in the admin form yet.** They are
  `$fillable` and cast, so tinker works, but there is no UI. Both default to
  including everything, so nothing is hidden until an owner sets them.
- **`hero-carousel.js` is an Alpine component registered in `app.js`, not a
  separate Vite entry.** Registering it *after* `Alpine.start()` leaves
  `x-data="heroCarousel()"` unevaluated: the panels render and the script never
  attaches, which looks exactly like the frozen carousel and reports nothing.
  A standalone entry is also what produced a 0.00 kB bundle once already — an
  unused export gets tree-shaken.
- **Autoplay runs on `/destinations` and not on a destination page.** The index is
  a browse surface; a destination page is somewhere someone has arrived. Where it
  does run it ships the pause control, because a self-starting loop over five
  seconds without one fails WCAG 2.2.2. `every_field_the_template_reads_off_the
  _script_exists_on_it` exists because the caption once bound
  `activeSlide().regionLabel` while the script returned `{ region }`, and the chip
  went blank the moment Alpine took over.
- **The chevrons are links and the dots are buttons.** Chevrons navigate to a real
  destination, so they work without JS and are middle-clickable; they are omitted
  at each end rather than disabled, because there is no wrap-around and a
  permanently disabled control is a dead one. The dots move the fan in place.
- **The caption's heading tag is a parameter.** It is the page `h1` on a
  destination page and an `h2` on the index, which already has one in its header
  slot.

**Not covered by any test:** the choreography, the swipe threshold, the autoplay
timing and the reduced-motion path. There is no browser automation in this repo, so
those rest on a click-through. Re-click after touching `hero-carousel.js`, the
`[data-offset]` rules, or the panel markup.

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

## Frontend gotchas

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
- **`gallery.js` must never identify its own controls by sniffing their
  markup.** `paint()` used to disable each arrow by asking it whether it had
  `dataset.galleryPrev`, while `buildControls()` wrote `dataset[key]` with
  `key` being the *keyboard shortcut* — so the attribute actually written was
  `data-arrow-left`. The check was therefore never true for either button, both
  fell through to the "last slide" branch, and **reaching the final photo
  disabled Previous as well: no way back.** One argument doing two unrelated
  jobs, and the two halves were never wired to each other.
  `buildControls()` now takes `shortcut` and `hook` as separate arguments and
  puts `prev` / `next` on `state`, which `paint()` reads directly. It also no
  longer overwrites `state.controls` — that name meant the container element
  before that line and a button array after it.
  `the_previous_control_is_only_disabled_on_the_first_photo` pins the two
  halves agreeing by reading the source, because there is no JS test runner
  here and the symptom was invisible to every test that existed.
- **The carousel and the hero are both full width, and that is knowingly soft.**
  The page container runs to `max-w-[1600px]`, so both blocks render ~1504px
  wide, while `DestinationController::storeUploadedImage()` stores uploads
  **verbatim** with no resize and they are typically 1080–1170px phone shots.
  That is a 1.3–1.4× upscale, so the photos render soft on wide screens.
  **This is a decision, not an oversight.** `max-w-5xl` (1024px) on both blocks
  was implemented and tested — sharp *and* consistent — then reverted: the owner
  judged the narrower pair the wrong look for the page and asked twice for the
  carousel to match the hero. Do not "fix" the softness by re-adding the cap
  without asking; that is the option that was already declined.
  **`object-fit` is not the lever either.** `cover` ships and is correct — it
  crops without distorting. `fill` stretches the aspect ratio and `contain`
  letterboxes a ~3.9:1 strip. No `object-fit` value prevents an upscale, so
  "try a different object-fit" is the wrong fix.
  The actual fix is to **resize the uploads** so a 1504px box has enough
  pixels, which also makes `srcset` viable. That needs GD or Imagick, and
  **neither is installed** — verified on the CLI *and* under XAMPP — so it means
  enabling an extension on the host plus a backfill of existing photos.
  `the_carousel_matches_the_hero_and_neither_is_capped` pins the match, records
  the accepted cost in its own docblock, and still pins `object-cover` on both
  images. Its failure message on the cap assertion points at that note so the
  next person does not read it as a bug.
- **The destination page's hero and carousel are adjacent inset cards at the
  container width. Four layouts have been tried; do not redo the first three.**
  The carousel has been: above the hero; hero-first full-bleed via `100vw`;
  full-bleed via negative margins; down beside `02 / Location`; and is now back
  directly under the hero at full width. It is a partial,
  `destinations/partials/carousel` — one file keeps the no-JS fallback and the
  `gallery.js` hooks from drifting apart.
  **Attempt 1 — `width: 100vw` + `margin-inline: calc(50% - 50vw)`.** Landed
  off-centre by roughly half a scrollbar: `100vw` measures the viewport
  *including* the vertical scrollbar, so the block is ~15px too wide; centring on
  `50vw` split that excess across **both** edges while the `overflow-x: clip` on
  `body` only took **one**. One edge visibly short, the hero's background showing
  where the image was cut. That is what "it doesn't look smooth" was — a
  misalignment, not a preference.
  **Attempt 2 — negative margins** (`-mx-5 sm:-mx-8 lg:-mx-12`, the inverse of
  `<main>`'s `px-5 sm:px-8 lg:px-12`). Aligned, no arithmetic to get wrong, but
  too wide for the page.
  **Attempt 3 — beside the location map.** Left half the page empty on a wide
  screen *and* shrank the photos to ~40% of the container. The dead space was the
  give-away: the aside beside it is shorter than the left column, so the whole
  right half of the viewport sat empty.
  So the width is whatever the container gives it. **Never put a `100vw` or a
  `-mx-*` on either block**, and note that *any* `overflow-*` on an ancestor inside
  the container silently defeats the attempt.
  **The radii match on purpose:** both `rounded-[2rem]`, so the two adjacent media
  blocks read as one unit. They were `2rem` / `1.5rem` while the carousel sat beside
  the map card; that difference went with the move.
  **Softness: accepted, and it is the trade.** ~1504px against a typical
  1080–1170px upload is a ~1.3–1.4× upscale, so the photos are soft on a wide
  screen. Narrower versions were implemented and reverted more than once, each
  time sharp and each time judged too small. If the cap assertion fails, that is a
  design change to raise, not a bug. The root fix is resizing uploads, which needs
  GD or Imagick — absent from both the CLI and XAMPP.
  **The carousel must stay the hero's `nextElementSibling`.** Order alone is not
  enough: it was still "after the hero" while sitting below the map, which is what
  let that regression through. `the_carousel_sits_directly_under_the_hero`
  compares `nextElementSibling` *and* `compareDocumentPosition` — the first
  catches drift, the second catches the carousel moving above the `<h1>`, which
  would hand a screen reader and the tab order the photo strip before the
  destination's name.
- **`overflow-x: clip` on `body` is not available as a fallback.** It came and
  went with the `100vw` attempt. If it is ever wanted, `hidden` is the wrong value:
  **`hidden` creates a scroll container**, making `position: sticky` resolve against
  `body` instead of the viewport — silently breaking the itinerary editor's sticky
  save bar and the discover deck's sticky header. `clip` clips without a scrollport.
- **`.tm-no-scrollbar` hides the carousel's scrollbar without stopping it
  scrolling.** Separate from `.tm-drag-rail`, which already hid one, because that
  class also sets `cursor: grab` — wrong for the carousel, which is driven by
  arrows, dots, keyboard and swipe, so a grab cursor promises a drag nothing
  implements. It needs **both** spellings: `scrollbar-width: none` covers Firefox
  and now Chrome, and the `::-webkit-scrollbar` rule covers the rest; dropping
  either silently brings the bar back on some browsers. `overflow-x-auto` must
  stay on the track regardless — `goTo()` and `watchScroll()` both read
  `scrollLeft`.
  `the_track_hides_its_scrollbar_and_the_rule_still_exists` pins both halves: the
  class on the track, **and** that app.css still defines it. A class in markup
  with no rule behind it is the failure mode
  `InteractionMarkupTest::every_attribute_selector_in_the_stylesheet_is_produced_by_something`
  does not cover — that one guards CSS styling nothing, not markup styling
  nothing.
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
