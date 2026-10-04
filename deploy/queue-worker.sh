#!/usr/bin/env bash
#
# Queue worker for Hostinger hPanel cron.
#
# Shared hosting will not let you run `queue:work` as a daemon, so this drains
# the queue and exits. hPanel cron is the only thing that ever runs it.
#
# Install by adding a cron entry in hPanel, not by running this by hand:
#
#   Command:  /bin/bash /home/u348491703/queue-worker.sh
#   Interval: every minute
#
# Or from SSH, to append it once:
#
#   crontab -l > /tmp/cron.bak 2>/dev/null || true
#   echo "* * * * * APP_ROOT=$HOME/domains/example.com/public_html /bin/bash $HOME/queue-worker.sh" >> /tmp/cron.bak
#   crontab /tmp/cron.bak
#
# No redirect on that cron line. The script writes its own log, and adding one
# here as well produced two interleaved copies of every line.
#
# Cron on hPanel runs with a minimal environment: no .env, no PATH additions,
# often a different PHP. So resolve the PHP binary explicitly, cd into the app,
# and let the binary pick up .env from the working directory.

set -uo pipefail

# Layout B puts the app root INSIDE public_html, so the default has to be
# edited for every real install. Edit it, or export APP_ROOT from cron -- there
# is no default that is right for both layouts.
APP_ROOT="${APP_ROOT:-$HOME/domains/yourdomain.tld}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
LOG="${QUEUE_WORKER_LOG:-$HOME/queue-worker.log}"

# A misconfiguration must be loud.
#
# These used to `exit 0` and the artisan output was sent to /dev/null, so a
# worker pointing at a placeholder path did nothing every minute, reported
# success, and left crawls sitting in `queued` with no way to tell why. A
# non-zero exit is what makes cron mail the reason.
if [ ! -f "$APP_ROOT/artisan" ]; then
    echo "queue-worker: no artisan at $APP_ROOT -- set APP_ROOT to your app root" >&2
    exit 1
fi

if [ ! -x "$PHP_BIN" ]; then
    # Fall back to whatever php is on PATH.
    PHP_BIN="$(command -v php || true)"
    if [ -z "$PHP_BIN" ]; then
        echo "queue-worker: no php binary found -- set PHP_BIN" >&2
        exit 1
    fi
fi

cd "$APP_ROOT" || exit 1

# Keep the log bounded. This runs every minute, so an unattended file grows
# forever and shared hosting charges for the quota. Trimming in place rather
# than rotating to a second file keeps it to one inode and needs no cleanup.
MAX_LOG_BYTES=$(( 512 * 1024 ))

if [ -f "$LOG" ] && [ "$(wc -c <"$LOG")" -gt "$MAX_LOG_BYTES" ]; then
    tail -c $(( MAX_LOG_BYTES / 2 )) "$LOG" >"$LOG.trimmed" 2>/dev/null \
        && mv -f "$LOG.trimmed" "$LOG"
fi

# --stop-when-empty is what makes this cron-safe: it returns immediately when
# there is nothing to do instead of holding the slot until it is killed.
#
# --timeout is deliberately omitted. CrawlSourceJob declares its own
# `public int $timeout = 300`, and a job-level timeout outranks any worker flag,
# so passing one here would be misleading rather than protective.
#
# Output goes to a log file, never to /dev/null. A crawl that fails already
# records its own status and error_message (see EthicalSourceFetcher), but the
# worker's own failures -- bad DB credentials, a moved APP_ROOT, a PHP fatal --
# were previously invisible on shared hosting, where there is no supervisor to
# read them.
#
# The exit code is still not propagated upward. Cron mails on non-zero, and a
# single unreachable upstream host would then mail every minute; the log has
# the detail and the crawl rows carry the status.
{
    echo "=== $(date -Is) queue-worker start (app_root=$APP_ROOT php=$PHP_BIN)"

    "$PHP_BIN" artisan queue:work \
        --stop-when-empty \
        --tries=1 \
        --no-interaction \
        -v
    status=$?

    echo "=== $(date -Is) queue-worker exit=$status"
} >>"$LOG" 2>&1

exit 0