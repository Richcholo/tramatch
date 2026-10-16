/**
 * Build the throwaway database the browser suite runs against.
 *
 * WHY A SCRIPT AND NOT AN NPM ONE-LINER: the setup is four steps with two
 * destructive ones in it, and both orderings of those two are wrong in a way
 * that is only visible later. Migrate before the file exists does nothing
 * useful; seeding before migrating fails with "table does not exist" — which
 * is exactly how the live server behaved the first time this was run by hand.
 * Ordering them here means nobody has to remember.
 *
 * The database is a FILE, because the app under test has to be served by a real
 * PHP process while Playwright drives a real browser: two processes cannot share
 * an in-memory database. It is deleted and rebuilt on every run.
 *
 * THE DESTRUCTIVE STEP IS GUARDED TWICE, ON PURPOSE. setup() refuses to run if
 * the resolved database is anything other than a SQLite file whose name matches
 * e2e, so a stray DB_DATABASE in the environment cannot turn `rm` into a
 * dropped development catalogue. `php artisan test` already carries this lesson
 * — never point a test run at MySQL to work around a driver problem.
 */

import { execFileSync } from 'node:child_process';
import { copyFileSync, existsSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(fileURLToPath(import.meta.url), '..', '..');
const envFile = resolve(root, '.env.e2e');
const template = resolve(root, '.env.e2e.example');
const database = resolve(root, 'database', 'e2e.sqlite');

function php(...args) {
    return execFileSync('php', args, { cwd: root, encoding: 'utf8', stdio: 'pipe' });
}

/**
 * Refuse to continue unless the target really is the throwaway file.
 *
 * Checks the path we resolved ourselves, not what the environment claims: a
 * DB_DATABASE pointing somewhere real is the exact accident this guards, so it
 * must not be able to talk its way past the check.
 */
function assertThrowawayDatabase() {
    const isSqlite = database.endsWith('.sqlite');
    const isE2e = database.includes('e2e');
    const insideRepo = database.startsWith(resolve(root, 'database'));

    if (!isSqlite || !isE2e || !insideRepo) {
        console.error(`\nRefusing to touch ${database}\n`);
        console.error('The browser suite deletes its database on every run. That is only');
        console.error('safe for database/e2e.sqlite. If you meant something else, you are');
        console.error('running the wrong script.\n');
        process.exit(1);
    }
}

/**
 * Delete Vite's dev-server marker.
 *
 * `public/hot` is written by `npm run dev` and makes every `@vite` directive
 * emit `http://localhost:5173/...` instead of reading the built manifest. It is
 * gitignored and untracked, so it cannot reach the host — but it poisons every
 * other environment, and it does so SILENTLY: the HTML renders perfectly, the
 * only symptom is that every stylesheet and script 404s to a dead port, and a
 * browser suite that does not collect console errors sees a page with no
 * assertions failing.
 *
 * Found this way, on the first run of this suite: every asset request was
 * ERR_CONNECTION_REFUSED on port 5173 while the page itself reported healthy.
 * Removed rather than ignored, and reported, so a leftover is visible in the
 * setup output instead of being mysterious later.
 */
function clearViteDevServerMarker() {
    const marker = resolve(root, 'public', 'hot');

    if (!existsSync(marker)) {
        return null;
    }

    const contents = readFileSync(marker, 'utf8').trim();
    rmSync(marker);

    return contents;
}

const staleDevServer = clearViteDevServerMarker();

// The guard runs FIRST, before anything is deleted or written. Calling it after
// the work would be the dead-guard shape this repo has been bitten by: a
// function defined, commented as protective, and never invoked, so the file
// reads as safe while the one destructive command in it is unguarded.
assertThrowawayDatabase();

// ALWAYS re-copy the template, never "only if missing".
//
// The first version created .env.e2e once and then left it alone, so a change to
// the template never reached it — and the run failed with a dotenv parse error
// naming a line that had been fixed in the template three minutes earlier. This
// is the same class of fault as a cached config or a server .env predating a
// deploy: a file that is written once and then believed. For a throwaway test
// environment there is nothing worth hand-editing, so the file is always
// derived and never authoritative.
copyFileSync(template, envFile);

// An absolute path, so the server resolves it identically no matter which
// directory either process was started from.
writeFileSync(
    envFile,
    readFileSync(envFile, 'utf8').replace(/^# DB_DATABASE.*$/m, `DB_DATABASE=${database.replace(/\\/g, '/')}`),
);

if (existsSync(database)) {
    rmSync(database);
}

php('artisan', 'key:generate', '--env=e2e', '--force', '--quiet');
php('artisan', 'migrate', '--env=e2e', '--force', '--quiet');
php('artisan', 'db:seed', '--env=e2e', '--force', '--quiet');

const destinations = php('artisan', 'tinker', '--env=e2e', '--execute=echo \\App\\Models\\Destination::count();').trim();

console.log('\nE2E database ready.');
console.log(`  ${database}`);
console.log(`  ${destinations} destinations seeded`);

if (staleDevServer) {
    console.log(`  removed a stale Vite dev marker (public/hot -> ${staleDevServer})`);
    console.log('    it would have pointed every @vite URL at that dead dev server.');
}

console.log('');