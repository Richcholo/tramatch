window.__tramatchPageTransitions = true;

(() => {
    /*
     * Three timings, and the third is the one that matters.
     *
     * LOADING_DELAY_MS is a delay on purpose. Feedback has to land immediately
     * or people click twice, but a full-screen takeover on every navigation is
     * irritating when the connection is fine. Under 350ms almost every
     * navigation finishes first and the overlay never shows at all; the
     * button's own :active state covers the gap, because that lands within a
     * frame.
     *
     * LOADING_FADE_MS is how long the fade-out takes.
     *
     * LOADING_MAX_MS is the guarantee, and it did not exist until a traveller
     * reported the overlay spinning forever. This overlay used to be dismissed
     * only by a successful page load, so any navigation that never completed
     * left it pinned over a page that was working perfectly, with no way out
     * but a reload: the browser's stop button, a beforeunload prompt dismissed
     * rather than accepted, a link some other handler swallowed. Nothing in
     * that sequence ever produces a page load, so nothing ever dismissed it.
     *
     * Past the cap the overlay goes away whatever is happening. A slow genuine
     * navigation then spends its last few seconds with no overlay, which costs
     * nothing; stranding the traveller costs everything.
     */
    const LOADING_DELAY_MS = 350;
    const LOADING_FADE_MS = 200;
    const LOADING_MAX_MS = 8000;

    const loader = () => document.querySelector('[data-navigation-loading]');

    let navigationLoadingTimer = null;
    let navigationMaxTimer = null;

    const clearNavigationLoading = () => {
        window.clearTimeout(navigationLoadingTimer);
        window.clearTimeout(navigationMaxTimer);

        const element = loader();

        if (!element) {
            return;
        }

        // Hide immediately to trigger the transition, then remove it from the
        // layer stack once the fade has had time to run. Adding `hidden` in the
        // same tick would collapse it before the 200ms ever plays.
        element.classList.add('tm-loader--leaving');
        window.setTimeout(() => {
            element.classList.add('hidden');
            element.classList.remove('tm-loader--leaving');
        }, LOADING_FADE_MS);
    };

    const scheduleNavigationLoading = () => {
        window.clearTimeout(navigationLoadingTimer);

        navigationLoadingTimer = window.setTimeout(() => {
            const element = loader();

            if (!element) {
                return;
            }

            // --leaving has to go as well as hidden. clearNavigationLoading adds
            // it for the duration of the fade-out, and showing the overlay again
            // while it is still set would render the visible state at opacity 0,
            // which reads as a blank page rather than a loader.
            element.classList.remove('tm-loader--leaving');
            element.classList.remove('hidden');

            window.clearTimeout(navigationMaxTimer);
            navigationMaxTimer = window.setTimeout(
                clearNavigationLoading,
                LOADING_MAX_MS
            );
        }, LOADING_DELAY_MS);
    };

    /*
     * Forms get the same treatment. Login, registration, the password-reset
     * form and saving an itinerary all round-trip to the server, and without
     * this the submit button just sits there while the request is in flight --
     * on a weak connection that looks identical to a dead page.
     *
     * Cross-origin forms are excluded so the overlay is never pinned over a
     * page this script cannot dismiss it from.
     */
    const isSameOriginForm = (form) => {
        const action = form.getAttribute('action');

        if (!action) {
            return true;
        }

        try {
            return new URL(action, window.location.origin).origin === window.location.origin;
        } catch {
            return false;
        }
    };

    const BUSY_LABEL = 'Working...';

    /**
     * `event.submitter` rather than a querySelector: the admin bulk rows put
     * their buttons *outside* the form and point them at it with the HTML5
     * `form=` attribute, so a search inside the form element finds nothing.
     */
    const busySubmitter = (event) => {
        const submitter = event.submitter ?? event.target.querySelector('[type="submit"]');

        if (!(submitter instanceof HTMLElement)) {
            return null;
        }

        // Only swap the text on a button that is plain text. Reassigning
        // textContent on one holding an <svg> would delete the icon, and the
        // label cannot be put back exactly as it was afterwards.
        if (submitter.children.length > 0) {
            submitter.setAttribute('aria-busy', 'true');
            return null;
        }

        if (submitter.dataset.tmSubmittedLabel === undefined) {
            submitter.dataset.tmSubmittedLabel = submitter.textContent ?? '';
            submitter.textContent = BUSY_LABEL;
        }

        submitter.setAttribute('aria-busy', 'true');

        return submitter;
    };

    const restoreSubmitters = () => {
        for (const submitter of document.querySelectorAll('[aria-busy="true"]')) {
            submitter.removeAttribute('aria-busy');

            if (submitter.dataset.tmSubmittedLabel !== undefined) {
                submitter.textContent = submitter.dataset.tmSubmittedLabel;
                delete submitter.dataset.tmSubmittedLabel;
            }
        }
    };

    /*
     * Bubble phase on the document, not capture.
     *
     * Capture on the document runs before *every* handler in the tree, so a
     * form that some later handler cancels would be left labelled "Working..."
     * forever with no navigation to clear it. Bubbling to the document happens
     * last, so `defaultPrevented` already reflects any handler that declined
     * the submission.
     */
    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (
            event.defaultPrevented ||
            !(form instanceof HTMLFormElement) ||
            !isSameOriginForm(form)
        ) {
            return;
        }

        busySubmitter(event);
        scheduleNavigationLoading();
    });

    const prepareIntroOverlay = () => {
        const overlay = document.getElementById('intro-overlay');

        if (!overlay || overlay.dataset.introPlaying === 'true') {
            return;
        }

        try {
            if (sessionStorage.getItem('introPlayed') === '1') {
                overlay.remove();
                return;
            }
        } catch {
            // sessionStorage unavailable; home.js plays the intro as normal.
        }

        overlay.style.opacity = '1';
    };

    /*
     * A beforeunload prompt is the one navigation this script cannot wait out,
     * because the traveller may dismiss it and stay exactly where they are.
     * itinerary-editor.js registers its own handler for unsaved changes, so
     * this is a reachable path rather than a hypothetical one.
     *
     * Clearing here is also correct when the prompt is accepted: the page is
     * about to go, and the next page's overlay starts hidden regardless.
     */
    window.addEventListener('beforeunload', () => {
        clearNavigationLoading();
        restoreSubmitters();
    });

    prepareIntroOverlay();

    document.addEventListener('click', (event) => {
        if (
            event.defaultPrevented ||
            event.button !== 0 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey
        ) {
            return;
        }

        const target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        const link = target.closest('a[href]');

        if (!link || link.hasAttribute('download')) {
            return;
        }

        const linkTarget = (
            link.getAttribute('target') ??
            document.querySelector('base[target]')?.getAttribute('target') ??
            ''
        ).toLowerCase();

        if (linkTarget && linkTarget !== '_self') {
            return;
        }

        const href = link.getAttribute('href')?.trim();

        if (
            !href ||
            href.startsWith('#') ||
            href.startsWith('mailto:') ||
            href.startsWith('tel:')
        ) {
            return;
        }

        let url;

        try {
            url = new URL(href, document.baseURI);
        } catch {
            return;
        }

        if (
            !['http:', 'https:'].includes(url.protocol) ||
            url.origin !== window.location.origin
        ) {
            return;
        }

        if (
            url.pathname === window.location.pathname &&
            url.search === window.location.search
        ) {
            return;
        }

        scheduleNavigationLoading();
    });

    /*
     * The submit listener above covers forms, so there is no second one here.
     * An earlier version had a bare `submit` -> scheduleNavigationLoading()
     * listener alongside the link handler; it duplicated the timer call and,
     * having no origin check, would pin the overlay over a form posting to
     * another host -- a page this script can never dismiss it from.
     */

    /*
     * One pageshow handler, deliberately. There were two -- one here for the
     * overlay and one further up for the form buttons -- and both fired on the
     * same event, so the bfcache branch had to add `hidden` directly to beat
     * the other one's 200ms fade.
     */
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) {
            // A normal arrival: fade the overlay off over the page the traveller
            // is actually looking at.
            clearNavigationLoading();

            return;
        }

        /*
         * Restored from the back/forward cache. The page was frozen mid-flight,
         * so a "Working..." button and possibly a visible overlay were carried
         * into the cache with it. Without this the page is perfectly usable and
         * looks permanently stuck.
         */
        clearNavigationLoading();
        restoreSubmitters();
    });
})();
