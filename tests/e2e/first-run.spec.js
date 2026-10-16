import { test, expect } from './support/fixtures.js';
import { registerWithPreferences, likeCards, generateItinerary, enterEditMode } from './support/journey.js';

/**
 * The journey a real traveller takes, start to finish, in one test.
 *
 * Split across four tests it would be four tests that each re-walk the journey
 * to reach the state they care about, and the walk is where the bugs live. As
 * one test it is also the one thing worth running before a deploy: if this
 * passes, a stranger can get from registering to holding an itinerary.
 */
test('a new traveller goes from registering to an itinerary they can edit', async ({ page }) => {
    // 1. Register. Registration redirects to the dashboard, which redirects a
    //    traveller with no profile to preferences.
    await registerWithPreferences(page);

    // 2. The deck. Cards exist, so the "all caught up" empty state must not be
    //    what a traveller with nothing swiped is shown.
    await expect(page.locator('[data-swipe-card]').first()).toBeVisible();
    await expect(page.locator('[data-swipe-empty]')).toHaveCount(0);

    // 3. Like enough to have something to recommend.
    await likeCards(page, 5);

    await page.goto('/recommendations');
    await expect(page.locator('body')).not.toContainText('Your liked places will appear here');

    // 4. Generate.
    const itineraryUrl = await generateItinerary(page, { days: 2 });

    // SCOPED DELIBERATELY. `[data-day-card]` exists only inside the edit form,
    // which is `display: none` until the toggle is used, so a bare locator
    // matches a real element that is legitimately invisible and the assertion
    // fails against a perfectly healthy page. The read view has its own
    // container and its own "Day N" headings.
    await expect(page.locator('[data-editor-read]')).toBeVisible();
    await expect(page.locator('[data-editor-read]')).toContainText('Day 1');

    // 5. Open the editor. The toggle must actually reveal the form; a click
    //    that leaves the read view showing is the exact failure this suite
    //    exists to catch, because no HTTP test can see it.
    await enterEditMode(page);

    await expect(page.locator('[data-day-card]').first()).toBeVisible();

    const rows = page.locator('[data-editor-edit] [data-stop]');
    await expect(rows.first()).toBeVisible();

    // Every rendered row must expose the day it belongs to. Cross-day dragging
    // rewrites that field, so a row without it cannot change day — and that
    // shipped once already with the whole suite green.
    const withoutDay = await page
        .locator('[data-stop-list] [data-stop]')
        .evaluateAll((nodes) =>
            nodes.filter((node) => !node.querySelector('[data-field="day-id"]')).length,
        );

    expect(withoutDay, 'a stop row is missing [data-field="day-id"], so it cannot change day').toBe(0);

    // 6. Save and prove it persisted by reloading.
    await page.click('button[type="submit"]');
    await page.waitForURL(/\/itineraries\/\d+/);
    await page.reload();

    await expect(page.locator('[data-editor-read]')).toBeVisible();
    expect(page.url()).toBe(itineraryUrl);
});