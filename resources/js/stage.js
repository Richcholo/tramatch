/**
 * The destinations stage.
 *
 * An Alpine component, registered in app.js and NOT a separate Vite entry.
 * Two reasons, both learned the hard way on the version of this stage that was
 * built and then taken back out:
 *
 *   - registering it after `Alpine.start()` leaves `x-data` unevaluated. The
 *     panels render from the server and the script never attaches, which looks
 *     exactly like a frozen stage and reports nothing anywhere.
 *   - a standalone entry whose export nobody imports gets tree-shaken. That
 *     produced a 0.00 kB bundle once, and the stage shipped without its script
 *     while the build reported success.
 *
 * WHAT THIS SCRIPT ACTUALLY DOES IS SMALL, and the size is the point. It changes
 * ONE integer -- `index` -- and three things follow from that:
 *
 *   1. Every panel's `data-offset` is recomputed as `i - index`, and the CSS in
 *      app.css derives position, size, rotation, stacking and dimming from that
 *      attribute. The browser's transition between the old value and the new one
 *      IS the animation. This was inverted once: the offsets were written by PHP
 *      and never touched again, so advancing changed which panel was "active" and
 *      never where the panels were. State updated; layout did not; no test failed.
 *   2. The caption's bindings re-evaluate against the new slide.
 *   3. The backdrop, the image window and the two numbers are pushed into step.
 *
 * EVERYTHING ELSE IS DERIVED. There is no second copy of "which slide is active"
 * to fall out of step with `data-offset`.
 */

/**
 * How long the panels take to travel, in ms.
 *
 * MUST MATCH the 620ms in `.tm-fan-panel`'s transition in app.css, because this
 * is the delay before the caption is allowed back in. Too short and the caption
 * cross-fades over panels that are still moving, which reads as two things
 * happening at once rather than as one thing settling.
 */
const PANEL_MS = 620;

/** How long the two numbers take to count to their new value. */
const COUNT_MS = 420;

const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

