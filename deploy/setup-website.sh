#!/usr/bin/env bash
#
# One-time host setup for Hostinger shared hosting.
#
# Hostinger will not change a Web plan's document root, so the domain always
# serves ~/domains/<domain>/public_html. Laravel must be served from its own
# public/ directory, and everything else -- .env, app/, storage/, vendor/ --
# must stay outside the web root.
#
# The documented workaround is to keep the application one level up and make
# public_html a symlink to public. Hostinger looks for public_html by name, so
# the symlink satisfies it while the real files stay in public/.
#
# Run this ONCE, by hand, after the first clone. It rewrites public_html, so it
# refuses to run twice without --force.
#
# Usage:
#   cd ~/domains/yourdomain.tld
#   bash deploy/setup-website.sh

set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_ROOT"

FORCE="${1:-}"

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
bold()  { printf '\033[1m%s\033[0m\n' "$*"; }

bold "== TraMatch host setup =="
echo "application root: $APP_ROOT"

# --------------------------------------------------------------- preflight --

if [ ! -f artisan ] || [ ! -f composer.json ]; then
    red "This does not look like the application root."
    red "Expected artisan and composer.json in $APP_ROOT."
    exit 1
fi

if [ ! -d public ]; then
    red "No public/ directory. Run git clone or git pull first."
    exit 1
fi

if [ ! -f vendor/autoload.php ]; then
    red "Dependencies are not installed. Run:"
    red "    composer install --no-dev --optimize-autoloader"
    exit 1
fi

PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
if ! php -r 'exit(version_compare(PHP_VERSION, "8.3", ">=") ? 0 : 1);'; then
    red "PHP $PHP_VERSION is too old. composer.json requires ^8.3."
    red "Set it in hPanel -> Advanced -> PHP, then re-run."
    exit 1
fi
green "php $PHP_VERSION"

# ----------------------------------------------------------------- .env check

if [ ! -f .env ]; then
    red "No .env yet. Create it before wiring up public_html:"
    red "    cp .env.production.example .env"
    red "    php artisan key:generate"
    red "then edit .env and re-run this script."
    exit 1
fi
green ".env present"

# ------------------------------------------------------------- public_html --

if [ -L public_html ]; then
    CURRENT="$(readlink public_html)"
    if [ "$CURRENT" = "public" ] || [ "$CURRENT" = "./public" ]; then
        green "public_html already points at public/ -- nothing to do."
        echo
        bold "Remaining steps:"
        echo "  1. Edit .env with the real database and mail credentials."
        echo "  2. php artisan migrate --seed --force"
        echo "  3. php artisan storage:link"
        echo "  4. php artisan optimize"
        echo "  5. Add the queue worker cron (see DEPLOY.md)."
        exit 0
    fi

    red "public_html already points somewhere else: $CURRENT"
    red "Fix it by hand if this is wrong; not touching it."
    exit 1
fi

if [ -d public_html ]; then
    CONTENTS="$(find public_html -mindepth 1 -maxdepth 1 2>/dev/null | wc -l | tr -d ' ')"

    echo
    bold "public_html exists and contains $CONTENTS entries."
    echo "Hostinger seeds it with a placeholder .htaccess and index.php."
    echo "This step moves it aside to public_html.hostinger-backup."
    echo
    echo "If it contains anything of yours, copy it into public/ first."
    echo

    if [ "$FORCE" != "--force" ]; then
        red "Not touching it. Re-run with --force once you are sure:"
        red "    bash deploy/setup-website.sh --force"
        exit 1
    fi

    mv public_html public_html.hostinger-backup
    green "moved the original to public_html.hostinger-backup"
fi

ln -s public public_html
green "public_html -> public"

if [ ! -e public_html/index.php ]; then
    red "The symlink does not resolve to public/index.php. Check it by hand:"
    red "    ls -la public_html"
    exit 1
fi

green "public_html resolves to public/index.php"

# ------------------------------------------------------------------ storage --

# The web user and the CLI user are different on shared hosting, so both need
# write access. 775 lets the group share; if artisan reports permission errors
# use 777 for these two directories only.
chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || true
green "storage/ and bootstrap/cache/ are group-writable"

echo
bold "== Host setup done =="
echo
echo "Still to do by hand:"
echo "  1. Edit .env: APP_URL, the four DB_* values, MAIL_USERNAME/PASSWORD,"
echo "     MAIL_FROM_ADDRESS, SESSION_SECURE_COOKIE=true, CRAWLER_CONTACT."
echo "  2. php artisan migrate --seed --force"
echo "  3. php artisan storage:link"
echo "  4. php artisan optimize"
echo "  5. Add the queue worker cron. See DEPLOY.md."
echo
echo "Verify the document root is NOT leaking anything:"
echo "    curl -sI https://yourdomain.tld/.env | head -1   # expect 404"
echo "    curl -sI https://yourdomain.tld/artisan | head -1 # expect 404"