#!/usr/bin/env bash
#
# Queue worker for Hostinger hPanel cron.
#
# Shared hosting will not let you run `queue:work` as a daemon, so this drains
# the queue and exits. hPanel cron is the only thing that ever runs it.
#
# Install by adding a cron entry in hPanel:
#
#   Command:  /bin/bash /home/uXXXXXXX/queue-worker.sh
#   Interval: every minute
#
# Or from SSH, to append it once:
#
#   crontab -l > /tmp/cron.bak 2>/dev/null || true
#   echo "* * * * * /bin/bash $HOME/queue-worker.sh" >> /tmp/cron.bak
#   crontab /tmp/cron.bak
#
# No redirect on that cron line. The script writes its own log, and adding one
# here as well produced two interleaved copies of every line.
#
# Cron on hPanel runs with a minimal environment: no .env, no PATH additions,
# often a different PHP. So the PHP binary is resolved explicitly, we cd into
# the app, and let that binary pick up .env from the working directory.
#
# There is nothing to edit before this works. The app root is discovered from
# $HOME/domains, so the same script is correct on Layout A (app root above
# public_html) and Layout B (app root inside it).

set -uo pipefail

LOG="${QUEUE_WORKER_LOG:-$HOME/queue-worker.log}"

log() {
    printf '%s run: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >>"$LOG"
}

# Logged as well as mailed. Cron mail on shared hosting is the first thing that
# stops being delivered, so a misconfiguration that only appears there is
# indistinguishable from no misconfiguration at all.
fail() {
    log "FATAL $*"
    printf 'queue-worker: %s\n' "$*" >&2
    exit 1
}

# Keep the log bounded. This runs every minute, so an unattended file grows
# forever and shared hosting charges for the quota. Trimming in place rather
# than rotating to a second file keeps it to one inode and needs no cleanup.
MAX_LOG_BYTES=$(( 512 * 1024 ))

if [ -f "$LOG" ] && [ "$(wc -c <"$LOG")" -gt "$MAX_LOG_BYTES" ]; then
    tail -c $(( MAX_LOG_BYTES / 2 )) "$LOG" >"$LOG.trimmed" 2>/dev/null \
        && mv -f "$LOG.trimmed" "$LOG"
fi

# ---------------------------------------------------------------------------
# Find the app root.
#
# This used to default to the literal placeholder $HOME/domains/yourdomain.tld
# and exit 0 on a miss, with the artisan output sent to /dev/null. A worker
# with the unedited default therefore did nothing every minute, reported
# success, and left every crawl sitting in `queued` with nothing anywhere to
# say why. Two deployments were lost to it.
#
# An explicit APP_ROOT still wins, so a layout this discovery cannot guess is
# one export away rather than an edit to a copy of this file that lives outside
# version control and is never pulled again.
# ---------------------------------------------------------------------------
if [ -z "${APP_ROOT:-}" ]; then
    match_count=0
    match=""

    # Both layouts, in one pass. An app root is a directory holding BOTH
    # `artisan` and `.env`; that pair is what distinguishes it from the
    # public_html beside it, which holds neither in either layout.
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
            fail "no app root found under $HOME/domains (looked for a directory containing both artisan and .env). Set APP_ROOT on the cron line."
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
# Drain the queue.
#
# --stop-when-empty is what makes this cron-safe: it returns immediately when
# there is nothing to do instead of holding the slot until it is killed.
#
# --timeout is deliberately omitted. CrawlSourceJob declares its own
# `public int $timeout = 300`, and a job-level timeout outranks any worker flag,
# so passing one here would be misleading rather than protective.
#
# The exit code is not propagated upward. Cron mails on non-zero, and a single
# unreachable upstream host would then mail every minute; the log has the
# detail and each crawl records its own status and error_message (see
# EthicalSourceFetcher). A failure to reach the worker at all still exits
# non-zero, because that is a misconfiguration rather than a dead host.
# ---------------------------------------------------------------------------
log "start app_root=$APP_ROOT php=$PHP_BIN"

{
    "$PHP_BIN" artisan queue:work \
        --stop-when-empty \
        --tries=1 \
        --no-interaction \
        -v
} >>"$LOG" 2>&1
status=$?

log "end exit=$status"

exit 0
