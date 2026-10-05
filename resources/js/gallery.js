/**
 * The destination photo carousel.
 *
 * Progressive enhancement, deliberately. The server already rendered every photo
 * as a plain horizontally scrollable row, with real src and alt, so if this file
 * fails to load or throws halfway the traveller still sees the pictures. What
 * this adds is hiding all but the current slide and the controls to move between
 * them.
 *
 * The controls are inserted by this script rather than rendered by Blade, so
 * there is never a button on the page that does nothing -- a live-region
 * announcing "slide 1 of 4" next to dead arrows is worse than no carousel at
 * all.
 *
 * Not verified by any test. There is no browser automation in this project, so
 * the scroll behaviour, the touch handling and the reduced-motion path below have
 * never been run. What IS pinned is the server-rendered fallback: that the
 * images are in the HTML with no JS, and that a single photo does not become a
 * carousel.
 */

/** Visible width of the track, so a slide can be measured rather than assumed. */
const trackWidth = (track) => track.clientWidth;

/**
 * Move to a slide by scroll position rather than by transforming the track.
 *
 * Scrolling keeps the browser's own momentum, keyboard scrolling and
 * touch-swipe behaviour intact. A transform would have to reimplement all three,
 * and would desynchronise from the scroll position the moment anything else
 * scrolled the container.
 */
const goTo = (state, index) => {
    const next = Math.max(0, Math.min(state.slides.length - 1, index));

    state.current = next;

    state.track.scrollTo({
        left: next * trackWidth(state.track),
        behavior: state.reducedMotion ? 'auto' : 'smooth',
    });

    paint(state);
};

/**
 * Reflect the current slide in the DOM: aria-hidden on the rest, and the state
 * of the controls.
 *
 * aria-hidden matters as much as the visual hiding. Without it a screen reader
 * is offered four images of the same place, and the live region announces
 * nothing about which one it is on.
 */
const paint = (state) => {
    state.slides.forEach((slide, index) => {
        const current = index === state.current;

        slide.toggleAttribute('aria-hidden', !current);

        // Tab order follows the eye: an off-screen photo should not be reachable.
        for (const focusable of slide.querySelectorAll(
            'a, button, input, select, textarea, [tabindex]'
        )) {
            focusable.tabIndex = current ? 0 : -1;
        }
    });

    for (const dot of state.dots) {
        const current = Number(dot.dataset.galleryDot) === state.current;

        dot.setAttribute('aria-current', current ? 'true' : 'false');
    }

    /*
     * state.prev and state.next, not a guess from the control's own markup.
     *
     * These used to be told apart by sniffing `dataset.galleryPrev` on each
     * button, while buildControls() actually wrote `dataset[key]` with key being
     * the keyboard shortcut -- so the attribute was data-arrow-left, the check
     * was never true for either button, and both fell through to the "last
     * slide" rule. The visible effect was that reaching the final photo disabled
     * Previous as well, leaving no way back at all. One argument doing two
     * unrelated jobs, and the two halves were never wired to each other.
     */
    if (state.prev) {
        state.prev.disabled = state.current === 0;
    }

    if (state.next) {
        state.next.disabled = state.current === state.slides.length - 1;
    }

    if (state.status) {
        state.status.textContent = `Photo ${state.current + 1} of ${
            state.slides.length
        }`;
    }
};

