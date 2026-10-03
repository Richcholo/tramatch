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

## Keeping the app outside `public_html`

The app lives at `~/domains/yourdomain.tld/` and `public_html` becomes a
**symlink to `public`**. Hostinger finds `public_html` by name, so the symlink
satisfies it, while `.env`, `app/`, `storage/` and `vendor/` stay unreachable
from the web.

```sh
cd ~/domains/yourdomain.tld
git clone -b main https://github.com/Richcholo/tramatch.git .
composer install --no-dev --optimize-autoloader

cp .env.production.example .env
php artisan key:generate
# edit .env now -- the script checks for it

bash deploy/setup-website.sh --force
```

The script refuses to run without `--force` the first time, because it moves
Hostinger's seeded `public_html` aside to `public_html.hostinger-backup`. Check
that backup is empty of anything you need before letting it go.

`public/index.php` resolves paths with `__DIR__.'/../vendor/autoload.php'`, and
PHP's `__DIR__` is the *resolved* real path, so the symlink does not break the
autoloader or the `storage/` lookups.

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

### `retry_after` must exceed the crawl timeout — already fixed, do not undo

`config/queue.php` derives `retry_after` from `QUEUE_WORKER_TIMEOUT` (+60s);
`AppServiceProvider` re-registers `queue:listen` with the same number. It used
to default to **90** against a 300s job, which meant a crawl taking 100s was
released to a second worker while the first was still fetching — one admin click,
two crawls, two sets of proposals, no error. `tests/Unit/QueueRetryAfterTest.php`
guards it.

Reading the `queue:listen` command's own `--timeout` default tells you 60 and
means nothing: `DevCommands::artisan()` only records a subprocess for
`php artisan dev`, it does not reconfigure the command.

### UNRESOLVED — the domain returns 403, not a PHP error

`curl -sI https://tramatch.site/` returns `HTTP/2 403`. **This is not a PHP
version problem** — a version or fatal error gives 500, not 403. A 403 means the
request never reached PHP at all, so nothing in this document is the cause yet.

Most likely one of:

1. **`public_html` is not the symlink.** If `setup-website.sh` failed, or the
   domain's document root points elsewhere, there is no `index.php` to run.
2. **Hostinger's own block** — some accounts 403 until a domain is verified or
   while the account is in a setup state.
3. **The symlink is in place but the target is wrong** — check it resolves.

Diagnose on the host:

```sh
cd ~/domains/tramatch.site
ls -la public_html                 # must be a symlink to public
ls public/index.php                # must exist
ls public_html/index.php           # must resolve through the symlink
```

If `public_html` is missing or is still a directory, re-run:

```sh
bash deploy/setup-website.sh --force
```

Do **not** treat this as resolved until `curl -sI https://tramatch.site/` returns
200 or 301.

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