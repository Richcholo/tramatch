window.__tramatchPageTransitions = true;

(() => {
    const isHomePath = (pathname) => {
        return pathname.replace(/\/+$/, '') === '';
    };


    let navigating = false;
    let navigationLoadingTimer = null;

    const transitionKey = 'tramatch-page-enter';

    /*
     * 350ms before the overlay appears, 200ms to fade it back out.
     *
     * The delay is the point. Feedback has to land immediately or people click
     * twice, but a full-screen takeover on every navigation is irritating when
     * the connection is fine. Under 350ms almost every navigation finishes
     * first and the overlay never shows at all; the button's own :active state
     * covers the gap, because that lands within a frame.
     */
    const LOADING_DELAY_MS = 350;
    const LOADING_FADE_MS = 200;

    const loader = () => document.querySelector('[data-navigation-loading]');

    const scheduleNavigationLoading = () => {
        window.clearTimeout(navigationLoadingTimer);

        navigationLoadingTimer = window.setTimeout(() => {
            loader()?.classList.remove('hidden');
        }, LOADING_DELAY_MS);
    };

    const clearNavigationLoading = () => {
        window.clearTimeout(navigationLoadingTimer);

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

    const authPaths = [
        '/login',
        '/register',
    ];

    const isAuthPath = (pathname) => {
        return authPaths.includes(pathname);
    };

    const hasIntroOverlay = () => {
        return document.querySelector('#intro-overlay') !== null;
    };

    const isHomePage = () => {
        return document.querySelector('#hero') !== null;
    };

    const setPendingTransition = () => {
        try {
            sessionStorage.setItem(transitionKey, '1');
        } catch {
            return;
        }
    };

    const consumePendingTransition = () => {
        try {
            const pending = sessionStorage.getItem(transitionKey) === '1';

            sessionStorage.removeItem(transitionKey);

            return pending;
        } catch {
            return false;
        }
    };

    const clearPendingTransition = () => {
        try {
            sessionStorage.removeItem(transitionKey);
        } catch {
            return;
        }
    };

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
            
        }

        overlay.style.opacity = '1';
    };


    const createCurtain = () => {
        let curtain = document.querySelector('[data-page-curtain]');

        if (!curtain) {
            curtain = document.createElement('div');
            curtain.dataset.pageCurtain = '';
            curtain.setAttribute('aria-hidden', 'true');
            document.body.appendChild(curtain);
        }

        return curtain;
    };

    const animateCurtain = async (curtain, className) => {
        curtain.classList.remove(
            'page-curtain-entering',
            'page-curtain-leaving'
        );

        curtain.classList.add(className);

        const animations = curtain.getAnimations();

        await Promise.all(
            animations.map((animation) =>
                animation.finished.catch(() => {})
            )
        );
    };

   const playPageEnter = async () => {
        if (navigating) {
            return;
        }

        const pendingTransition = consumePendingTransition();


        if (hasIntroOverlay()) {
            return;
        }

  
        if (!pendingTransition) {
            return;
        }

        const curtain = createCurtain();

        await animateCurtain(
            curtain,
            'page-curtain-leaving'
        );

        if (!navigating) {
            curtain.remove();
        }
    };



    prepareIntroOverlay();

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            playPageEnter,
            { once: true }
        );
    } else {
        void playPageEnter();
    }

    document.addEventListener('click', async (event) => {
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
     * the other one's 200ms fade. On a restore an instant hide is the correct
     * behaviour anyway, so that ordering requirement is gone.
     */
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) {
            // A normal arrival: fade the overlay off over the page the traveller
            // is actually looking at.
            clearNavigationLoading();

            return;
        }

        /*
         * Restored from the back/forward cache. The unload left a "Working..."
         * button and a full-screen overlay behind, so without this the page is
         * perfectly usable and looks permanently stuck.
         */
        navigating = false;
        clearPendingTransition();

        loader()?.classList.add('hidden');
        restoreSubmitters();

        document
            .querySelector('[data-page-curtain]')
            ?.remove();
    });
})();