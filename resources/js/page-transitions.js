window.__tramatchPageTransitions = true;

(() => {
    const isHomePath = (pathname) => {
        return pathname.replace(/\/+$/, '') === '';
    };


    let navigating = false;

    const transitionKey = 'tramatch-page-enter';

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

        const currentIsHome = isHomePath(window.location.pathname);
        const targetIsHome = isHomePath(url.pathname);
     
        if (currentIsHome === targetIsHome) {
            clearPendingTransition();
            return;
        }



        if (navigating) {
            event.preventDefault();
            return;
        }

        event.preventDefault();
        navigating = true;

        setPendingTransition();

        const curtain = createCurtain();

        const currentOpacity = getComputedStyle(curtain).opacity;

        curtain.style.setProperty(
            '--curtain-start-opacity',
            currentOpacity
        );

        await animateCurtain(
            curtain,
            'page-curtain-entering'
        );

        window.location.assign(url.href);
    });

    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) {
            return;
        }

        navigating = false;
        clearPendingTransition();

        document
            .querySelector('[data-page-curtain]')
            ?.remove();
    });
})();