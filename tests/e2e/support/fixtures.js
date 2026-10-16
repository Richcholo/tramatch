import { test as base, expect } from '@playwright/test';

/**
 * Noise that is not our code failing.
 *
 * A favicon 404 on a dev server is not a defect worth failing a run over, and
 * training every suite to ignore console errors trains people to ignore them.
 * The list is deliberately tiny and specific; a blanket "ignore warnings" would
 * have swallowed every bug this suite exists to catch.
 */
const IGNORED = [/favicon/i];

/**
 * A page that logs an error has failed, even when every assertion passed.
 *
 * Three features in this codebase shipped with the server-rendered markup
 * entirely correct and the JavaScript dead — an Alpine expression that never
 * evaluated, a listener bound to rows that did not exist yet, a Tailwind
 * `hidden` class fighting a `hidden` attribute. None of them threw an HTTP
 * error, none of them failed a single test, and all three were found by a human
 * clicking. A console error is the only signal a browser has for the class of
 * fault that a status-code assertion cannot see.
 *
 * Collected for the whole test and asserted once at the end, so the failure
 * message lists every problem rather than only the first.
 */
export const test = base.extend({
    page: async ({ page }, use) => {
        const problems = [];

        page.on('console', (message) => {
            if (message.type() !== 'error') {
                return;
            }

            const text = message.text();

            if (IGNORED.some((pattern) => pattern.test(text))) {
                return;
            }

            problems.push(`console.error: ${text}`);
        });

        page.on('pageerror', (error) => {
            problems.push(`uncaught: ${error.message}`);
        });

        await use(page);

        expect(
            problems,
            `the browser reported errors, which no HTTP-level assertion can see:\n${problems.join('\n')}`,
        ).toEqual([]);
    },
});

export { expect };