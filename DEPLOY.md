# Deploying TraMatch to Hostinger shared hosting

Written against Hostinger **Premium** (SSH + PHP 8.3 confirmed available).
Deployment is still on hold — this is the plan, not a record of a deploy.

Everything here is derived from traps this repo actually has. Where a step looks
defensive, the reason is in a comment or further down.

---

## The three constraints that shape this

**1. No Node.js on the host.** Premium's Node.js is greyed out, and it does not
matter: Node is only a build tool for Vite. The runtime never executes it. This
is also why `public/build` is now **committed** — with `/public/build` ignored,
`git pull` deploys PHP but no CSS or JS, and the site renders unstyled.

The cost is a noisy diff on every front-end change. Before committing:

```sh
npm run build
git add -A public/build   # -A matters, see below
```

**Use `git add -A public/build`, not `git add public/build`.** Vite names its
output by content hash, so a rebuild replaces `app-<hash>.css` with a *new*
filename rather than overwriting the old one. A plain `git add` leaves the
previous hash staged-but-unreferenced, and those orphans accumulate in the repo
forever. Verified: a rebuild changed the CSS hash while the earlier file was
still staged.

To check for orphans before committing:

```sh
diff <(git ls-files public/build | sort) <(cd public/build && find . -type f | sed 's|^\./||' | sort)
```

Empty output means disk and repository agree.

**2. The document root cannot be changed.** Hostinger will not move it on Web
plans, so the domain always serves `~/domains/<domain>/public_html`. See
"Keeping the app outside public_html" below.

**3. Crawls are queued work.** Every crawl sleeps ≥5s then makes two HTTP
requests, so it cannot run inside a web request. Without a cron worker the
`/admin/sources` page queues jobs that nobody ever picks up — and the panel
deliberately does *not* warn about this, because "waiting" is also the normal
state between jobs. See "The queue worker" below.

---

## Two layouts, pick one

Hostinger will not change a Web plan's document root, so the domain always
serves `public_html/`. There are two ways to reconcile that with Laravel wanting
to serve from `public/`. Both work on this host; they differ in where your code
lives and how much of a mistake is dangerous.

| | Layout A — outside | Layout B — inside |
|---|---|---|
| App root | `~/domains/<domain>/` | `~/domains/<domain>/public_html/` |
| `public_html` | symlink → `public` | real directory |
| File manager reaches the code | **no** | **yes** |
| `.env` reachable if rules fail | no | yes |
| `.htaccess` that must be read | `public/.htaccess` | root `.htaccess` |
| Deploy from | `~/domains/<domain>` | `~/domains/<domain>/public_html` |

**Layout A** keeps `.env`, `vendor/`, `storage/` and `config/` outside the web
root entirely, so a mistake in a rewrite rule cannot publish them. The cost is
real though: the control panel's file manager refuses to upload anywhere but
`public_html`, so every file change goes through SSH or git. Deploy is:

```sh
cd ~/domains/yourdomain.tld
git clone -b main https://github.com/Richcholo/tramatch.git .
composer install --no-dev --optimize-autoloader
cp .env.production.example .env
php artisan key:generate
# edit .env
bash deploy/setup-website.sh --force
```

**Layout B** is what you get from the Hostinger guide and what uploading through
the panel produces. The application root becomes the document root, and the root
`.htaccess` forwards requests into `public/` instead of `public/`'s contents
being cut apart. Nothing moves on each deploy, so `git pull` cannot break paths
— but `.env`, `vendor/`, `storage/logs/` and `config/` are all inside the web
root, and **the root `.htaccess` is the only thing refusing them.**

Converting an existing Layout A install:

```sh
cd ~/domains/yourdomain.tld
git pull                        # the root .htaccess has to be present first
bash deploy/setup-public-html-layout.sh
cd public_html
php artisan optimize:clear      # cached config holds the old absolute paths
php artisan optimize
```

The script refuses to run unless it recognises the current layout, backs up
`.env` to `$HOME` first, and prints rollback commands at the end.

### The deny rules are duplicated on purpose

Both layouts refuse the same paths, in `public/.htaccess` and in the root
`.htaccess`. Which one the server reads depends on the layout, so putting them
in one place would mean one layout has no protection.

```sh
curl -sI https://yourdomain.tld/.env | head -1                  # 403/404
curl -sI https://yourdomain.tld/artisan | head -1               # 403/404
curl -sI https://yourdomain.tld/vendor/autoload.php | head -1   # 404
curl -sI https://yourdomain.tld/storage/logs/laravel.log | head -1  # 404
curl -sI https://yourdomain.tld/config/app.php | head -1        # 404
```

