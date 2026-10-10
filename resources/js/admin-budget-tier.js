/**
 * Budget tier auto-fill and mismatch warning, for the admin destination form.
 *
 * `budget_level` is an exact match against the traveller's travel profile, not
 * a ranking: SwipeDeckService, RecommendationService and ItineraryGenerator all
 * filter `where('budget_level', $profile->budget_level)`. Filing a destination
 * under the wrong tier therefore does not rank it lower, it makes it invisible
 * to everyone in the other two tiers. That is worth a helper.
 *
 * Two behaviours, deliberately not one:
 *
 *   - While the admin has not touched the tier select, the estimated cost drives
 *     it. Typing a cost and getting the tier right by hand is busywork.
 *   - Once they HAVE touched it, it stops moving. A free museum genuinely can be
 *     'economy' and a guided tour can be 'premium', so an override is a real
 *     decision rather than a mistake -- and silently reverting it would be worse
 *     than saying so.
 *
 * A disagreement is surfaced either way, including on first load, because an
 * existing row may already be filed inconsistently and the admin should be told
 * without having to change anything to find out.
 *
 * The boundaries live on App\Models\Destination::BUDGET_TIERS and are rendered
 * into the guideline next to this control. This file deliberately does NOT carry
 * its own copy of the numbers -- it reads them from the rendered labels, because
 * a second copy would be free to drift from the guideline printed above the
 * field and nothing would notice.
 */

/** Parse "₱501 – ₱999" / "Free – ₱500" / "₱1,000 and up" into a numeric bound. */
const parseBound = (text, fallback) => {
    if (!text) {
        return fallback;
    }

    const digits = text.replace(/[^0-9]/g, '');

    return digits === '' ? fallback : Number.parseInt(digits, 10);
};

export default function initBudgetTier() {
    for (const root of document.querySelectorAll('[data-budget-tier]')) {
        const select = root.querySelector('[data-budget-tier-select]');
        const warning = root.querySelector('[data-budget-tier-warning]');
        const costField =
            root.closest('form')?.querySelector('[data-budget-tier-cost]') ?? null;

        if (!select || !costField) {
            continue;
        }

        /*
         * Read the boundaries back out of the guideline rows rather than
         * hardcoding them. Each row is <dt>label</dt><dd>range</dd>, and the dd
         * holds the top of the band: "Free - P500" -> 500, "P1,000 and up" ->
         * 1000, which is where the open-ended tier starts.
         */
        const rows = [...root.querySelectorAll('dl > div')];

        if (rows.length === 0) {
            continue;
        }

        /*
         * Each row's <dt> is the human label ("Mid"), which is NOT the option
         * value ("mid-range"). Matching on the label and then assigning it
         * straight to select.value would set an option that does not exist and
         * silently leave the select unchanged. So the value is looked up from
         * the select itself, by label.
         */
        const optionValueForLabel = (label) => {
            const wanted = label.trim().toLowerCase();

            for (const option of select.options) {
                if (option.textContent.trim().toLowerCase() === wanted) {
                    return option.value;
                }
            }

            return null;
        };

        const bands = rows
            .map((row) => ({
                value: optionValueForLabel(row.querySelector('dt')?.textContent ?? ''),
                max: parseBound(
                    row.querySelector('dd')?.textContent ?? '',
                    Number.POSITIVE_INFINITY
                ),
            }))
            .filter((band) => band.value !== null);

        /*
         * Ordered by the band ceiling, because the form renders them in tier
         * order and the last one has no upper bound at all. Sorting by number is
         * what makes an open-ended tier sort last instead of first.
         */
        bands.sort((a, b) => a.max - b.max);

        const tierFor = (raw) => {
            const cost = Number.parseFloat(raw);

            const amount = Number.isFinite(cost) && cost > 0 ? cost : 0;

            return bands.find((band) => amount <= band.max)?.value ?? select.value;
        };

        const labelFor = (value) =>
            select.querySelector(`option[value="${value}"]`)?.textContent.trim() ??
            value;

        let touched = false;

        const review = () => {
            const derived = tierFor(costField.value);
            const chosen = select.value;

            if (!touched && derived && derived !== chosen) {
                // Nothing to preserve yet, so follow the cost.
                select.value = derived;
            }

            const settled = select.value;

            if (!warning) {
                return;
            }

            if (derived && settled !== derived) {
                warning.textContent =
                    `Estimated cost of ₱${Number.parseFloat(costField.value || '0').toLocaleString()} ` +
                    `reads as ${labelFor(derived)}, but this is filed as ${labelFor(settled)}. ` +
                    'Travellers in the other tiers will not see this destination.';
                warning.hidden = false;

                return;
            }

            warning.hidden = true;
            warning.textContent = '';
        };

        select.addEventListener('change', () => {
            touched = true;
            review();
        });

        costField.addEventListener('input', review);

        // On load too: an already-inconsistent row should say so unprompted.
        review();
    }
}