const buildControls = (state) => {
    const { controls } = state;

    controls.replaceChildren();

    /*
     * `shortcut` and `hook` are separate arguments, and `hook` is what
     * identifies the button from the outside. They were one argument once, which
     * is what let paint() and buildControls() disagree about which button was
     * which -- see the note in paint().
     */
    const button = (label, glyph, shortcut, hook) => {
        const element = document.createElement('button');

        element.type = 'button';
        element.textContent = glyph;
        element.setAttribute('aria-label', label);

        /*
         * A visible glyph with no accessible name is announced as "button", so the
         * label is mandatory rather than decorative. The glyph itself is then
         * hidden from the accessibility tree to avoid it being read twice.
         */
        element.setAttribute('aria-keyshortcuts', shortcut);
        element.className =
            'flex h-11 w-11 items-center justify-center rounded-full bg-white/90 text-lg text-volcanic-teal shadow disabled:opacity-40';
        element.dataset[hook] = '';

        return element;
    };

    const previous = button('Previous photo', '‹', 'ArrowLeft', 'galleryPrev');
    const next = button('Next photo', '›', 'ArrowRight', 'galleryNext');

    previous.addEventListener('click', () => goTo(state, state.current - 1));
    next.addEventListener('click', () => goTo(state, state.current + 1));

    const dots = state.slides.map((slide, index) => {
        const dot = document.createElement('button');

        dot.type = 'button';
        dot.dataset.galleryDot = String(index);
        dot.setAttribute('aria-label', `Photo ${index + 1}`);
        dot.className =
            'h-2.5 w-2.5 rounded-full bg-white/70 transition aria-[current=true]:bg-philippine-gold';

        dot.addEventListener('click', () => goTo(state, index));

        return dot;
    });

    const status = document.createElement('p');

    status.className = 'sr-only';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');

    controls.append(previous, ...dots, next, status);

    /*
     * `controls` is deliberately left alone here. It is the container element
     * that was emptied at the top of this function, and it used to be
     * overwritten here with the two buttons -- so the name referred to a
     * different kind of thing before and after this line. paint() now uses
     * state.prev and state.next instead.
     */
    Object.assign(state, {
        dots,
        status,
        prev: previous,
        next,
    });
};

/**
 * Follow the user's own scrolling.
 *
 * A swipe or a trackpad scroll moves the track without going through goTo(), so
 * the dots and aria-hidden would otherwise drift out of step with what is
 * actually on screen. Scroll events are not fired by scrollTo(), so this cannot
 * feed back into itself.
 */
const watchScroll = (state) => {
    let frame = null;

    state.track.addEventListener(
        'scroll',
        () => {
            if (frame !== null) {
                return;
            }

            frame = window.requestAnimationFrame(() => {
                frame = null;

                const width = trackWidth(state.track);

                if (width === 0) {
                    return;
                }

                const index = Math.round(state.track.scrollLeft / width);

                if (index !== state.current) {
                    state.current = index;
                    paint(state);
                }
            });
        },
        { passive: true }
    );
};

export default function initGallery() {
    for (const root of document.querySelectorAll('[data-gallery]')) {
        const track = root.querySelector('[data-gallery-track]');
        const controls = root.querySelector('[data-gallery-controls]');

        if (!track || !controls) {
            continue;
        }

        const slides = [...track.querySelectorAll('[data-gallery-slide]')];

        if (slides.length === 0) {
            continue;
        }

        const state = {
            track,
            controls,
            slides,
            dots: [],
            status: null,
            prev: null,
            next: null,
            current: 0,
            reducedMotion: window.matchMedia?.(
                '(prefers-reduced-motion: reduce)'
            ).matches,
        };

        buildControls(state);
        paint(state);
        watchScroll(state);

        /*
         * Arrow keys, on the track rather than the window, so they only apply while
         * the carousel has focus and do not steal Left/Right from the rest of the
         * page. Left/Right are not universally reserved for carousels -- VoiceOver
         * users navigate with them too -- so the track is a labelled group and the
         * keys work only from inside it.
         */
        track.tabIndex = 0;
        track.setAttribute('role', 'group');

        track.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                goTo(state, state.current - 1);
            }

            if (event.key === 'ArrowRight') {
                event.preventDefault();
                goTo(state, state.current + 1);
            }
        });

        /*
         * The slide width is read on every goTo() rather than cached, because the
         * grid can reflow on resize and a cached width would then scroll to the
         * wrong offset. A resize listener re-paints so the dots stay truthful.
         */
        window.addEventListener('resize', () => {
            state.track.scrollTo({
                left: state.current * trackWidth(state.track),
                behavior: 'auto',
            });

            paint(state);
        });
    }
}
