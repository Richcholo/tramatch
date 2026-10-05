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

**Re-copy it after every pull.** It is the one file in this repo with a live
copy outside version control at `~/queue-worker.sh`, so `git pull` never reaches
it. That is how a fixed script kept running the old one.

### Test it the way cron runs it

```sh
/bin/bash ~/queue-worker.sh; echo "exit=$?"
```

Run *that*, not `php artisan queue:drain`. The two are not the same test: the
script is what resolves the app root, picks the PHP binary, and sees cron's
minimal environment, and none of that happens when you invoke artisan directly.
Testing artisan by hand and concluding the worker is broken has been the
mistake twice — `php` worked fine every time, the wrapper around it did not.

There is nothing to edit first. With `APP_ROOT` unset the script looks for a
directory under `$HOME/domains` holding **both** `artisan` and `.env`, which is
true of the app root in Layout A and Layout B and of nothing else. Failing to
find exactly one is an error, logged and exited non-zero, never ignored.

### Read the two logs

They answer different questions, which is why there are two:

| Question | Where |
|---|---|
| did cron fire, and which PHP did it pick? | `~/queue-worker.log` |
| did the drain find work, and what happened to it? | `storage/logs/laravel.log`, `queue:drain` prefix |

```sh
tail -n 20 ~/queue-worker.log
grep queue:drain storage/logs/laravel.log | tail -n 20
```

`queue-worker: start` once a minute means cron is firing. Then:

| In `storage/logs/laravel.log` | Meaning |
|---|---|
| no `queue:drain` lines | the script never reached artisan — check its own log |
| `found the queue empty` | **the worker runs and the queue is empty** — the dispatch is not enqueuing. Look at `SourceController`, not at cron |
| `job threw outside its own handling` | a job failed without recording why; released for the next run |
| `hit its wall-clock cap with work still queued` | more work than one minute allows; expected mid-bulk-crawl |

That third row is the distinction that was missing both times this went wrong:
"cron is broken" and "nothing was ever queued" look identical from
`/admin/sources`.

The script deliberately holds no job-handling logic — that all lives in
`php artisan queue:drain`, which is covered by tests. It stays a shell script
only for the three things shell is better at here: finding the app root, finding
the PHP binary, and recording that cron ran.

```sh
php artisan queue:drain          # drains and exits; same work, fewer moving parts
php artisan queue:failed         # jobs that exhausted their retries
php artisan sources:check        # crawls one source synchronously
```

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

…except that **profile photo upload is implemented**
(`ProfileController::updatePhoto` stores to the `public` disk under
`profile-photos/`, and the view reads it back through
`asset('storage/' . $user->profile_photo_path)`). So the link is now
load-bearing: uploads succeed and the image 404s until it exists.

```sh
cd ~/domains/<domain>/public_html
ln -s ../storage/app/public public/storage
curl -sI https://yourdomain.tld/storage/profile-photos/<file> | head -1   # 200
```

**Never leave a real directory at `public/storage` on this host.** That is how
the log became world-readable (see trap 3 above): the failed `storage:link`
leaves one behind. If it exists and is not a symlink, remove it and re-link.

### An oversized upload fails silently

Admin destination photos are capped at 4 MB by `DestinationController`'s
`max:4096` rule. If a file exceeds PHP's own `upload_max_filesize` — hPanel
defaults vary and are often lower — PHP discards **the whole request body**,
including `$_FILES` *and* `$_POST`. Laravel then sees an empty POST, `$request-
>file('image')` is null, the image is simply not replaced, and the admin is
told "Destination updated."

Nothing anywhere reports a problem, so check the limits before blaming the form:

```sh
php -i | grep -E 'upload_max_filesize|post_max_size'
```

Raise both in hPanel (PHP Configuration → Options) if the photo you need is
larger than the limit. `post_max_size` must exceed `upload_max_filesize`, since
the whole body is rejected once the limit is passed.

There is a second, unrelated no-op worth knowing: a form without
`enctype="multipart/form-data"` sends no file either, and looks identical from
the outside. `DestinationImageUploadTest` pins that both admin forms carry it.
`deploy.sh` creates the link only when missing, and deliberately does not pass
`--force`, which would delete a real directory.

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

Verify with `php artisan mail:test you@example.com`. It prints the resolved
mailer, host, port, encryption and username *before* sending, because the usual
failure is a config that was never applied rather than a bad password. Run
`php artisan optimize:clear` first — a stale `config:cache` overrides `.env`,
and the command would otherwise report the settings from whenever the cache was
written. An unset `MAIL_SCHEME` is normal and is not the fault: port 465 implies
implicit TLS, and the command says so rather than printing a blank line.

#### `554 5.7.1 <unknown[IPv6]>: Client host rejected: Access denied`

Measured on this host, 2026-10-04. **Root cause: `MAIL_USERNAME` and
`MAIL_PASSWORD` were missing from `.env`.**

Symfony's `EsmtpTransport` skips the `AUTH` exchange entirely when no username
is configured. It went straight to `MAIL FROM` → `RCPT TO` unauthenticated, and
Postfix refused to relay for an unauthenticated client — which is what
"Client host rejected: Access denied" means. It had nothing to do with the
sending address.

Two things made this look like a network or DNS fault, and both are traps worth
recording:

- The bracketed name is `unknown[2a02:4780:5c:2350:0:14c5:8fb7:1]`. `unknown`
  genuinely does mean reverse DNS did not resolve, but Postfix prints the
  client host on *any* relay rejection, so reading it as the cause was wrong.
- The rejection is at `RCPT TO`, and Symfony reports the expected code as
  `250/251/252` — the RCPT success codes. `MAIL FROM` alone answers `250`. So
  the code confirms *where* it failed and says nothing about credentials. It is
  not a `535` because `AUTH` was never attempted.

**`php artisan mail:test` prints the resolved username.** That line is the
diagnostic and it was on screen: `username  (none)`. A transport failure with no
username is the whole explanation. When a `mail:test` failure is being reasoned
about, read its whole output rather than the last line.

Two unrelated findings from the same investigation, both still true:

- The SMTP service is healthy. Verified by hand from another network: TLS 1.2,
  `220 ESMTP smtp.hostinger.com`, `250-AUTH PLAIN LOGIN`.
- **`MAIL_MAILER=sendmail` cannot be used on this host, whatever the fault.**
  Hostinger disables `proc_open`, and Symfony's `SendmailTransport` builds a
  `ProcessStream` in its constructor, which calls `proc_open()` unguarded:

  ```
  vendor/symfony/mailer/Transport/Smtp/Stream/ProcessStream.php:47
      $this->stream = proc_open($this->command, $descriptorSpec, $pipes);
  ```

  `mb_send_mail` is disabled too, and Laravel 11+ ships no transport reaching
  PHP's `mail()`. Do not reach for `sendmail` as a fallback here.

Also measured, for the record: `smtp.hostinger.com` resolves to Cloudflare
(`172.65.255.143`, and `2606:4700:…` for IPv6), and its own IPv4 has no PTR
either (`NXDOMAIN` on the reverse lookup). So if an unauthenticated relay ever
does need addressing, forcing an IPv4 peer is not the fix — and it should not be
tried, because Symfony's `SocketStream` sets no `verify_peer` at all, which would
put the SMTP password on a connection whose certificate is unchecked.

`.env.production.example` carries the SMTP block. Fill in the username and
password of a **real mailbox created in hPanel** — Hostinger will not relay for
an address that does not exist.

`MAIL_MAILER=log` remains the safe fallback if this ever regresses:
password reset is broken but nothing crashes and the link is recoverable from
`storage/logs/laravel.log`.

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