export default function destinationStage() {
    return {
        index: 0,
        count: 0,
        interval: 7000,
        reach: 2,

        /**
         * FALSE while a change is in flight.
         *
         * One flag drives two things: the caption's fade-and-rise, and the
         * pager's fade-out. They are the same event seen from two places, so they
         * share a state rather than each running their own timer and being able to
         * disagree about when the fan settled.
         */
        settled: true,

        paused: false,

        /**
         * WHY autoplay is held, as reasons rather than a boolean.
         *
         * Hover and focus can overlap -- a keyboard user's focus lands inside the
         * stage and their pointer is over it too -- so a single `hovering` flag
         * makes whichever event fires last the only one that counts, and the
         * slideshow resumes under a keyboard user who has not moved the pointer.
         * A set means both have to be released.
         */
        held: [],

        announcement: '',

        slides: [],
        matchScores: {},

        panels: [],
        backdrops: [],
        shownBackdrop: null,

        reducedMotion: false,
        timers: { settle: null, autoplay: null, count: null },

        init() {
            const payload = this.readPayload();

            this.slides = payload.slides;
            this.matchScores = payload.matchScores;

            this.count = this.slides.length;
            this.interval = Number(this.$el.dataset.stageInterval) || this.interval;
            this.reach = Number(this.$el.dataset.stageReach) || this.reach;
            this.index = clamp(Number(this.$el.dataset.stageActive) || 0, 0, Math.max(0, this.count - 1));

            this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            this.panels = [...this.$el.querySelectorAll('[data-stage-panel]')];
            this.backdrops = [...this.$el.querySelectorAll('[data-stage-backdrop]')];
            this.shownBackdrop = this.backdrops.find((layer) => layer.classList.contains('is-shown')) || null;

            this.announce();
            this.trackVisibility();
            this.scheduleAutoplay();
        },

        /**
         * The one island, read once.
         *
         * `JSON.parse` in a `try` because an unreadable island must leave the
         * server-rendered stage exactly as it is -- every panel already has its
         * starting offset, the caption already has its text and the dots already
         * have their `aria-current`. A stage with no script is a working stage.
         */
        readPayload() {
            try {
                const parsed = JSON.parse(this.$el.querySelector('[data-stage-data]').textContent);

                if (parsed && Array.isArray(parsed.slides)) {
                    return { slides: parsed.slides, matchScores: parsed.matchScores || {} };
                }
            } catch (error) {
                console.warn('[stage] slide payload unreadable, leaving the stage as rendered', error);
            }

            return { slides: [], matchScores: {} };
        },

        // --- what is on stage ------------------------------------------------

        active() {
            return this.slides[this.index] || null;
        },

        /**
         * A slide's SIGNED distance from the active one. 0 is the centre panel.
         *
         * `offsetOf`, not `offset`: the panel bindings call it with a literal index
         * and read no argument back, which keeps the attribute they write a pure
         * function of `index`.
         */
        offsetOf(i) {
            return i - this.index;
        },

        place() {
            const slide = this.active();

            return slide ? `${slide.municipality} · ${slide.province}` : '';
        },

        /**
         * Whether THIS viewer has a match score for the destination on stage.
         *
         * The map is empty for a guest, and a signed-in user's map only holds
         * destinations they liked at a matching budget -- so most slides have no
         * score and the label is hidden rather than filled with a guess.
         */
        hasMatch() {
            const slide = this.active();

            return Boolean(slide && this.matchScores[slide.destinationId] !== undefined);
        },

        matchValue() {
            const slide = this.active();

            return slide ? this.matchScores[slide.destinationId] : null;
        },

        // --- moving ----------------------------------------------------------

        go(next) {
            const target = clamp(next, 0, Math.max(0, this.count - 1));

            // A no-op on the current slide, so clicking the active dot does not
            // fade the caption out and back in for nothing.
            if (target === this.index) {
                return;
            }

            this.index = target;

            this.settled = false;

            this.syncBackdrop();
            this.syncImages();
            this.syncNumbers();
            this.syncMetrics();

            this.announce();
            this.scheduleSettle();
            this.scheduleAutoplay();
        },

        next() {
            // Clamped, not wrapped: wrapping sends a panel from far-left to
            // near-right in one step, which is a visible pop. The fan thins out at
            // each end the way a carousel should.
            this.go(this.index + 1);
        },

        prev() {
            this.go(this.index - 1);
        },

        scheduleSettle() {
            window.clearTimeout(this.timers.settle);

            this.timers.settle = window.setTimeout(() => {
                this.settled = true;
            }, this.reducedMotion ? 0 : PANEL_MS);
        },

        // --- keeping the layers in step --------------------------------------

        /**
         * Cross-fade the backdrop to the active photograph.
         *
         * Two layers, because `src` cannot be cross-faded -- it can only be
         * replaced, and a hard swap of a full-bleed blurred photograph behind
         * every panel is the single most obvious way this stage could look broken.
         *
         * Returns early when the incoming layer already holds the right
         * photograph, which is the case when the fan moves between two panels of
         * the SAME destination and nothing behind them has changed.
         */
        syncBackdrop() {
            const slide = this.active();

            if (!slide || !slide.imageUrl || this.backdrops.length < 2) {
                return;
            }

            const incoming = this.backdrops.find((layer) => layer !== this.shownBackdrop);

            if (incoming.getAttribute('src') === slide.imageUrl) {
                return;
            }

            incoming.setAttribute('src', slide.imageUrl);
            incoming.classList.add('is-shown');

            if (this.shownBackdrop) {
                this.shownBackdrop.classList.remove('is-shown');
            }

            this.shownBackdrop = incoming;
        },

        /**
         * Give a panel its photograph when it is about to be seen, and take it
         * back when it is not.
         *
         * A destination set is 65 destinations of up to four photographs each. With
         * every `src` rendered, the browser would fetch the entire catalogue's
         * photography on the first page load. The window is one slide WIDER than
         * the fan's reach on purpose: a panel that only got its `src` at the moment
         * it became visible would decode from nothing.
         */
        syncImages() {
            this.panels.forEach((panel, i) => {
                const image = panel.querySelector('img');

                if (!image) {
                    return;
                }

                const src = image.dataset.src;

                if (!src) {
                    return;
                }

                const wanted = Math.abs(this.offsetOf(i)) <= this.reach + 1;

                if (wanted && image.getAttribute('src') !== src) {
                    image.setAttribute('src', src);
                } else if (!wanted && image.hasAttribute('src')) {
                    image.removeAttribute('src');
                }
            });
        },

        /**
         * Count the two animated numbers to their new values.
         *
         * Tabular numerals on the label so the width does not move while the digits
         * change -- an animated number that reflows the row it sits in is worse
         * than one that does not animate at all.
         */
        syncNumbers() {
            const cost = this.active() ? this.active().estimatedCost : null;

            this.countUp(
                this.$el.querySelector('[data-stage-number="match"]'),
                this.matchValue(),
                (value) => Math.round(value).toString()
            );

            this.countUp(
                this.$el.querySelector('[data-stage-number="cost"]'),
                cost,
                (value) => `${this.costPrefix()}₱${Math.round(value).toLocaleString('en-PH')}`
            );
        },

        costPrefix() {
            const element = this.$el.querySelector('[data-stage-number="cost"]');

            return (element && element.dataset.stagePrefix) || '';
        },

        countUp(element, to, format) {
            window.clearTimeout(this.timers.count);

            if (!element || to === null || to === undefined || !Number.isFinite(Number(to))) {
                return;
            }

            const target = Number(to);
            const from = Number(element.dataset.stageValue || 0);

            element.dataset.stageValue = String(target);

            if (this.reducedMotion || from === target) {
                element.textContent = format(target);

                return;
            }

            const started = performance.now();
            const step = (now) => {
                const progress = Math.min(1, (now - started) / COUNT_MS);
                // Ease-out, so the digits settle rather than stopping dead.
                const eased = 1 - Math.pow(1 - progress, 3);

                element.textContent = format(from + (target - from) * eased);

                if (progress < 1) {
                    this.timers.count = requestAnimationFrame(step);
                }
            };

            this.timers.count = requestAnimationFrame(step);
        },

        /**
         * Show and hide the match micro-label, together with the hairline dot that
         * FOLLOWS it.
         *
         * Both are hidden together, in one place, because a dot left behind by a
         * hidden metric makes the row read "· EST. COST ₱1,250" -- a leading
         * separator is the visible symptom of the two being unlinked.
         *
         * `element.hidden`, never a class. These elements are `display: flex`
         * children of a flex row, and an author-level `display` beats the
         * browser's own `[hidden] { display: none }` -- so a Tailwind `hidden`
         * class here would be silently ignored. There is no such class.
         */
        syncMetrics() {
            const visible = this.hasMatch();

            const metric = this.$el.querySelector('[data-stage-metric="match"]');
            const separator = this.$el.querySelector('[data-stage-separator="match"]');

            if (metric) {
                metric.hidden = !visible;
            }

            if (separator) {
                separator.hidden = !visible;
            }
        },

        announce() {
            const slide = this.active();

            this.announcement = slide
                ? `Photograph ${this.index + 1} of ${this.count}. ${slide.name}, ${slide.province}.`
                : '';
        },

        // --- autoplay --------------------------------------------------------

        /**
         * Whether the timer should be running, and why not if it should not.
         *
         * Four independent reasons, all of which have to be true to stop it. The
         * single-slide case matters most: a one-photograph stage that advances to
         * itself every seven seconds is a stage that fades its own caption out and
         * back in forever, for no reason.
         */
        autoplayable() {
            return this.count > 1 && !this.paused && this.held.length === 0 && !document.hidden;
        },

        scheduleAutoplay() {
            window.clearTimeout(this.timers.autoplay);

            if (!this.autoplayable()) {
                return;
            }

            this.timers.autoplay = window.setTimeout(() => this.next(), this.interval);
        },

        toggle() {
            this.paused = !this.paused;
            this.scheduleAutoplay();
        },

        hold(reason) {
            if (!this.held.includes(reason)) {
                this.held.push(reason);
            }

            window.clearTimeout(this.timers.autoplay);
        },

        release(reason) {
            this.held = this.held.filter((held) => held !== reason);
            this.scheduleAutoplay();
        },

        /**
         * A tab put in the background is not a visitor watching the stage, and a
         * seven-second timer that fires into a hidden tab has burnt most of its
         * dwell before anyone sees it. Both directions are handled so returning to
         * the tab starts a full dwell rather than immediately advancing.
         */
        trackVisibility() {
            document.addEventListener('visibilitychange', () => this.scheduleAutoplay());
        },
    };
}
