# TraMatch

TraMatch is a web- and mobile-friendly travel itinerary recommender for Filipino local travelers. It uses a user’s travel profile and swipe decisions to identify destinations that fit their interests, budget, group size, and trip duration.

This repository contains the current TraMatch application through the core product, admin, PWA, and frontend polish phases. Deployment is intentionally being held until final QA and visual refinement are complete.

## Current product flow

```text
Register or log in
        ↓
Complete travel preferences
        ↓
Swipe destination cards
        ↓
Like or pass destinations
        ↓
View liked destination recommendations
```

## Implemented features

- Laravel Breeze authentication
- User profiles
- Destination catalog for Luzon
- Destination tags
- Budget filtering
- Weighted travel preferences
- Preference-based destination matching
- Explainable recommendation scores
- Interactive swipe discovery deck
- Like and Pass actions
- Keyboard controls for the swipe deck
- Saved swipe decisions
- Passed destinations excluded from the deck
- Liked destinations prioritized in recommendations
- Resettable discovery deck
- Responsive Tropical Festival visual design
- Itinerary generation
- Itinerary day and destination management
- Leaflet destination and itinerary maps
- OpenStreetMap map tiles
- Reviews and ratings
- Administrator dashboard
- Destination create, edit, archive, restore, and permanent removal
- Curated opening hours with a cited source
- Per-day opening hours and closed-day rules
- Administrator destination-source crawling with an approval queue
- Source reachability reporting
- Progressive Web App foundation
- Editorial landing page
- Smooth Lenis scrolling
- GSAP and ScrollTrigger homepage motion
- Login and registration redesign
- Internal page transitions
- MySQL or MariaDB database support
- Vite and Tailwind CSS development workflow

The application is currently in the polish and QA phase. Production deployment is being held until testing, accessibility, performance, and migration cleanup are complete.

## Technology stack

- PHP
- Laravel 13
- Laravel Breeze
- Blade
- Tailwind CSS 4
- Vite
- JavaScript
- MySQL or MariaDB
- Leaflet and OpenStreetMap
- Lenis
- GSAP
- GSAP ScrollTrigger
- PHP Artisan `dev` (single-process dev runner for Windows)
- Git and GitHub

## Requirements

Install the following before setting up the project:

- PHP 8.4 or a Laravel-supported PHP version
- Composer
- Node.js 22.12.0 or newer
- npm
- MySQL or MariaDB
- Git

On Windows, XAMPP can provide MySQL. Start MySQL in the XAMPP Control Panel before running migrations.

Two PHP extensions are needed. `pdo_mysql` is required for the application, and
`pdo_sqlite` is required for the test suite, which runs against an in-memory
SQLite database so it never touches your development data.

```powershell
php -m | Select-String "pdo_mysql|pdo_sqlite"
```

If a line is missing, enable it in `php.ini` and remove the leading semicolon:

```ini
extension=pdo_mysql
extension=pdo_sqlite
```

### HTTPS crawling on Windows

The source crawler only fetches over HTTPS. Windows PHP does not always ship a
CA bundle, and without one every crawl fails with
`cURL error 60: unable to get local issuer certificate`. Point `php.ini` at a
real bundle:

```ini
curl.cainfo = C:\php84\extras\ssl\cacert.pem
openssl.cafile = C:\php84\extras\ssl\cacert.pem
```

A bundle ships with Composer at `vendor/composer/ca-bundle/cacert.pem` and is a
valid fallback. Restart your web server after editing `php.ini` — a running
PHP process keeps the configuration it started with, so an old server keeps an
empty trust store and every crawl from the web fails while the same crawl from
the command line succeeds.

Never work around this by disabling certificate verification.

## Clone the project

```powershell
git clone https://github.com/Richcholo/tramatch.git
cd tramatch
```

Replace the repository URL with the actual GitHub repository URL.

## Install PHP dependencies

```powershell
composer install
```

## Install JavaScript dependencies

Use `npm ci` when `package-lock.json` is committed:

```powershell
npm ci --include=optional
```

If the project does not contain `package-lock.json`, use:

```powershell
npm install --include=optional
```

Node `22.12.0` or newer is required by the current Vite packages.

## Create the environment file

In PowerShell:

```powershell
Copy-Item .env.example .env
```

Generate the application key:

```powershell
php artisan key:generate
```

## Create the database

Create a MySQL database named `tramatch`.

Using the MySQL console:

