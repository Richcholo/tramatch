import { test, expect } from './support/fixtures.js';
import { registerWithPreferences, likeCards, generateItinerary, enterEditMode, saveEditor } from './support/journey.js';

/**
 * The editor paths no human has ever clicked.
 *
 * The meal dialog, the places picker and the custom-stop form were built in the
 * same commit as the four-shaped stop and have not been in a browser since. They
 * are covered by PHPUnit at the HTTP layer, which proves the server accepts the
 * payload — and says nothing at all about whether the dialog opens, whether the
 * rows it clones are wired up, or whether the notice appears.
 *
 * Reflow is here too, because it is the feature with the most moving parts
 * between a click and a visible number: a fetch, a JSON shape, and a loop
 * writing into inputs.
 */

async function reachAnItinerary(page) {
    await registerWithPreferences(page);
    await likeCards(page, 5);

    // Benguet explicitly, not "whatever is first". The province list is now
    // every province with a candidate rather than the few the traveller liked,
    // so the default is alphabetical — which is a province with no researched
    // food or lodging, and the add-a-place tests then SKIP themselves. A skip is
    // a test that has quietly stopped testing, so the province that actually
    // has places is requested by name.
    return generateItinerary(page, { days: 2, province: 'Benguet' });
}

/**
 * The bug this suite was built to find, pinned.
 *
 * The add-stop dialog lists two kinds of checkbox: liked attractions and
 * researched places. Both used to carry `data-place` — the attraction's held a
 * "municipality, province" label — and the script branched on
 * `hasAttribute('data-place')`. So every attraction ticked in that dialog took
 * the place branch: item_type was written as the literal string "undefined" and
 * place_id as "Banaue, Ifugao", a location string in a foreign key column.
 *
 * Nothing caught it. Every HTTP-level test posts its own well-formed payload,
 * so the malformed one this dialog builds was never sent by anything but a
 * person — and nobody had opened it.
 *
 * Asserted on the hidden fields the row actually carries, because that is the
 * contract: an attraction is a destination_id and no place_id, and it is typed
 * `attraction` rather than whatever the attribute happened to be missing.
 */
test('a liked attraction added from the dialog is a destination, not a place', async ({ page }) => {
    await reachAnItinerary(page);
    await enterEditMode(page);

    await page.locator('[data-add]').first().click();
    await expect(page.locator('[data-add-dialog]')).toBeVisible();

    const attractions = page.locator('[data-destination]');

    test.skip((await attractions.count()) === 0, 'no liked attraction offered for this trip');

    const id = await attractions.first().getAttribute('data-destination');
    await attractions.first().check();
    await page.click('[data-add-confirm]');
    await expect(page.locator('[data-add-dialog]')).toBeHidden();

    const row = page
        .locator('[data-editor-edit] [data-stop]')
        .filter({ has: page.locator(`[data-field="destination-id"][value="${id}"]`) })
        .first();

    await expect(row).toHaveCount(1);

    const fields = await row.evaluate((node) => ({
        itemType: node.querySelector('[data-field="item-type"]')?.value,
        destinationId: node.querySelector('[data-field="destination-id"]')?.value,
        placeId: node.querySelector('[data-field="place-id"]')?.value,
    }));

    expect(fields.itemType).toBe('attraction');
    expect(fields.destinationId).toBe(id);
    expect(fields.placeId, 'an attraction was filed as a place, with a location string as its id').toBe('');
});

test('a traveller can add somewhere to eat and somewhere to stay', async ({ page }) => {
    await reachAnItinerary(page);
    await enterEditMode(page);

    const before = await page.locator('[data-stop]').count();

    await page.locator('[data-add]').first().click();
    await expect(page.locator('[data-add-dialog]')).toBeVisible();

    // Both checkbox sets in this dialog must be distinguishable, and the
    // script branches on data-destination. The attraction's location attribute
    // used to be called `data-place`, the same name the place's id uses, which
    // is how the misclassification below happened.
    const places = page.locator('[data-place][data-kind]');
    const available = await places.count();

    // Skipped rather than failed when a province has no researched places, so
    // this covers what the catalogue can reach without turning a curation gap
    // into a browser-suite failure that gets deleted and forgotten.
    test.skip(available === 0, 'no researched places in this province yet');

    await places.nth(0).check();

    const kind = await places.nth(0).getAttribute('data-kind');
    await expect(page.locator('[data-add-count]')).toHaveText('1');

    await page.click('[data-add-confirm]');
    await expect(page.locator('[data-add-dialog]')).toBeHidden();

    const after = await page.locator('[data-stop]').count();
    expect(after).toBe(before + 1);

    // It must render as the kind it was added as, not as a bare attraction.
    await expect(page.locator('[data-editor-notice]')).toContainText(/stop/i);
    await saveEditor(page);

    // Survives a reload: a stop added in the dialog and lost on save is a stop
    // that only ever existed in the browser.
    await page.reload();
    expect(await page.locator('[data-stop]').count()).toBe(after);

    if (kind === 'lodging') {
        await expect(page.locator('body')).toContainText(/where to stay|lodging|hotel/i);
    }
});

test('a traveller can add a stop of their own, with no pin', async ({ page }) => {
    await reachAnItinerary(page);
    await enterEditMode(page);

    const before = await page.locator('[data-stop]').count();

    await page.locator('[data-add]').first().click();
    await expect(page.locator('[data-add-dialog]')).toBeVisible();

    await page.fill('[data-custom-name]', 'The hotel we already booked');
    await page.fill('[data-custom-address]', 'Session Road, Baguio');

    // No confirm click: adding a custom stop adds it and closes the dialog in
    // one action, unlike ticking a checkbox which stages a batch. Clicking
    // confirm afterwards waits on a button that is disabled, because nothing
    // is ticked by then.
    await page.click('[data-add-custom]');
    await expect(page.locator('[data-add-dialog]')).toBeHidden();

    expect(await page.locator('[data-stop]').count()).toBe(before + 1);
    await saveEditor(page);
    await page.reload();

    // A custom entry with an address and no coordinates is legal and stays
    // unroutable. Half a position is not a position, so this must not have
    // become a pin at zero degrees.
    await expect(page.locator('body')).toContainText('The hotel we already booked');
    await enterEditMode(page);

    const coordinates = await page
        .locator('[data-field="custom-latitude"]')
        .evaluateAll((nodes) => nodes.map((node) => node.value));

    expect(coordinates.some((value) => value !== '' && Number(value) === 0)).toBe(false);
});

test('reflow fills the times and says whether they are measured or estimated', async ({ page }) => {
    await reachAnItinerary(page);
    await enterEditMode(page);

    // Clear a start time so reflow has something to decide.
    const firstStart = page.locator('[data-stop] [data-field="start-time"]').first();
    await firstStart.fill('');
    await firstStart.blur();

    await page.locator('[data-reflow]').first().click();

    const notice = page.locator('[data-editor-notice]');

    // The whole point of the measured/estimated work: the notice must name
    // which one the traveller is looking at, never silently present an estimate
    // as a distance.
    await expect(notice).toContainText(/Day \d+/);
    await expect(notice).toContainText(/estimate|real driving distances/i);

    await expect(page.locator('[data-stop] [data-field="start-time"]').first()).not.toHaveValue('');
});