`/vendor/autoload.php` is the one to watch in Layout B: nothing else would have
blocked it, so a 200 there means the root `.htaccess` is not being read at all.
`/storage/profile_photos/...` must still return 200 once uploads exist — the
rules deny `storage/logs`, `storage/app`, `storage/framework`, not `storage/`.

### `public/index.php` and the symlink

In Layout A, `public/index.php` resolves paths with
`__DIR__.'/../vendor/autoload.php'`, and PHP's `__DIR__` is the *resolved* real
path, so the symlink does not break the autoloader or the `storage/` lookups.
In Layout B no path is rewritten at all, because `public/` stays where Laravel
put it.

### Confirm nothing leaked

```sh
curl -sI https://yourdomain.tld/.env| head -1     # expect 404
curl -sI https://yourdomain.tld/artisan | head -1  # expect 404
curl -sI https://yourdomain.tld/storage/logs/laravel.log | head -1  # expect 404
```

If any of those returns 200, stop and fix it before sharing the URL.

---

## First deploy

```sh
cd ~/domains/yourdomain.tld
bash deploy/deploy.sh
```

Which is: `optimize:clear` → `git pull --ff-only` → `composer install --no-dev`
→ `chmod` storage → `ln -s` the public storage link if missing →
`migrate --force` → `optimize`.

Subsequent deploys are the same command.

### Why `optimize:clear` runs first

A cached config is compiled from the `.env` as it was when the cache was
written. Without clearing it, changing `APP_URL` or a database password in
`.env` has **no effect** and the deploy silently keeps using the old values.
This bites hardest on the very first deploy, when you edit `.env` and wonder why
nothing changed.

### Why it refuses a dirty tree

A deploy that carries uncommitted edits makes the server diverge from the
repository, and the next `git pull` becomes a conflict instead of a
fast-forward. It excludes `storage/` and `bootstrap/cache/`, which are
legitimately dirty after a request.

### Why `migrate` comes before `optimize`

`optimize` builds `config:cache`, and migrations should have already run
against that config. The opposite order risks migrating with one config and
booting with another.

---

## The queue worker

Crawls are dispatched as `App\Jobs\CrawlSourceJob` and **nothing runs them
inside a web request**. Without a worker, `/admin/sources` queues jobs that sit
forever.

Copy the worker out of the repo and register it in hPanel cron:

```sh
cp deploy/queue-worker.sh ~/queue-worker.sh
```

hPanel → Advanced → Cron Jobs:

| | |
|---|---|
| Command | `/bin/bash /home/u348491703/queue-worker.sh` |
| Interval | every minute |

Edit `APP_ROOT` at the top of that copy if your domain path differs.

Two things in it are deliberate:

- **`--stop-when-empty`** is what makes cron safe. Without it the worker holds
  the slot until it is killed, and the next cron run stacks on top.
- **`--timeout` is deliberately omitted.** `CrawlSourceJob` declares
  `public int $timeout = 300`, and a job-level timeout outranks any worker flag,
  so passing one would be misleading rather than protective.

### Check it is actually running

```sh
php artisan queue:work --stop-when-empty --tries=1   # should return immediately if idle
php artisan sources:check                            # crawls one source
```

Then click **Crawl** in `/admin/sources` and watch the crawl log populate. If
`status` stays `queued`, cron is not firing.

---

## Traps specific to this repo

### Three failures that produce no error message

All three were hit during the first live deploy, and all three fail *quietly* —
no exception, no log entry, nothing in the response to tell you why. They are
listed first because they are the ones that cost time.

**1. A cached config left pointing at the old directory → silent 500.**
`bootstrap/cache/config.php` bakes in absolute paths when it is written. Move
the application and every cached path still points at where it used to be, so
log writes and compiled views go to a directory that no longer exists. The
symptom is a 500 with **zero new lines** in `storage/logs/laravel.log`, and
`APP_DEBUG=true` appearing to do nothing — because the cached config overrides
`.env` entirely. Fix:

```sh
php artisan optimize:clear
```

`deploy.sh` does this before anything else, and it must stay first in the order.

**2. A duplicate `APP_KEY=` line → "No application encryption key".**
`key:generate` rewrites the *first* match; dotenv honours the *last*. One empty
line left over from the template and the generated key is ignored. Check with
`grep -c '^APP_KEY' .env`, which must print exactly `1`.

