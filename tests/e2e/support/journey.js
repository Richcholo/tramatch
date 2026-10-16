import { expect } from '@playwright/test';

/**
 * Every selector here is one the markup or a script actually uses.
 *
 * They were read out of the Blade and the editor script rather than invented,
 * because a browser suite that fails on a wrong selector teaches you to ignore
 * it — and the whole value of this suite is that a failure means something.
 */

let sequence = 0;

/**
 * A unique address per registration.
 *
 * The suite shares one seeded database, so a fixed address would collide with
 * the previous run's account and the second run would silently test the first
 * run's traveller.
 */
export function travellerEmail() {
    sequence += 1;

    return `e2e.${Date.now()}.${sequence}@tramatch.test`;
}

/**
 * Register, land on preferences, and answer them.
 *
 * The interests are the ones a real traveller would plausibly pick and that the
 * economy catalogue is dense in — Nature carries 30 destinations and Cultural
 * 28, so the deck below is guaranteed to have cards. Romantic is deliberately
 * NOT used: it carries zero economy rows, which is exactly the empty-deck case
 * the product has to handle honestly, and it has its own test for that.
 */
export async function registerWithPreferences(page, { interests = ['Nature', 'Cultural'] } = {}) {
    await page.goto('/register');

    await page.fill('input[name="first_name"]', 'E2E');
    await page.fill('input[name="last_name"]', 'Traveller');
    await page.fill('input[name="email"]', travellerEmail());
    await page.fill('input[name="password"]', 'password1234');
    await page.fill('input[name="password_confirmation"]', 'password1234');
    await page.click('button[type="submit"]');

    // Registration redirects to the dashboard, which redirects a traveller with
    // no profile to preferences. Both hops are part of the journey under test.
    await page.waitForURL(/\/preferences/);

    // Budget is a select, not a radio, and the interest weights are selects
    // keyed by a tag primary key the test cannot know statically — each one
    // sits in a label carrying the tag's name. Matching on that label is the
    // only stable handle, and guessing at ids is how a browser suite starts
    // lying.
    await page.selectOption('select[name="budget_level"]', 'economy');
    await page.fill('input[name="group_size"]', '2');
    await page.fill('input[name="trip_duration_days"]', '2');

    for (const interest of interests) {
        await page
            .locator('label', { hasText: interest })
            .locator('select[name^="weights["]')
            .selectOption('3');
    }

    await page.click('button[type="submit"]');

    await page.waitForURL(/\/discover/);
}

/**
 * Like `count` cards, which is what gives the traveller a recommendation set.
 */
export async function likeCards(page, count = 5) {
    for (let i = 0; i < count; i += 1) {
        const like = page.locator('[data-swipe-action="liked"]');

        if ((await like.count()) === 0) {
            break;
        }

        await like.first().click();
        await page.waitForTimeout(150);
    }
}

/**
 * Generate an itinerary through the real form.
 *
 * The form is TWO SIBLING forms, not one: a GET that picks the province and
 * reloads with that province's destinations, then a POST that creates the trip.
 * They are deliberately not nested, which is this repo's standing rule — an
 * inner form's start tag is discarded by the HTML parser and its closing tag
 * ends up closing the outer one, silently removing every field from the payload.
 *
 * That structure is also why "select the province" is not enough to see any
 * destinations: the GET has to be submitted, and the button that does it is a
 * submit inside the *other* form's sibling.
 *
 * Returns the itinerary URL, because every editor test after this needs it and
 * re-walking the journey per test is what makes a suite nobody runs.
 */
export async function generateItinerary(page, { days = 2, province } = {}) {
    await page.goto('/itineraries/create');

    // The province list is built from the traveller's own recommendations, so it
    // is read rather than assumed.
    const options = await page.locator('select[name="area"] option').evaluateAll((nodes) =>
        nodes
            .map((node) => ({ value: node.value, label: node.textContent.trim() }))
            .filter((option) => option.value !== ''),
    );

    expect(options.length, 'no provinces offered, so the create form is a dead end').toBeGreaterThan(0);

    const chosen = province
        ? options.find((option) => option.label === province)
        : options[0];

    await page.selectOption('select[name="area"]', chosen.value);

    // Submit the GET form by its visible name. A bare `button[type="submit"]`
    // would be ambiguous here: both forms submit, and picking the wrong one
    // posts an incomplete trip form and lands on a validation error.
    await page.getByRole('button', { name: /load places/i }).click();
    await page.waitForLoadState('domcontentloaded');

    const boxes = page.locator('[data-destination-checkbox]');

    expect(
        await boxes.count(),
        `no destinations offered for ${chosen.label} after loading, so generation is impossible`,
    ).toBeGreaterThan(0);

    const wanted = Math.min(3, await boxes.count());

    for (let i = 0; i < wanted; i += 1) {
        await boxes.nth(i).check();
    }

    await page.fill('input[name="title"]', 'A trip worth taking');
    await page.fill('input[name="days"]', String(days));
    await page.getByRole('button', { name: /generate itinerary/i }).click();

    await page.waitForURL(/\/itineraries\/\d+/);

    return page.url();
}

/**
 * Put the page into edit mode.
 *
 * The toggle is the only way in, and a click on the wrong element leaves the
 * read view showing with the form silently mounted — which is the shape of the
 * bug this suite is here for.
 */
export async function enterEditMode(page) {
    await page.click('[data-editor-toggle]');
    await expect(page.locator('[data-editor-edit]')).toBeVisible();
}

/**
 * Save the editor.
 *
 * SCOPED TO THE EDITOR'S OWN FORM, ALWAYS. The itinerary page carries several
 * forms — mark active, delete, and one hidden remove-a-day form per day — so a
 * bare `button[type="submit"]` clicks whichever appears first in the document,
 * which on this page is not the save button. It produced a green test that
 * clicked "Mark active" and then asserted the page still rendered: the editor's
 * payload was never posted at all, and a meal added in the dialog appeared to
 * vanish.
 *
 * Same trap as the create form's "Load places" button, and the reason this is a
 * shared helper rather than a selector pasted into three specs.
 */
export async function saveEditor(page) {
    await page
        .locator('[data-editor-form]')
        .getByRole('button', { name: /save itinerary/i })
        .click();

    await page.waitForURL(/\/itineraries\/\d+/);
}