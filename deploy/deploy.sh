#!/usr/bin/env bash
#
# Repeatable deploy for Hostinger shared hosting. Assumes setup-website.sh has
# already been run once.
#
#   cd ~/domains/yourdomain.tld
#   bash deploy/deploy.sh
#
# Safe to run repeatedly. Every step is idempotent.
#
# Flags:
#   --no-pull     skip `git pull` (deploy whatever is already checked out)
#   --ref=<ref>   pull a specific ref instead of the tracked branch
#   --migrate-only run only the migrations, for a hotfix that ships no code
#
# Assembled from the pitfalls this repo actually hits; see DEPLOY.md.

set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_ROOT"

DO_PULL=1
MIGRATE_ONLY=0
REF=""

for arg in "$@"; do
    case "$arg" in
        --no-pull)     DO_PULL=0 ;;
        --migrate-only) MIGRATE_ONLY=1; DO_PULL=0 ;;
        --ref=*)       REF="${arg#--ref=}" ;;
        *) echo "Unknown option: $arg" >&2; exit 1 ;;
    esac
done

green() { printf '\033[32m%s\033[0m\n' "$*"; }
red()   { printf '\033[31m%s\033[0m\n' "$*"; }
bold()  { printf '\033[1m%s\033[0m\n' "$*"; }
fail()  { red "$*"; exit 1; }

bold "== TraMatch deploy =="
echo "root: $APP_ROOT"
echo "php:  $(php -r 'echo PHP_VERSION;')"
echo

# --------------------------------------------------------------- preflight --

[ -f artisan ] || fail "Not the application root (no artisan)."

if [ ! -f .env ]; then
    fail "No .env. Copy .env.production.example and fill it in first."
fi

# A .env that cannot boot fails on the first request, not on deploy. It has
# broken this deployment twice: once because APP_KEY was empty, once because a
# duplicate APP_KEY line meant key:generate fixed the first and dotenv read the
# second. Both surface as MissingAppKeyException with nothing in the log.
# grep -c prints 0 AND exits non-zero when there is no match, so `|| echo 0`
# would append a second 0 and produce "0\n0". wc -l always succeeds and counts
# what is actually there.
APP_KEY_LINES="$(grep '^APP_KEY=' .env 2>/dev/null | wc -l | tr -d ' ')"
APP_KEY_VALUE="$(grep '^APP_KEY=' .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d '\r')"

if [ "$APP_KEY_LINES" != "1" ]; then
    fail ".env has $APP_KEY_LINES APP_KEY lines, expected exactly 1. grep -n '^APP_KEY' .env"
fi

if [ -z "$APP_KEY_VALUE" ]; then
    fail "APP_KEY is empty in .env. Run: php artisan key:generate --force"
fi

green "APP_KEY present and unique"

# A cached config is built from the .env as it was when the cache was written.
# Clearing it first means this deploy actually sees an edited .env -- a changed
# DB password or APP_URL will not take effect otherwise.
bold "-- clearing stale caches"
php artisan optimize:clear
echo

if [ "$MIGRATE_ONLY" -eq 1 ]; then
    bold "-- migrations only"
    php artisan migrate --force
    php artisan optimize
    green "done"
    exit 0
fi

# -------------------------------------------------------------------- pull --

if [ "$DO_PULL" -eq 1 ]; then
    bold "-- git pull"

    # Refuse to run against a dirty tree. A deploy that silently carries
    # uncommitted edits makes the server diverge from the repository, and the
    # next pull becomes a merge conflict instead of a fast-forward.
    # composer.lock is restored rather than demanded. `composer install` on a
    # host whose PHP differs from the machine that wrote the lock re-resolves and
    # rewrites the file, so it is always dirty after the first deploy and would
    # block every subsequent one. A production server must never diverge on the
    # lock -- that is exactly how you end up installing a different dependency
    # set than the one the suite was green against -- so discarding it is the
    # correct behaviour, not a workaround.
    #
    # config.platform in composer.json is what stops the rewrite happening at
    # all: it pins resolution to a fixed PHP regardless of the machine.
    if ! git diff --quiet -- composer.lock 2>/dev/null; then
        red "composer.lock was modified on this server -- discarding it."
        red "The repository's lock is authoritative."
        git checkout -- composer.lock
        echo
    fi

    if [ -n "$(git status --porcelain -- ':!storage' ':!bootstrap/cache')" ]; then
        red "The working tree has uncommitted changes:"
        git status --short -- ':!storage' ':!bootstrap/cache'
        echo
        red "Commit or discard them, or use --no-pull to deploy as-is."
        exit 1
    fi

    CURRENT="$(git rev-parse --short HEAD)"
    echo "currently at: $CURRENT on $(git rev-parse --abbrev-ref HEAD)"

    if [ -n "$REF" ]; then
        git fetch origin --quiet
        git checkout "$REF"
        git pull --ff-only origin "$REF"
    else
        git pull --ff-only
    fi

    NEW="$(git rev-parse --short HEAD)"
    if [ "$CURRENT" = "$NEW" ]; then
        green "already up to date at $NEW"
    else
        green "deployed $CURRENT -> $NEW"
        git log --oneline "$CURRENT..$NEW" | sed 's/^/    /'
    fi
    echo