**3. `storage:link` failing leaves a real directory behind.**
`StorageLinkCommand` creates `public/storage` *first*, then calls `link()`,
which throws here (see below). The empty-but-real directory survives. Combined
with a stale config pointing `storage_path` at it, that turned
`storage/logs/laravel.log` — with the database password and `APP_KEY` in it —
into a URL that returned 200 to anyone who asked. `.htaccess` now denies those
paths outright, so a repeat is refused rather than served.

### `php artisan storage:link` cannot work here

`config/queue.php` derives `retry_after` from `QUEUE_WORKER_TIMEOUT` (+60s);
`AppServiceProvider` re-registers `queue:listen` with the same number. It used
to default to **90** against a 300s job, which meant a crawl taking 100s was
released to a second worker while the first was still fetching — one admin click,
two crawls, two sets of proposals, no error. `tests/Unit/QueueRetryAfterTest.php`
guards it.

Reading the `queue:listen` command's own `--timeout` default tells you 60 and
means nothing: `DevCommands::artisan()` only records a subprocess for
`php artisan dev`, it does not reconfigure the command.

### The app must NOT be uploaded inside `public_html`

This is the mistake that caused a 403 on first deploy, and it is easy to make:
`public_html` is the folder the control panel's file manager opens, so a
drag-and-drop upload of the project lands the whole Laravel root there.

The result:

- The document root is `public_html/`, but `index.php` is then at
  `public_html/public/index.php`. There is no index at the root, so **PHP never
  starts and the domain returns 403**, not 500.
- Worse, `.env`, `storage/` and `vendor/` are all inside the document root. The
  403 happens to hide them, but the moment the layout is fixed by any other
  route — copying `public/*` up, say — your database password is served to the
  internet.

Correct layout:

```
~/domains/yourdomain.tld/     <- app root: .env, app/, storage/, vendor/
~/domains/yourdomain.tld/public_html -> public   <- symlink
```

`setup-website.sh` detects this layout, warns, and fixes it by moving the
contents up one level. It moves files rather than renaming the directory
precisely so `.env` and `storage/` are never relocated wholesale.

Verify, and expect `.env` to be unreachable:

```sh
cd ~/domains/yourdomain.tld
ls -la public_html              # -> public
curl -sI https://yourdomain.tld/.env | head -1     # 404
curl -sI https://yourdomain.tld/storage/logs/laravel.log | head -1   # 404
curl -sI https://yourdomain.tld/artisan | head -1   # 404
```

A 403 on the domain root after this means the symlink is not resolving — check
`ls -la public_html` and that `public/index.php` exists.

### PHP 8.4, not 8.3 — `composer.lock` needs it

The host runs **PHP 8.4**, matching local. It started on 8.3.33, which is *not*
enough: `composer.lock` pins seventeen Symfony 8.0.x packages that require
`>=8.4` (`symfony/http-foundation`, `console`, `routing`, `mailer`, …), and
`symfony/yaml` needs `>=8.4.1`.

That is why `composer.lock` kept showing as modified after every deploy:
`composer install` on 8.3 could not satisfy the lock, so Composer re-resolved
and rewrote it. Set the host to **PHP 8.4** in hPanel → Advanced → PHP, and it
goes away.

`composer.json` now pins `"platform": {"php": "8.4.1"}` so resolution no longer
depends on which machine runs it. The floor is `8.4.1`, not `8.4`, because of
`symfony/yaml`.

If the host is ever moved back to 8.3, `composer install` will fail outright —
that is correct, and the fix is 8.4 rather than downgrading the dependency tree
days before launch.

### `composer.lock` is restored, not trusted, on the server

`deploy.sh` discards a locally-modified `composer.lock` before checking the tree.
A production server must not diverge on the lock: that is how you install a
different dependency set than the one the suite was green against. Other tracked
changes still block normally. Both behaviours are covered by a sandbox test of
the exact logic.

### `php artisan storage:link` cannot work here

Hostinger puts both `symlink()` and `exec()` in `disable_functions`. Laravel's
`Filesystem::link()` tries `symlink()` and falls back to
`exec('ln -s ...')` when it is unavailable, so with both disabled artisan dies
with:

```
Call to undefined function Illuminate\Filesystem\exec()
```

The shell is not restricted, so create the link there instead:

```sh
cd ~/domains/yourdomain.tld
ln -s ../storage/app/public public/storage
```

Relative target, which is what Laravel asks for anyway since it survives the
project directory moving. `deploy/deploy.sh` does this and treats a failure as
non-fatal.

**Nothing in the app reads that directory yet**, so this is not a blocker:

- Crawl snapshots use `Storage::disk('local')`, which roots at
  `storage/app/private` — not `storage/app/public`, and not web-served.
