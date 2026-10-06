/**
 * The destinations fan on a destination page.
 *
 * Registered as an Alpine component in app.js rather than loaded as a per-page
 * Vite entry. A standalone entry is what produced a 0.00 kB bundle once already:
 * an export nobody imported got tree-shaken to nothing and the carousel shipped
 * dead with every test green. Registering it after `Alpine.start()` has the same
 * effect more quietly -- `x-data="heroCarousel()"` never resolves, the panels
 * render, and the script never attaches.
 *
 * WHAT ACTUALLY MOVES THE FAN
 * ============================
 *
 * Not this file. This file changes ONE integer (`index`). Every panel then
 * recomputes its own signed offset from that integer, Alpine rebinds `data-offset`
 * on it, and the CSS rules keyed on `data-offset` supply position, size, rotation,
 * stacking order and opacity. The browser transitions between the old attribute
 * value and the new one.
 *
 * The first version had this inverted and the carousel looked frozen: the panels'
 * offsets were written once by PHP into `data-stage-offset` and never touched
 * again, so `index` moved but nothing in the geometry did. The panels merely
 * changed which one was highlighted. If you are ever tempted to set an offset
 * from PHP and expect it to hold, do not -- it is the starting state only.
 *
 * All slides stay in the DOM and only their offsets change, which is what makes an
 * advance a re-index rather than a re-layout. No image reloads, so there is
 * something for the transition to interpolate.
 *
 * THERE IS NO AUTOPLAY. It ran on `/destinations` and was dropped when the
 * carousel moved to the destination page alone: this is a page someone has
 * arrived at, the copy beneath the fan is all about one place, and a timer that
 * keeps shifting the fan under someone reading it is worse than no timer. With no
 * autoplay there is nothing to pause, so the pause control went too -- a dead
 * control is worse than an absent one.
 *
 * NOT COVERED BY ANY TEST. There is no browser automation in this project, so the
 * choreography, the swipe threshold and the reduced-motion path below have never
 * been run. See AGENTS.md.
 */

/** How far a pointer must travel before a drag counts as a swipe. */
const SWIPE_THRESHOLD = 56;

/**
 * A new index is not accepted until this many ms have passed.
 *
 * Slightly longer than the 620ms CSS transition, so a second click during a slide
 * queues onto the current one rather than snapping the transition mid-flight.
 */
const SETTLE_MS = 660;

export default function heroCarousel() {
    return {
        count: 0,
        index: 0,
        busy: false,
        announcement: '',
        panelData: [],
        reduce: false,

        panels: [],

        /**
         * Read the initial state out of the server-rendered attributes.
         *
         * `data-stage-active` is the index derived from the URL on the server, so a
         * cold load already lands on the right panel and a shared link replays
         * correctly. Seeding from the DOM rather than from PHP again keeps one
         * source of truth for the starting index.
         */
        init() {
            const root = this.$el;

            this.count = Number(root.dataset.stageCount || 0);
            this.index = Number(root.dataset.stageActive || 0);

            this.reduce =
                window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ??
                false;

            this.panels = [...root.querySelectorAll('[data-stage-panel]')];

            /*
             * The panel names the live region announces on a change. The caption is
             * deliberately NOT read here: it is this page's h1 and stays put, so
             * advancing the fan must not make the screen reader claim the page is
             * now about somewhere else.
             */
            this.panelData = this.panels.map((panel) => ({
                name: panel.dataset.stageName ?? '',
                regionLabel: panel.dataset.stageRegion ?? '',
            }));

            this.announce();

            this.bindSwipe();
        },

        /**
         * The signed offset of panel `i` from the active one.
         *
         * Not clamped here: the CSS and the `data-far` binding both use the raw
         * value, so clamping in only one place is how they would disagree.
         */
        offsetOf(i) {
            return i - this.index;
        },

        /** The panel the fan is currently centred on. */
        activeSlide() {
            return this.panelData[this.index] ?? { name: '', regionLabel: '' };
        },

        announce() {
            const slide = this.activeSlide();

            this.announcement = slide.name
                ? `${slide.name}, ${slide.regionLabel}`
                : '';
        },

        go(to) {
            if (this.busy || this.count === 0) {
                return;
            }

            const target = Math.max(0, Math.min(to, this.count - 1));

            if (target === this.index) {
                return;
            }

            this.index = target;
            this.announce();

            this.busy = true;

            setTimeout(() => {
                this.busy = false;
            }, this.reduce ? SETTLE_MS / 3 : SETTLE_MS);
        },

        next() {
            this.go(this.index + 1);
        },

        prev() {
            this.go(this.index - 1);
        },

        /*
         * Hover and focus handling, kept because the markup binds them -- but
         * deliberately inert now that there is no autoplay to interrupt. Removing
         * them from the template would be tidier; they are here so the bindings do
         * not throw "is not a function" if the markup is reused as-is.
         */
        pause() {},
        resume() {},

        /**
         * Swipe, via pointer events so touch, pen and mouse share one path.
         *
         * The threshold is deliberate: a swipe is a committed gesture, and a 10px
         * wobble while reaching for the dots should not flip the slide.
         *
         * `pointercancel` is handled alongside `pointerup` because a gesture
         * interrupted by a scroll never fires `up`; without it `startX` stays
         * latched and the next stray move counts as a swipe.
         */
        bindSwipe() {
            let startX = null;

            this.$el.addEventListener('pointerdown', (event) => {
                if (event.pointerType === 'mouse' && event.button !== 0) {
                    return;
                }

                startX = event.clientX;
            });

            const end = (event) => {
                if (startX === null) {
                    return;
                }

                const distance = event.clientX - startX;

                startX = null;

                if (Math.abs(distance) < SWIPE_THRESHOLD) {
                    return;
                }

                distance < 0 ? this.next() : this.prev();
            };

            this.$el.addEventListener('pointerup', end);
            this.$el.addEventListener('pointercancel', () => {
                startX = null;
            });
        },
    };
}