```sql
CREATE DATABASE tramatch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Using XAMPP, open phpMyAdmin at:

```text
http://localhost/phpmyadmin
```

Select **New**, then create a database named:

```text
tramatch
```

## Configure `.env`

Update the database and timezone values in `.env`:

```dotenv
APP_NAME=TraMatch
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_TIMEZONE=Asia/Manila

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tramatch
DB_USERNAME=root
DB_PASSWORD=

CRAWLER_CONTACT=you@example.com
```

For a default XAMPP installation, the MySQL username is usually `root` and the password is usually blank. Change the values if your local MySQL installation uses a different username, password, or port.

`APP_TIMEZONE` must be `Asia/Manila`. Setting it in `.env` is not enough on
its own — `config/app.php` has to keep reading `env('APP_TIMEZONE')`, because
Laravel 11 and newer ship a hardcoded `'UTC'` there.

`CRAWLER_CONTACT` is the address the source crawler advertises in its
`User-Agent` and `robots.txt` group. Set it to a real mailbox that someone
reads. It is polite-crawler policy, not a cosmetic setting.

`.env.example` ships SQLite defaults, so `DB_CONNECTION=sqlite` in a fresh copy
is expected. Change it to `mysql` for the application. Never point the test
suite at MySQL — `php artisan test` forces SQLite in memory regardless of your
`.env`.

Never commit `.env` to GitHub.

## Create the database tables

Run all migrations and seed the development database:

```powershell
php artisan migrate --seed
```

To view migration status:

```powershell
php artisan migrate:status
```

The repository includes migrations for the following tables:

| Table | Purpose |
|---|---|
| `users` | Accounts, authentication, and roles |
| `travel_profiles` | Budget, group size, trip duration, and region |
| `tags` | Travel interests such as beach, nature, and history |
| `user_preferences` | Weighted user interests |
| `destinations` | Destination information, coordinates, costs, hours, and tags |
| `destination_tag` | Destination-to-tag relationships |
| `destination_swipes` | User Like and Pass decisions |
| `itineraries` | Saved itinerary records |
| `itinerary_days` | Day records for saved itineraries |
| `itinerary_items` | Destination items assigned to itinerary days |
| `reviews` | Destination ratings and written reviews |
| `destination_sources` | Official pages a destination's fees and hours are read from |
| `destination_source_snapshots` | Raw HTML captured by a crawl |
| `destination_source_crawls` | One row per crawl attempt, including failures |
| `destination_update_proposals` | Crawl findings awaiting administrator approval |

### The destination catalogue lives in a CSV

`database/data/luzon-locations-clean.csv` is the source of truth for the
destination catalog. It has 65 rows and 32 columns, including the curated
opening-hours columns (`opening_time`, `closing_time`, `closed_days`,
`daily_hours`, `hours_kind`, `operating_status`, `hours_source_url`,
`hours_source_label`, `hours_note`).

`DatabaseSeeder` runs `TagSeeder` → `LuzonLocationsCsvSeeder` →
`DestinationSourceSeeder`, in that order. `DestinationSourceSeeder` resolves
each source by `destination_slug` and only warns when a destination is
missing, so running it before the CSV seeder registers almost nothing.

The CSV seeder matches rows on `slug` with `firstOrNew`, so re-seeding is
idempotent. It rewrites every CSV-managed column on each run and preserves
`image_url` on existing rows — which also means it will overwrite admin edits
to the other columns. Re-run the seeders only when you mean to:

```powershell
php artisan db:seed
```

To seed only the catalogue:

```powershell
php artisan db:seed --class=LuzonLocationsCsvSeeder
```

A blank cell in the CSV clears that field on re-seed. That is deliberate for
hours: a withdrawn number must not survive as a stale "Open now". Never fill a
cell you have not verified against a source.

## Start the application

Use one PowerShell terminal:

```powershell
php artisan dev
```

That starts three processes in the current window:

| Process | Port | Notes |
|---|---|---|
| Laravel app | `8000` | The application URL |
| Vite | `5173` | Frontend assets only, not the app |
| Queue worker | — | Required for crawling, never omit it |

`php artisan dev` (or `composer run dev`) is the supported way to work on this
project. Three separate terminals work too, but you then have to remember to
start the queue worker:

```powershell
npm run dev
php artisan serve
php artisan queue:work
```

Check what is running:

```powershell
php artisan dev:list
```

Open the application at:

```text
http://localhost:8000
```

Use the Laravel URL as the main application URL. Vite usually runs on port `5173` and only serves frontend assets.

`php artisan dev` is a development tool. Production needs a real process
manager, not this command. The queue worker is registered as
`queue:listen --timeout=300`; keep that timeout, because a hung crawl would
otherwise block the worker indefinitely.

## Available routes

| URL | Purpose |
|---|---|
| `/` | Laravel welcome page |
| `/register` | New account registration |
| `/login` | User login |
| `/dashboard` | Dashboard or first-time preference redirect |
| `/preferences` | Travel profile and weighted interests |
| `/destinations` | Public destination catalog |
| `/destinations/{slug}` | Destination details |
| `/recommendations` | Liked destination recommendations |
| `/discover` | Swipe discovery deck |
| `/discover/swipes` | Saves Like or Pass actions |
| `/discover/reset` | Resets a user’s swipe deck |
| `/itineraries` | Saved itinerary list |
| `/itineraries/create` | Itinerary generation form |
| `/itineraries/{id}` | Itinerary details and map |
| `/destinations/{slug}/reviews` | Saves a destination review |
| `/admin` | Administrator dashboard |
| `/admin/destinations` | Administrator destination management |
| `/admin/sources` | Destination source list, reachability, and crawl queue |
| `/admin/sources/{id}` | Source detail and crawl log |
| `/admin/proposals` | Crawl findings awaiting approval |
| `/profile` | Breeze user profile page |

The preference, recommendation, discovery, itinerary, review, and admin routes require authentication. Every `/admin` route additionally requires `role = 'admin'`.

## Opening hours

Opening hours are **curated in the CSV, not scraped**. Every destination in the
catalogue has a general window, a source URL, and a verified-on date, and the
source link is rendered under the hours on `/destinations/{slug}` so a traveler
can check it.

`hours_kind` records what the stored window actually means, because a bare time
pair is ambiguous:

| `hours_kind` | Meaning |
|---|---|
| *(empty)* | Normal opening hours |
| `always_open` | Ungated — 00:00–23:59 means reachable at any hour, not a 24-hour business |
| `per_day` | The week differs; a per-day table is rendered |
| `registration_window` | A sign-up cut-off, not opening hours (Mt. Pinatubo, Mt. Daraitan, Mt. Ulap, Palaui Island, Cape Engaño Lighthouse) |
| `reservation_required` | Walk-ins are refused |
| `alert_dependent` | Access depends on the current hazard alert level (Taal Volcano View, Mayon Volcano Natural Park) |

Resolution order for a given day is per-day window, then `closed_days`, then
the general pair. `openState` reports one of `open`, `closed`, `closed_today`
(a day the model knows is shut) or `unknown` (no hours data at all) — it never
guesses `closed_today` for a destination with no hours.

Admins edit all of this from `/admin/destinations/{id}/edit`. Any change to the
window, closed days, status, source URL or note restamps `last_verified_at`;
renaming or retagging does not.

## Crawling destination sources

Administrators can point TraMatch at official destination pages and let it
propose fee and hours changes. Nothing reaches the `destinations` table without
approval.

```text
destination_sources
        ↓ crawl
