/**
 * The destinations carousel.
 *
 * Registered as an Alpine component so both `/destinations` and every
 * `/destinations/{slug}` get identical behaviour from one definition, and so it
 * loads through app.js rather than as a per-page Vite entry. The previous
 * arrangement used a separate `@vite(['resources/js/stage.js'])` entry, which is
 * exactly the shape that produced a 0.00 kB bundle once already: a standalone
 * entry whose export nobody imported got tree-shaken to nothing and the carousel
 * shipped dead with every test green.
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
 * NOT COVERED BY ANY TEST. There is no browser automation in this project, so the
 * choreography, the swipe threshold and the autoplay timing below have never been
 * run. See AGENTS.md.
 */

/** How far a pointer must travel before a drag counts as a swipe. */
const SWIPE_THRESHOLD = 56;

/** How long after an interaction ends before autoplay resumes, in ms. */
const RESUME_DELAY = 2000;

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

        /** Set once the visitor has driven the carousel themselves. */
        engaged: false,

        paused: false,
        announcement: '',
        panelData: [],
        reduce: false,

        timer: null,
        resumeTimer: null,

        /**
         * Read the initial state out of the server-rendered attributes.
         *
         * `data-stage-active` is the index derived from the URL on the server, so
         * a cold load already lands on the right panel and a shared link replays
         * correctly. Seeding from the DOM rather than from PHP again keeps one
         * source of truth for the starting index.
         */
        init() {
            const root = this.$el;

            this.count = Number(root.dataset.stageCount || 0);
            this.index = Number(root.dataset.stageActive || 0);

            this.interval = Number(root.dataset.stageInterval || 3600);
            this.autoplay = root.dataset.stageAutoplay === '1';

            this.reduce =
                window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ??
                false;

            this.panels = [...root.querySelectorAll('[data-stage-panel]')];

            /*
             * `regionLabel`, matching the field name the caption binds to
             * (`activeSlide().regionLabel`) and the CarouselSlide accessor it comes
             * from. It was `region` here while the caption read `regionLabel`, so
             * the chip rendered its server-rendered text on load and then went
             * EMPTY the moment Alpine took over -- a mismatch visible only once the
             * script ran, and only as a missing word.
             */
            this.panelData = this.panels.map((panel) => ({
                name: panel.dataset.stageName ?? '',
                regionLabel: panel.dataset.stageRegion ?? '',
            }));

            // The caption follows the index, and it is the only thing a screen
            // reader hears on a change -- the panels carry no alt text on purpose.
            this.announce();

            if (this.autoplay && !this.reduce) {
                this.start(1500);
            }

            document.addEventListener('visibilitychange', () => {
                document.hidden ? this.pause('hidden') : this.resume('hidden');
            });

            this.bindSwipe();
        },

        /**
         * The signed offset of panel `i` from the active one.
         *
         * Clamped by the caller (the CSS and the `data-far` binding), not here, so
         * the same number drives position and reachability consistently.
         */
        offsetOf(i) {
            return i - this.index;
        },

        /** The slide the caption describes. */
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

            // Touching the carousel at all stops the timer permanently. A visitor
            // who has driven it themselves did not ask to be driven.
            this.engaged = true;

            this.index = target;
            this.announce();

            this.busy = true;

            setTimeout(() => {
                this.busy = false;
            }, this.reduce ? SETTLE_MS / 3 : SETTLE_MS);

            // Re-arm autoplay after the slide, unless they are driving it.
            if (this.autoplay && !this.reduce) {
                this.resume('manual');
            }
        },

        next() {
            this.go(this.index + 1);
        },

        prev() {
            this.go(this.index - 1);
        },

        /**
         * Jump to the first slide, without a transition.
         *
         * Only used at the end of the collection. Autoplay stops at the last slide
         * because there is no wrap-around -- wrapping sends a panel from far-left
         * to near-right in one step, which is a visible pop. Rather than let the
         * loop die silently, this cuts back to the start with the transition
         * suppressed for one frame.
         */
        restart() {
            this.panels.forEach((panel) => {
                panel.style.transition = 'none';
            });

            this.index = 0;
            this.announce();

            // Read layout once so the suppressed transition is committed before it
            // is restored. Without this the browser coalesces both writes and the
            // panels visibly slide the whole way back.
            void this.$el.offsetWidth;

            this.panels.forEach((panel) => {
                panel.style.transition = '';
            });
        },

        start(delay = 0) {
            this.clear();

            this.timer = setTimeout(() => {
                // At the end, cut back rather than advance past the last slide.
                if (this.index >= this.count - 1) {
                    this.restart();
                }

                this.next();

                this.timer = setInterval(() => {
                    if (this.index >= this.count - 1) {
                        this.restart();

                        return;
                    }

                    this.next();
                }, this.interval);
            }, delay);
        },

        pause() {
            this.clear();

            this.paused = true;
        },

        /**
         * Resume after a delay, and never after the visitor has taken over.
         *
         * `engaged` is checked here rather than by cancelling the timer at each
         * call site, because it is the condition that matters: once someone has
         * pressed an arrow, no hover, focus or visibility event should put the
         * carousel back under automatic control.
         */
        resume() {
            if (!this.autoplay || this.reduce || this.engaged) {
                return;
            }

            this.paused = false;

            this.clear();

            this.resumeTimer = setTimeout(() => {
                this.start(0);
            }, RESUME_DELAY);
        },

        toggle() {
            if (this.paused) {
                this.paused = false;

                this.clear();
                this.start(RESUME_DELAY);

                return;
            }

            this.pause();
        },

        clear() {
            if (this.timer) {
                clearTimeout(this.timer);
                clearInterval(this.timer);
                this.timer = null;
            }

            if (this.resumeTimer) {
                clearTimeout(this.resumeTimer);
                this.resumeTimer = null;
            }
        },

        /**
         * Swipe, via pointer events so touch, pen and mouse share one path.
         *
         * The threshold is deliberate: a swipe is a committed gesture, and a
         * 10px wobble while reaching for the dots should not flip the slide.
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