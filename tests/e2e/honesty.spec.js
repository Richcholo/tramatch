import { test, expect } from './support/fixtures.js';
import { registerWithPreferences } from './support/journey.js';

/**
 * The honesty guarantees, checked where a traveller actually reads them.
 *
 * These are the claims this project makes about itself: never present an
 * estimate as a measurement, never tell someone they are finished when they
 * have done nothing, and never fatal a page for a logged-out visitor. Each is
 * pinned in PHPUnit too, but PHPUnit cannot see what a person is shown.
 */

/**
 * The empty deck a valid profile can legitimately produce.
 *
 * Romantic carries zero economy destinations, which was verified against the
 * catalogue CSV on 2026-10-16: 75 economy rows, none of them tagged Romantic.
 * A traveller who ticks it is not doing anything wrong.
 *
 * MARKED AS EXPECTED TO FAIL, because the defect is real and unfixed: the page
 * tells that traveller "You are all caught up" having swiped nothing at all,
 * and offers Reset, which cannot fix a tag mismatch. It was found by walking
 * this path rather than by any test.
 *
 * `test.fail()` is the honest way to hold that. Deleting the assertion, or
 * relaxing it to something the current copy happens to satisfy, would take a
 * known lie out of the record and leave nothing to notice when it is fixed.
 * Playwright inverts this the day the copy becomes honest: the test starts
 * failing because it was *supposed* to fail and now passes, which is the
 * signal to delete the marker.
 */
test('a traveller whose interests match nothing is told the truth about it', async ({ page }) => {
    test.fail(true, 'empty deck still claims the traveller is "all caught up" — Phase 1 fix');
    await registerWithPreferences(page, { interests: ['Romantic'] });

    await expect(page.locator('[data-swipe-empty]')).toBeVisible();

    // The specific false statement, asserted as the string it is.
    await expect(page.locator('[data-swipe-empty]')).not.toContainText('You are all caught up');

    // And there has to be a way out, not just an accurate complaint.
    const links = page.locator('[data-swipe-empty] a');

    expect(await links.count(), 'an empty deck with no way out of it').toBeGreaterThan(0);
});

test('a traveller is never told they are caught up before swiping anything', async ({ page }) => {
    await registerWithPreferences(page);

    // Either there are cards, or the honest empty state explains why. What must
    // not happen is the false claim.
    await expect(page.locator('body')).not.toContainText('You are all caught up');
});

/**
 * The guest surface.
 *
 * The app layout called `auth()->user()->isAdmin()` unguarded for the admin nav
 * and fataled every public page for logged-out visitors. Guest access to the
 * catalogue is the app's front door, so it gets a test rather than an
 * assumption.
 */
test('a logged-out visitor can browse destinations without an error', async ({ page }) => {
    const destinations = await page.request.get('/destinations');
    expect(destinations.status()).toBe(200);

    await page.goto('/destinations');
    await expect(page.locator('body')).not.toContainText('Whoops');

    // Into an actual destination page, which renders the photo stage — the
    // heaviest piece of JavaScript on the site.
    const link = page.locator('a[href*="/destinations/"]').first();
    await link.click();
    await expect(page.locator('body')).not.toContainText('Whoops');
    expect(page.url()).toMatch(/\/destinations\/.+/);

    // An archived destination is not reachable by URL.
    const missing = await page.request.get('/destinations/not-a-real-place');
    expect(missing.status()).toBe(404);
});

test('the create form offers a way forward when there is nothing to plan yet', async ({ page }) => {
    await registerWithPreferences(page, { interests: ['Romantic'] });

    // No likes means no recommendations, which used to mean a province select
    // with a single placeholder option and no explanation.
    const response = await page.goto('/itineraries/create');
    expect(response.status()).toBe(200);

    const options = await page
        .locator('select[name="area"] option')
        .evaluateAll((nodes) => nodes.filter((node) => node.value !== '').length);

    if (options === 0) {
        // Then the page owes the traveller an explanation and a way to get
        // likes, rather than a dead dropdown.
        await expect(page.locator('body')).toContainText(/discover|like/i);
    }
});