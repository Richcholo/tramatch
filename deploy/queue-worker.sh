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
#   echo "* * * * * /bin/bash $HOME/queue-worker.sh >> $HOME/queue-worker.log 2>&1" >> /tmp/cron.bak
#   crontab /tmp/cron.bak
#
# Cron on hPanel runs with a minimal environment: no .env, no PATH additions,
# often a different PHP. So resolve the PHP binary explicitly, cd into the app,
# and let the binary pick up .env from the working directory.

set -uo pipefail

APP_ROOT="${APP_ROOT:-$HOME/domains/yourdomain.tld}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

if [ ! -f "$APP_ROOT/artisan" ]; then
    echo "queue-worker: no artisan in $APP_ROOT -- set APP_ROOT" >&2
    exit 0
fi

if [ ! -x "$PHP_BIN" ]; then
    # Fall back to whatever php is on PATH.
    PHP_BIN="$(command -v php || true)"
    if [ -z "$PHP_BIN" ]; then
        echo "queue-worker: no php binary found -- set PHP_BIN" >&2
        exit 0
    fi
fi

cd "$APP_ROOT" || exit 0

# --stop-when-empty is what makes this cron-safe: it returns immediately when
# there is nothing to do instead of holding the slot until it is killed.
#
# --timeout is deliberately omitted. CrawlSourceJob declares its own
# `public int $timeout = 300`, and a job-level timeout outranks any worker flag,
# so passing one here would be misleading rather than protective.
#
# The exit code is not propagated. A failing crawl has already recorded its own
# status and error_message before rethrowing (see EthicalSourceFetcher), so a
# non-zero exit here only makes cron send mail on every transient failure.
"$PHP_BIN" artisan queue:work \
    --stop-when-empty \
    --tries=1 \
    --no-interaction \
    >/dev/null 2>&1

exit 0