- The only web reference to `public/storage` is
  `asset('storage/' . $user->profile_photo_path)` in the profile view, and
  `profile_photo_path` is never assigned anywhere. Breeze created the column
  and the view; there is no upload controller.

It becomes load-bearing the moment profile photo uploads are implemented.

### What this host allows, measured

Verified on the live account rather than assumed:

| | Result |
|---|---|
| PHP | 8.4 selectable; 8.3 **cannot** install this lock |
| `open_basedir` | **no value** — PHP reads `../vendor/` freely, so the layout above works |
| Symlinked document root | LiteSpeed **follows** `public_html -> public` |
| `symlink()` | disabled |
| `exec()` | disabled |
| `proc_open()` | disabled — `php artisan about` and anything shelling out will fail |

The disabled functions are why `storage:link` cannot work and why
`php artisan about` errors with *"relies on proc_open"*. Neither affects the web
request path. `open_basedir` being unrestricted is what makes the whole
above-`public_html` layout viable, and that is not guaranteed on every Hostinger
plan — check it before assuming.

Also worth enabling in hPanel → Advanced → PHP: **OPcache**.

### Trusting the proxy

Hostinger terminates TLS, so PHP sees plain HTTP from loopback. `bootstrap/app.php`
now calls `trustProxies(at: '*')`, without which Laravel generates `http://`
URLs on an HTTPS page and session cookies are not returned — a 419 on the first
form post. The cost is that `X-Forwarded-For` is client-influenced, so the
login throttle can be sidestepped by forging the header; that was taken
deliberately over the alternative, since not trusting it collapses every visitor
onto one throttle key.

### SMTP or forgot-password fails silently

`MAIL_MAILER=log` is right locally and wrong in production. Two live flows call
the mailer: `Password::sendResetLink()` and the verification resend on
`routes/auth.php`. With the log mailer a traveller is told the link is on its
way, nothing is sent, and the only trace is the link in the log. Premium
includes a mailbox; `.env.production.example` has the SMTP block.

### `APP_TIMEZONE` is read by `config/app.php`, so setting it works

Laravel 11+ ships a hardcoded `'UTC'` there. This repo's `config/app.php` reads
`env('APP_TIMEZONE')`, so `Asia/Manila` takes effect. Timestamps written
*before* that line was fixed are UTC wall-clock and read 8 hours early.

### `public/hot` must never reach the server

If `public/hot` exists, Laravel serves assets from the Vite dev server instead
of `public/build`. It is gitignored, so `git pull` will not create it — but do
not upload your working copy wholesale, or you will ship
`hot` pointing at `http://[::1]:5173`.

### TLS trust store is per-process

Crawls over HTTPS need a real CA bundle in the host's `php.ini`. A missing or
unparseable bundle fails every crawl with `cURL error 60`. Fix the trust store;
never disable verification with `Http::withOptions(['verify' => false])`.
Restart the worker after changing `php.ini` — a running PHP process keeps the
`php.ini` it booted with.

### Datacentre IP changes crawl results

The Azure WAF refusal recorded in `AGENTS.md` commonly rejects data-centre IPs
while serving the same page to a home connection. Re-run `sources:check` **on
the host** before trusting any `fetchability` verdict measured locally.

### Crawls can be killed mid-fetch

Shared hosting caps PHP execution time. Raise `max_execution_time` in
hPanel → Advanced → PHP before deploying. If it is 30–60s, slow crawls die
mid-fetch and the source is left `crawling` until it goes stale. `sources:check`
from the CLI will show which ones are affected.

### The migration chain cannot be validated from here

The dev database has already run every migration, so it cannot detect a broken
chain. `php artisan test` on a developer machine (sqlite, from scratch) is the
only check. This is why the **third Premium website slot is worth having** — run
a staging copy on the same host and migrate that first.

---

## Rollback

1. `git log --oneline` and find the last good commit
2. `git checkout <sha>`
3. `bash deploy/deploy.sh --no-pull`

Migrations do not auto-rollback. If a deploy added a migration, restoring the
old code leaves the extra tables in place — usually harmless, but check
`php artisan migrate:status` before assuming the app is back.

---

## What is not covered

- **Email verification is not enforced.** `User` does not implement
  `MustVerifyEmail`, so the routes exist and the mail can be sent, but nothing
  stops an unverified account. Decide whether that matters before launch.
- **No rate limiting on the public forms** beyond what Breeze sets up. Reviews
  and comments are the obvious targets if this gets traffic.
- **Backups are weekly** on Premium, and `public/build` is now in git so it
  needs no backup. The database does.