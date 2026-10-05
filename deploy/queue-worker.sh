#!/usr/bin/env bash
#
# Queue worker wrapper for Hostinger hPanel cron.
#
# This is a wrapper and nothing else. Every decision about what to do with a
# queued job lives in `php artisan queue:drain` (app/Console/Commands/DrainQueue.php),
# which is covered by tests. Do not move that logic in here: it was here before,
# and it could not be tested, which is how a placeholder APP_ROOT and a silent
# `exit 0` survived two deployments.
#
# What is left for a shell script is exactly what shell is better at here:
#
#   - finding the app root, because cron runs with no working directory and this
#     host disables both symlink() and proc_open(), so nothing can ask artisan to
#     work it out;
#   - finding the PHP binary, because cron has a minimal PATH and the CLI php is
#     not necessarily the same build as the web one;
#   - leaving a record that cron ran at all, which is the one fact the log in
#     storage/logs/laravel.log cannot tell you.
#
# Install by adding a cron entry in hPanel:
#
#   Command:  /bin/bash /home/uXXXXXXX/queue-worker.sh
#   Interval: every minute
#
# Or from SSH:
#
#   crontab -l > /tmp/cron.bak 2>/dev/null || true
#   echo "* * * * * /bin/bash $HOME/queue-worker.sh" >> /tmp/cron.bak
#   crontab /tmp/cron.bak
#
# No redirect on that cron line. This script writes its own log, and adding one
# there as well interleaved two copies of every line.
#
# Running it by hand is a valid test of the cron path, and it is the reason this
# is a file rather than a command typed into the cron field. If you are not sure
# whether the worker works, run this over SSH. Running `php artisan queue:drain`
# instead tests something subtly different from what cron will execute: the
# shell that resolves the path, the PHP binary it picks, and the environment
# cron hands it. That difference is what made a broken setup look like a working
# one for two deployments.

set -uo pipefail

LOG="${QUEUE_WORKER_LOG:-$HOME/queue-worker.log}"

log() {
    printf '%s queue-worker: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >>"$LOG"
}

# Logged as well as mailed. Cron mail on shared hosting is the first thing that
# quietly stops being delivered, and a misconfiguration that appears only there
# is indistinguishable from no misconfiguration at all.
fail() {
    log "FATAL $*"
    printf 'queue-worker: %s\n' "$*" >&2
    exit 1
}

# Keep the log bounded. This runs every minute, so an unattended file grows
# forever and shared hosting charges for the quota. Trimming in place rather than
# rotating to a second file keeps it to one inode and needs no cleanup.
MAX_LOG_BYTES=$(( 512 * 1024 ))

if [ -f "$LOG" ] && [ "$(wc -c <"$LOG")" -gt "$MAX_LOG_BYTES" ]; then
    tail -c $(( MAX_LOG_BYTES / 2 )) "$LOG" >"$LOG.trimmed" 2>/dev/null \
        && mv -f "$LOG.trimmed" "$LOG"
fi

# ---------------------------------------------------------------------------
# Find the app root.
#
# This defaulted to the literal placeholder $HOME/domains/yourdomain.tld and, on a
# miss, exited 0 with the artisan output sent to /dev/null. A worker left
# unedited therefore did nothing every minute, reported success, and left every
# crawl sitting in `queued` with nothing anywhere to say why. There is no default
# here now: an unset APP_ROOT means "go look", and failing to find exactly one
# app is an error rather than a shrug.
#
# An app root is a directory holding BOTH `artisan` and `.env`. That pair is what
# distinguishes it from the public_html beside it, which holds neither in either
# deployment layout, so one rule covers Layout A and Layout B.
# ---------------------------------------------------------------------------
if [ -z "${APP_ROOT:-}" ]; then
    match_count=0
    match=""

    for candidate in "$HOME"/domains/*/ "$HOME"/domains/*/public_html/; do
        [ -d "$candidate" ] || continue
        [ -f "${candidate}artisan" ] || continue
        [ -f "${candidate}.env" ] || continue

        match_count=$(( match_count + 1 ))
        match="${candidate%/}"
    done

    case "$match_count" in
        1)
            APP_ROOT="$match"
            ;;
        0)
            fail "no app root under $HOME/domains (looked for a directory containing both artisan and .env). Set APP_ROOT on the cron line."
            ;;
        *)
            fail "ambiguous app root, $match_count directories under $HOME/domains contain both artisan and .env: $match. Set APP_ROOT on the cron line."
            ;;
    esac
fi

[ -f "$APP_ROOT/artisan" ] || fail "no artisan at $APP_ROOT -- APP_ROOT must be the directory containing artisan"

# ---------------------------------------------------------------------------
# Find PHP.
# ---------------------------------------------------------------------------
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

if [ ! -x "$PHP_BIN" ]; then
    # Fall back to whatever php is on PATH.
    PHP_BIN="$(command -v php || true)"

    if [ -z "$PHP_BIN" ]; then
        fail "no php binary found -- set PHP_BIN on the cron line"
    fi
fi

cd "$APP_ROOT" || fail "cannot cd to $APP_ROOT"

# ---------------------------------------------------------------------------
# Drain.
#
# The behaviour is DrainQueue's, not this script's: it stops on the first job
# that throws outside its own handling (release() makes a failing job
# immediately re-poppable, so looping would spin until the wall-clock cap), it
# logs what it did to storage/logs/laravel.log, and it always exits 0 so a
# single unreachable upstream host does not mail every minute.
#
# What this script adds is the two facts only the shell can observe: that cron
# fired at all, and which PHP it resolved.
# ---------------------------------------------------------------------------
log "start app_root=$APP_ROOT php=$PHP_BIN"

"$PHP_BIN" artisan queue:drain >>"$LOG" 2>&1
status=$?

log "end exit=$status"

# Non-zero only when the drain itself could not run. A failing crawl is a normal
# outcome that has already recorded its own status, and mail on every one of them
# trains the owner to ignore cron mail.
if [ "$status" -ne 0 ]; then
    exit "$status"
fi

exit 0
