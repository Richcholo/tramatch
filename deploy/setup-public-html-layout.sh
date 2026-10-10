#!/usr/bin/env bash
#
# Move the application INTO public_html/, so the control panel's file manager can
# reach it.
#
# Trade-off, stated plainly: this puts .env, vendor/, storage/ and config/
# inside the document root. The root .htaccess refuses them, but that file is
# the only thing standing between a mistake and a published database password.
# The alternative layout -- application root one level up, public_html a symlink
# to public/ -- keeps them outside the web entirely and is one command to go
# back to.
#
# Run once, after `git pull` so the root .htaccess is present:
#
#   cd ~/domains/<domain>
#   bash deploy/setup-public-html-layout.sh
#
# Refuses to run if it does not recognise the current layout. Everything it does
# is reversible from the printed rollback commands.

set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_ROOT"

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
bold()  { printf '\033[1m%s\033[0m\n' "$*"; }

bold "== Move TraMatch into public_html/ =="
echo "application root: $APP_ROOT"
echo

# --------------------------------------------------------------- preflight --

if [ ! -f artisan ] || [ ! -f composer.json ]; then
    red "This does not look like the application root."
    exit 1
fi

if [ ! -f .env ]; then
    red "No .env here. Run this from the directory that holds .env."
    exit 1
fi

# The APP_KEY check is here because this has broken the deploy twice: a
# duplicate APP_KEY= line means key:generate rewrites the first match while
# dotenv honours the last, so the app boots and then throws
# MissingAppKeyException on the first request. It is a one-line fix that is
# invisible until a request arrives, so it is worth refusing to move a live
# application over a .env that cannot boot.
# grep -c prints 0 AND exits non-zero on no match, so `|| echo 0` would make
# the count "0\n0". wc -l always succeeds.
APP_KEY_LINES="$(grep '^APP_KEY=' .env 2>/dev/null | wc -l | tr -d ' ')"
APP_KEY_VALUE="$(grep '^APP_KEY=' .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d '\r')"

if [ "$APP_KEY_LINES" != "1" ]; then
    red ".env has $APP_KEY_LINES APP_KEY lines, expected exactly 1."
    red "Delete the extras before moving:"
    red "    grep -n '^APP_KEY' .env"
    exit 1
fi

if [ -z "$APP_KEY_VALUE" ]; then
    red "APP_KEY is empty in .env. Generate one before moving:"
    red "    php artisan key:generate --force"
    exit 1
fi

if [ ! -f .htaccess ]; then
    red "No root .htaccess. Run 'git pull' first -- without it the document root"
    red "would be served with nothing refusing .env or storage/logs."
    exit 1
fi

if [ ! -d public_html ]; then
    red "No public_html here. This script expects the current layout: application"
    red "root above public_html, with public_html a symlink to public/."
    exit 1
fi

if [ ! -L public_html ]; then
    red "public_html is a real directory, not a symlink."
    red "It looks like this script has already been run. Nothing to do."
    exit 0
fi

TARGET="$(readlink public_html)"
case "$TARGET" in
    public|./public) : ;;
    *)
        red "public_html points somewhere unexpected: $TARGET"
        red "Refusing to guess."
        exit 1
        ;;
esac
green "current layout recognised (public_html -> $TARGET)"

# A safe point to come back to. Only .env is unique to this server; everything
# else is in git.
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$HOME/tramatch-env-backup-$STAMP"
cp .env "$BACKUP"
green "copied .env to $BACKUP"
echo

# -------------------------------------------------------------------- move --

bold "-- moving the application into public_html/"

# The symlink points at public/, which is about to move, so remove it first.
rm public_html

mkdir public_html

# public/ moves as public_html/public/ and stays a subdirectory -- that is the
# whole point of this layout. The root .htaccess forwards requests into it.
find . -mindepth 1 -maxdepth 1 ! -name 'public_html' -print0 |
    while IFS= read -r -d '' entry; do
        mv "$entry" public_html/
    done

green "moved $(find public_html -mindepth 1 -maxdepth 1 | wc -l | tr -d ' ') entries"
echo

# ----------------------------------------------------------------- verify --

bold "-- verifying"

FAIL=0

check() {
    if [ "$2" = "0" ]; then
        echo "  PASS  $1"
    else
        echo "  FAIL  $1"
        FAIL=1
    fi
}

[ -f public_html/artisan ];             check "artisan present" $?
[ -f public_html/.env ];                 check ".env present" $?
[ -f public_html/.htaccess ];            check "root .htaccess present" $?
[ -f public_html/public/index.php ];     check "public/index.php present" $?
[ -f public_html/vendor/autoload.php ];  check "vendor/autoload.php present" $?
[ -d public_html/storage ];              check "storage/ present" $?
[ -f public_html/.htaccess ];            check "deny rules in root .htaccess" $?
grep -q 'R=404' public_html/.htaccess;   check "root .htaccess has R=404 rules" $?

echo
if [ "$FAIL" = "0" ]; then
    green "layout looks right"
else
    red "something did not move correctly. Do not continue."
    echo "Rollback:"
    echo "  cd $APP_ROOT && cp $BACKUP .env && git status"
    exit 1
fi

# ------------------------------------------------------------------ finish --

bold "== Done =="
echo
echo "Now, from public_html:"
echo
echo "  cd $APP_ROOT/public_html"
echo "  php artisan optimize:clear      # cached config holds the OLD paths"
echo "  php artisan optimize"
echo
echo "Then verify -- these are the ones that matter:"
echo
echo "  curl -sI https://yourdomain.tld/ | head -1                    # 200"
echo "  curl -sI https://yourdomain.tld/.env | head -1                # 403/404"
echo "  curl -sI https://yourdomain.tld/artisan | head -1             # 403/404"
echo "  curl -sI https://yourdomain.tld/vendor/autoload.php | head -1 # 404"
echo "  curl -sI https://yourdomain.tld/storage/logs/laravel.log | head -1  # 404"
echo "  curl -sI https://yourdomain.tld/config/app.php | head -1      # 404"
echo
echo "The last four are new. If /vendor/autoload.php returns 200, the root"
echo ".htaccess is not being read and .env is one directory listing away."
echo
echo "From now on, deploy with:"
echo "  cd ~/domains/<domain>/public_html && git pull && bash deploy/deploy.sh"
echo
echo "Rollback, if you want the old layout back:"
echo "  cd $APP_ROOT/public_html"
echo "  find . -mindepth 1 -maxdepth 1 ! -name 'public_html' -print0 \\"
echo "    | while IFS= read -r -d '' e; do mv \"\$e\" ..; done"
echo "  rmdir public_html && ln -s public public_html"
echo "  cd .. && php artisan optimize:clear && php artisan optimize"
echo
echo ".env backup from before the move: $BACKUP"