fi

# --------------------------------------------------------------- composer --

# --no-dev because phpunit and the dev tooling are not wanted on the host, and
# it roughly halves vendor/. The package:discover script that runs afterwards
# needs a bootable app, so .env must already be correct by this point.
#
# Guarded on purpose: this removes dev packages rather than just skipping them,
# so running it on a developer machine deletes PHPUnit out from under the test
# suite (recoverable with a plain `composer install`). The server is the only
# place it belongs.
if [ -f .env ] && grep -q '^APP_ENV=local' .env 2>/dev/null; then
    red "This .env says APP_ENV=local -- refusing to run --no-dev composer install."
    red "It would delete your dev dependencies, including PHPUnit."
    red "Deploy from the server, or set --no-pull and handle composer yourself."
    exit 1
fi

bold "-- composer install"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress
echo

# ------------------------------------------------------------- directories --

# The web user and the CLI user differ on shared hosting. Without this the web
# server can serve the app but cannot write logs or compiled views, and the
# failure surfaces as a 500 on the first page view with nothing useful logged.
bold "-- writable directories"
chmod -R ug+rwx storage bootstrap/cache
green "storage/ bootstrap/cache/"

# public/storage -> storage/app/public, created with ln -s rather than
# `php artisan storage:link`.
#
# Hostinger puts both symlink() and exec() in disable_functions. Laravel's
# Filesystem::link() tries symlink() and falls back to exec('ln -s ...') when it
# is missing, so with both disabled artisan dies with
# "Call to undefined function Illuminate\Filesystem\exec()". The shell has no
# such restriction, so linking here works.
#
# Relative target, which is what Laravel asks for anyway: it survives the
# project directory being moved or renamed.
#
# Guarded twice. `-L` also catches a broken symlink, which `-e` would not, and a
# stale link is the realistic failure here. And a failure is reported but not
# fatal, because nothing in the app reads this directory today -- crawl snapshots
# go to the 'local' disk at storage/app/private, and the only web reference is
# asset('storage/' . $user->profile_photo_path) on a column nothing ever writes.
# Adding photo uploads later makes it load-bearing.
if [ -L public/storage ]; then
    green "public/storage already linked"
elif [ -e public/storage ]; then
    red "public/storage exists but is not a symlink -- leaving it alone."
    red "If it is a stray directory, remove it and re-run."
else
    if ln -s ../storage/app/public public/storage 2>/dev/null; then
        green "public/storage -> ../storage/app/public"
    else
        red "Could not create public/storage. Continuing: nothing uses it yet."
        red "Nothing breaks until profile photo uploads are implemented."
    fi
fi
echo

# ---------------------------------------------------------------- migrate --

bold "-- migrations"
php artisan migrate --force
echo

# ---------------------------------------------------------------- optimize --

# Last, so it compiles the config that migrate just ran against. route:cache is
# safe here even though routes/web.php has a closure for '/', which Laravel 11+
# can serialise.
bold "-- config/route/view cache"
php artisan optimize
echo

# ------------------------------------------------------------------ verify --

bold "== deploy complete =="
echo
echo "Check these before calling it done:"
echo
echo "  curl -sI https://yourdomain.tld/                # expect 200"
echo "  curl -sI https://yourdomain.tld/.env            # expect 404"
echo "  curl -sI https://yourdomain.tld/artisan         # expect 404"
echo "  php artisan about                                # env should say production"
echo
echo "Then confirm the queue worker is actually running, since crawls silently"
echo "queue forever if it is not:"
echo "  php artisan queue:work --stop-when-empty --tries=1"
echo "  php artisan sources:check"
echo
echo "NOTE: crawling from here means a datacentre IP. Azure WAF refusals and"
echo "other bot walls may differ from what you measured at home, and crawls"
echo "that hang will die at the host's max_execution_time. See DEPLOY.md."