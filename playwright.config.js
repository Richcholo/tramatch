import { defineConfig } from '@playwright/test';

/**
 * The browser safety net for a codebase whose server-rendered markup has been
 * correct while the feature was dead in the browser three separate times.
 *
 * Why this exists and why it is not CI: the failures this catches are invisible
 * to every HTTP-level test. An element carrying Tailwind's `hidden` class while
 * a script toggled the `hidden` attribute. Rendered rows missing the
 * `[data-field="day-id"]` attribute the drag code rewrites. Rows cloned from a
 * template that were never wired for dragging. All three shipped, all three
 * passed the suite, and all three were found by a human clicking.
 *
 * So: no CI, no scheduled runs, no process to maintain. A command you run
 * before you deploy, covering the four journeys that matter. That is a
 * different and more useful thing than a pipeline nobody ever runs.
 *
 * `npm run e2e:setup` first — it builds the throwaway SQLite database and seeds
 * the real catalogue, because a browser test against an empty catalogue tests
 * nothing.
 */
const PORT = 8123;
const PHP_PORT = 8130;
const baseURL = `http://127.0.0.1:${PORT}`;

export default defineConfig({
    testDir: './tests/e2e',

    // A journey here crosses a dozen pages and can legitimately wait on the
    // seeding server's first request.
    timeout: 60_000,
    expect: { timeout: 10_000 },

    // The suite shares one seeded database. Parallel workers would each need
    // their own copy, and this runs a few times a week rather than on every
    // commit — so the isolation is not worth the setup it would cost.
    fullyParallel: false,
    workers: 1,

    reporter: process.env.CI ? [['line']] : [['list']],

    use: {
        baseURL,
        // Kept on failure only. A trace per passing run is noise nobody reads.
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
    },

    // Two servers, started in order: the app, then the proxy in front of it.
    //
    // The proxy is not optional. PHP's built-in server is single-threaded and
    // PHP_CLI_SERVER_WORKERS needs fork(), so it is ignored on Windows and a
    // browser's parallel connections come back ERR_CONNECTION_REFUSED. Every
    // page here loads a stylesheet, a script and images, so without the proxy
    // every test renders an unstyled page and — because the HTML request
    // itself succeeds — mostly still passes. See scripts/e2e-proxy.mjs.
    webServer: [
        {
            // --env=e2e loads .env.e2e, which points at the throwaway SQLite
            // file. Without the flag this serves the real .env, i.e. your
            // development database, and the suite would be one click from
            // dropping the catalogue.
            command: `php artisan serve --env=e2e --port=${PHP_PORT}`,
            url: `http://127.0.0.1:${PHP_PORT}`,
            reuseExistingServer: false,
            timeout: 60_000,
            stdout: 'ignore',
            stderr: 'pipe',
        },
        {
            command: `node scripts/e2e-proxy.mjs ${PORT} ${PHP_PORT}`,
            url: baseURL,
            reuseExistingServer: false,
            timeout: 60_000,
        },
    ],
});