destination_source_snapshots   (raw HTML on the local disk)
        ↓ parser
destination_update_proposals   (pending administrator approval)
        ↓ approve
destinations
```

Every crawl is dispatched as a queued job, because a crawl sleeps at least five
seconds for politeness and makes two HTTP requests. The admin button queues it;
nothing crawls inside a web request. From the command line it runs
synchronously:

```powershell
php artisan sources:crawl {id}
```

Measure whether each source page can be read at all:

```powershell
php artisan sources:check
```

`fetchability` is a *filter*, not a skip: a temporarily-down host must never
become permanently uncrawlable, so `dispatch()` refuses nothing.

Deliberate limits, all of them deliberate:

- The crawler may propose **hours and fees only**. `name`, `description`,
  `latitude` and `longitude` are owned by the CSV seeder and cannot be
  extracted.
- **Fees are never auto-parsed.** They are tiered and multi-product, so the raw
  fee block is shown for an admin to compare against the stored value.
- The crawler will not propose hours that contradict curated data: a place
  recorded as ungated (`00:00–23:59`) is never narrowed by a text snippet, a
  per-day schedule is never flattened to a single pair, and a window shorter than
  two hours is read as a departure slot rather than opening hours. A value that
  already matches creates no proposal.
- **The crawl target is the URL the hours were verified against**, not a
  marketing homepage. Each source is pointed at the cited hours page, which
  roughly doubles what the crawler can read: 55 of 65 sources now crawl
  successfully and 18 yield a value, up from 38 and 8. Thirteen of those 18
  match the curated hours exactly, which independently validates the
  hand-curated data. Wikipedia is never crawled — it is licensed content and
  yields nothing.
- **A refused request is reported accurately.** A 403 is not automatically "the
  site blocks crawlers": the crawler inspects the response and distinguishes a
  Cloudflare JavaScript challenge, an Azure WAF block, and a bare
  access-denied. The first two are not about our User-Agent and no header
  change fixes them, so the note says so instead of implying a fixable request
  problem.
- The admin page distinguishes a *homepage that publishes nothing* (set a details
  URL) from a *readable page with no data*, instead of showing one dead-end
  message for both.
- `robots.txt` is honoured (cached 24 hours, `Crawl-delay` respected, five
  second floor). A `robots.txt` that cannot be read is treated as *unknown*, not
  as a disallow, and the page is tried once so the real reason gets recorded.
  Redirects are followed (max 5) and re-checked against the target host's
  robots.txt, but a redirect into a disallowed path is refused.
- Loopback and private hosts, non-HTTP(S) schemes, non-HTML responses and
  bodies over 2 MB are refused. A host that does not resolve is recorded as dead
  and not retried, because it is not a transient failure.
- The `User-Agent` is honest. It does not impersonate a browser, even though a
  spoofed one would get a 200 from two of the bot-walled hosts.
- Most seeded LGU domains do not resolve, and JSON-LD is absent from every
  reachable source. Check a page before assuming a source will yield data.

Every crawl attempt is logged, including failures, so "refused" can be told
apart from "never ran". Measured across all 65 sources: 55 crawl successfully
and 10 fail, all of them refused by an edge security layer (7 Cloudflare
challenges, 1 Azure WAF, 2 bare access-denied). There are no dead source
domains left.

## First-time user flow

A user without a travel profile is redirected from `/dashboard` to `/preferences`.

After saving preferences, the user is sent to `/discover`.

The user can then:

- Swipe right or click **Like** to save a destination.
- Swipe left or click **Pass** to exclude a destination.
- Use the left and right keyboard arrows.
- Reset the deck to start again.
- Open `/recommendations` to view liked destinations.

Changing the user’s preferences clears their previous swipe decisions and creates a fresh discovery deck.

## Recommendation logic

The current recommendation service only returns destinations liked during discovery.

The score combines the user profile and swipe behavior:

```text
70% weighted profile match + 30% liked-by-swipe bonus
```

The system also filters destinations by the user’s selected budget. Passed destinations are excluded from recommendations.

## Creating an administrator account

Register an account first, then run:

```powershell
php artisan tinker
```

Inside Tinker:

```php
$user = App\Models\User::where('email', 'admin@example.com')->firstOrFail();
$user->role = 'admin';
$user->save();
exit
```

Replace the email address with the account you want to make an administrator. After assigning the role, the account can access `/admin` and manage destinations.

## Running the tests

The suite is PHPUnit (not Pest) and runs against an in-memory SQLite database
with array cache/session and a synchronous queue:

```powershell
php artisan test
php artisan test --filter=RecommendationServiceTest
php artisan test tests/Feature/Admin/SourceCrawlingTest.php
```

It never touches your development database — `phpunit.xml` overrides
`DB_CONNECTION=sqlite` regardless of what `.env` says. This is the only check
that can detect a broken migration chain, because a dev database has already run
every migration. Run it after touching any migration.

There is no CI workflow, no pre-commit hook, and no lint or typecheck gate.
`vendor/bin/pint` is installed but the tree is **not** Pint-clean. Do not mass-format it and do not make `pint --test` a pass/fail gate.

## Frontend commands

Start Vite with live updates:

```powershell
npm run dev
```

Create a production frontend build:

```powershell
npm run build
```

The compiled assets are written to:

```text
public/build
```

## Useful Laravel commands

Clear cached configuration, routes, and views:

```powershell
php artisan optimize:clear
```

List routes:

```powershell
php artisan route:list
```

Check migration status:

```powershell
php artisan migrate:status
```

Open Laravel Tinker:

```powershell
php artisan tinker
```

## Existing local databases

If a developer already has an older local database, do not edit an already-ran migration and expect Laravel to run it again. Use a new corrective migration for schema changes.

The project may contain corrective migrations for older incomplete local tables. Keep those migration files in GitHub because a fresh clone needs them to reproduce the current schema.

For a completely disposable local database, this command rebuilds all tables and runs the seeders:

```powershell
php artisan migrate:fresh --seed
```

This deletes all local users, preferences, destinations, swipes, and other database records. Never run it against production data.

## Collaboration workflow

Create a feature branch before changing code:

```powershell
git switch main
git pull origin main
git switch -c feature/your-feature-name
```

Check your changes:

```powershell
git status
git diff
```

Commit related changes together:

```powershell
git add .
git commit -m "Describe the completed change"
```

Push the branch:

```powershell
git push -u origin feature/your-feature-name
```

Open a pull request on GitHub. Ask another team member to review the changes before merging into `main`.

Pull the latest changes before starting new work:

```powershell
git switch main
git pull origin main
```

### Merge the `bon` branch into `main`

Save or commit any current local changes first:

```powershell
git status
git add .
git commit -m "Save current work"
```

Fetch the latest remote branches:

```powershell
git fetch origin
```

Inspect the changes in `bon`:

```powershell
git diff origin/main...origin/bon --stat
git log --oneline --decorate --graph origin/main..origin/bon
```

Update your local `main` branch:

```powershell
git switch main
git pull origin main
```

Merge the branch:

```powershell
git merge --no-ff origin/bon
```

If there are no conflicts, run the application tests:

```powershell
php artisan test
npm run build
```

Push the merged `main` branch:

```powershell
git push origin main
```

If Git reports conflicts, check the affected files:

```powershell
git status
```

Resolve the conflict markers, then run:

```powershell
git add .
git commit -m "Resolve bon merge conflicts"
git push origin main
```

To cancel the merge before committing:

```powershell
git merge --abort
```

Do not force-push to `main`. After confirming the merge is successful, the local branch can be removed with:

```powershell
git branch -d bon
```

The remote branch can be removed only after confirming that nobody still needs it:

```powershell
git push origin --delete bon
```

## Files that should not be committed

Do not commit:

```text
.env
/vendor
/node_modules
/public/build
/storage/*.key
/storage/app/private/crawl-snapshots
```

Laravel’s default `.gitignore` already excludes most generated and sensitive files. Confirm that `.env` is ignored before pushing:

```powershell
git status --ignored
```

Crawled page snapshots are captured to the `local` disk under
`crawl-snapshots/`. They are raw third-party HTML and do not belong in Git.

## Current project status

Completed or actively implemented:

- Project setup
- Tropical Festival design system
- Database models and migrations
- Destination catalog and seed data
- User preferences and weighted matching
- Swipe discovery
- Itinerary generation
- Leaflet destination and itinerary maps
- Reviews and ratings
- Administrator destination management
- Destination archive and permanent removal options
- Curated opening hours with a cited source on every destination
- Per-day hours, closed-day rules, and an hours-kind explanation
- Administrator source crawling with an approval queue
- Source reachability reporting and a per-crawl audit log
- Single-command dev runner (`php artisan dev`)
- PWA foundation
- Editorial front page
- Smooth scrolling and homepage motion
- Login and registration visual redesign
- Page transitions for supported internal navigation

Current polish work:

- Front-page intro overlay
- Mobile responsiveness
- Cross-browser transitions
- Better itinerary-generation algorithm
- Improved route and travel-time optimization
- Accessibility review
- Database and migration cleanup
- Replacing the ~29 unresolvable seeded source domains

Not yet ready for production:

- Full production deployment
- Final security audit
- Final accessibility audit
- Final performance audit
- Complete automated test coverage
- A real process manager to replace `php artisan dev`

## Troubleshooting a fresh clone

| Symptom | Cause | Fix |
|---|---|---|
| `could not find driver` on migrate | `pdo_mysql` is not enabled | Enable `extension=pdo_mysql` in `php.ini` and restart |
| Test suite errors on connect | `pdo_sqlite` is not enabled | Enable `extension=pdo_sqlite` in `php.ini` and restart |
| Timestamps 8 hours early | `APP_TIMEZONE` set in `.env` but `config/app.php` hardcodes `'UTC'` | Keep `env('APP_TIMEZONE')` in `config/app.php` |
| Crawls fail with `cURL error 60` | No CA bundle, or the server started before `php.ini` was fixed | Set `curl.cainfo` / `openssl.cafile`, then **restart the server** |
| Crawl queued but nothing happens | Queue worker is not running | Use `php artisan dev`, or start `php artisan queue:work` |
| `npm ci` fails on install scripts | Lifecycle scripts are disabled in `.npmrc` | That is intended; do not re-enable them |
| One source cannot be crawled | Its host is unreachable, bot-walled, or JS-rendered | Use `fetchability` to filter; it is not a permanent skip |
