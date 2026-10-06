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
         * Only FOCUS holds it now. Hover used to as well, and that
         * was the reported "it doesn't auto-play": the stage is the
         * whole opening screen, so a desktop visitor's pointer is over
         * it for exactly as long as they are reading it, and a timer
         * held for the entire dwell is a timer that never fires while
         * anyone is watching. The pause control is the WCAG 2.2.2
         * mechanism for stopping content that moves without being
         * asked -- it is always in the accessibility tree and always
         * visible -- and that is where the decision belongs, not
         * under the visitor's cursor by accident.
         *
         * A set rather than a boolean, so that reasons can in
         * principle overlap and each is released on its own.
         */
        held: [],
        held: [],

        announcement: '',

        slides: [],
        matchScores: {},

        /**
         * The offsets the server rendered, read once in `init()` from
         * `data-stage-start`. See `offsetOf()` for why they exist separately.
         */
        starts: [],

        /**
         * TRUE when the payload island could not be read, or does not describe the
         * panels that were actually rendered.
         *
         * A distinct state rather than an error to swallow, because it changes what
         * the component is ALLOWED to do: an unreadable payload must leave the stage
         * exactly as the server rendered it, so autoplay does not start, no control
         * moves the fan, and `offsetOf()` answers from `starts`. Everything keeps
         * working and nothing shows the wrong photograph.
         */
        unreadable: false,

        panels: [],
        backdrops: [],
        shownBackdrop: null,
        fan: null,

        /**
         * Drag state.
         *
         * `dragging` is only true past the dead zone, so it means "a gesture is
         * underway", not "a pointer is down on the stage" -- otherwise a tap on a
         * panel would light up the dragging class and change the cursor.
         *
         * `dragFrom` is the null sentinel for "not dragging at all", because 0 is a
         * legitimate `clientX`.
         */
        dragging: false,
        dragFrom: null,
        dragDelta: 0,
        suppressClick: false,

        reducedMotion: false,
        timers: { settle: null, autoplay: null, count: null },

init() {
            const payload = this.readPayload();

            this.slides = payload.slides;
            this.matchScores = payload.matchScores;

            this.count = this.slides.length;
            this.interval = Number(this.$el.dataset.stageInterval) || this.interval;
            this.reach = Number(this.$el.dataset.stageReach) || this.reach;

            this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            this.panels = [...this.$el.querySelectorAll('[data-stage-panel]')];
            this.backdrops = [...this.$el.querySelectorAll('[data-stage-backdrop]')];
            this.shownBackdrop = this.backdrops.find((layer) => layer.classList.contains('is-shown')) || null;

            /*
             * THE SERVER'S OWN OFFSETS, captured before anything can rewrite them.
             *
             * `data-offset` is the LIVE attribute -- Alpine overwrites it on every
             * index change -- so it cannot be read back as the starting state. Every
             * panel therefore also carries `data-stage-start`, written once by PHP
             * and never bound, and this reads those.
             *
             * It exists so that a stage which CANNOT READ ITS PAYLOAD leaves the
             * stage exactly as the server rendered it, instead of re-indexing itself
             * onto whatever happens to be panel zero. That failure was not
             * theoretical: the payload island used to sit OUTSIDE the `x-data`
             * element, so `readPayload()`'s `this.$el.querySelector` never found
             * it, `count` came back 0, `index` clamped to 0, and the page showed the
             * first destination's photographs under this destination's backdrop --
             * with autoplay dead, every dot inert and no motion at all, and nothing
             * reported anywhere.
             *
             * With this captured, an unreadable payload is a DEGRADED stage rather
             * than a WRONG one: the fan stays where the server put it, the dots and
             * the pause control are inert because there is nothing to move through,
             * and the photograph in front of you is the right one.
             */
            this.starts = this.panels.map((panel) => Number(panel.dataset.stageStart));
            this.unreadable = this.slides.length === 0 || this.starts.length !== this.slides.length;

            /*
             * Which panel the server put in the middle. Usually the same answer as
             * `data-stage-active`, and asked separately because it is the only one
             * available when the payload is unusable.
             */
            const centred = Math.max(0, this.starts.indexOf(0));

            this.index = this.unreadable
                ? centred
                : clamp(Number(this.$el.dataset.stageActive) || 0, 0, Math.max(0, this.count - 1));

            /*
             * PUSH THE RENDERED STATE INTO STEP RATHER THAN TRUSTING IT.
             *
             * The server already renders the preload window for the opening index,
             * so these calls look redundant and are not. The `index` set above is
             * CLAMPED to the real slide count: if the cached payload is shorter than
             * the rendered markup -- a stale cache entry against a catalogue that has
             * since grown, which is exactly what a ten-minute cache window invites --
             * then `index` lands somewhere other than `data-stage-active`, and the fan
             * opens on a panel that was never given a photograph.
             *
             * `syncNumbers()` and `syncMetrics()` are here for the same reason and
             * also settle the guest case: the match metric starts hidden and the two
             * numbers start on the opening slide's values, and both are re-asserted
             * from the same `active()` the caption reads.
             *
             * Skipped entirely when the payload was unusable, because every one of
             * them reads `active()` -- which is `slides[index]`, and there are no
             * slides. Re-asserting an empty state over a correct render is the one
             * thing this whole block exists to prevent.
             */
            if (!this.unreadable) {
                this.syncImages();
                this.syncNumbers();
                this.syncMetrics();
            }

            this.bindDrag();

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
         *
         * THE SERVER'S OFFSET, WHEN THERE IS NO PAYLOAD. `unreadable` is set once,
         * in `init()`, when the island could not be read or does not match the
         * rendered panels. Returning `starts[i]` then freezes the fan exactly where
         * the server put it, instead of letting `index` fall to 0 and re-indexing
         * every panel onto the first one in the DOM.
         *
         * That distinction is the whole point: a degraded stage and a WRONG stage
         * look identical from outside, and only one of them is showing you a
         * photograph of somewhere else.
         */
        offsetOf(i) {
            if (this.unreadable) {
                return Number.isFinite(this.starts[i]) ? this.starts[i] : 0;
            }

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
            // Nothing to move through, and moving would move it to the WRONG place.
            if (this.unreadable) {
                return;
            }

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

        // --- dragging ---------------------------------------------------------

        /**
         * How far the fan must travel before a release commits to an advance.
         *
         * A DISTANCE, not a velocity, deliberately. Velocity needs a time sample,
         * and a sample taken from a slow careful drag and from a fast flick across
         * the same physical distance should not be able to disagree about what
         * happened -- which they do constantly, because the flick has one pointer
         * event and the drag has thirty. So a fast flick is judged on the ground it
         * covered, like everything else.
         *
         * A FIFTH OF THE PANEL IN FRONT OF YOU, floored and capped.
         *
         * THE THRESHOLD IS MEASURED OFF THE PANEL, NOT THE FAN. It used
         * to be 15% of the fan's own width, which was defensible while
         * the fan was capped at 68rem and stopped being so the moment
         * the stage was allowed to fill the page: at a 1500px fan that
         * is 225px of travel before a release commits, which is exactly
         * the "hard to grab" the stage was reported as. A threshold that
         * grows with the empty space AROUND the photographs grows with
         * the wrong thing -- the gap between panels is not part of the
         * gesture.
         *
         * The panel, by contrast, is the thing being grabbed. A fifth of
         * it is a deliberate swipe and no more, and the floor and cap
         * keep it between 48px and 120px whatever `--u` has grown to.
         */
        dragThreshold() {
            const panel = this.panels[this.index];
            const width = panel ? panel.offsetWidth : 320;

            return clamp(width * 0.2, 48, 120);
        },

        /**
         * Listen for the gesture. Bound once, in `init()`, imperatively.
         *
         * Not through `x-on` attributes in the markup, because a drag needs
         * `setPointerCapture` and a capture-phase click interceptor, and expressing
         * those in attributes means either an `x-on` per panel or reaching for
         * `$refs` and a custom directive. The listener is on the fan, so it is ONE
         * listener for the whole stage however many photographs it holds.
         */
        bindDrag() {
            if (this.unreadable || this.count < 2) {
                return;
            }

            this.fan = this.$el.querySelector('[data-stage-fan]');

            if (!this.fan) {
                return;
            }

            this.fan.addEventListener('pointerdown', (event) => {
                // A fresh press clears a stale suppression, so a gesture that ends
                // without producing a click cannot eat the next real one.
                this.suppressClick = false;

                this.dragStart(event);
            });

            this.fan.addEventListener('pointermove', (event) => this.dragMove(event));
            this.fan.addEventListener('pointerup', (event) => this.dragEnd(event));
            this.fan.addEventListener('pointercancel', (event) => this.dragEnd(event));

            /*
             * THE BROWSER'S OWN DRAG IS THE ENEMY HERE, and `dragstart`
             * is where it begins. The photograph and the link wrapping
             * it are BOTH draggable by default, so a horizontal pull
             * starts a native drag-and-drop: a ghost image follows the
             * pointer, `pointercancel` fires, and the fan's gesture
             * dies halfway. `dragstart` bubbles, so this one listener
             * on the fan cancels it for every descendant whatever the
             * gesture started on, and the pull stays with the stage
             * that owns it.
             *
             * The `draggable="false"` on the image and the link is
             * the first line of defence and works without JavaScript;
             * this is the net under it.
             */
            this.fan.addEventListener('dragstart', (event) => {
                event.preventDefault();
            });

            /*
             * A drag that ends over a panel must not ALSO follow that panel's link.
             *
             * CAPTURE phase, and on the stage rather than the fan. By the time the
             * click event exists there is no drag left to cancel -- the gesture has
             * already happened and the browser has already synthesised the click
             * from the same pointer sequence. The only thing that can stop it is to
             * intercept the click BEFORE it reaches the anchor, which means a
             * capture-phase listener on an ancestor of that anchor.
             * `stopPropagation` in the capture phase means the anchor never sees it.
             *
             * Without this, every drag that ends over a card navigates away from
             * the page the visitor is looking at -- and on a touch screen, where
             * releasing always lands on something, the gesture is simply unusable.
             */
            this.$el.addEventListener('click', (event) => {
                if (!this.suppressClick) {
                    return;
                }

                this.suppressClick = false;

                event.preventDefault();
                event.stopPropagation();
            }, true);
        },

        dragStart(event) {
            // Primary button only, so a right-click never starts a drag.
            if (event.button !== 0 || this.unreadable) {
                return;
            }

            this.dragging = false;
            this.dragFrom = event.clientX;
            this.dragDelta = 0;

            /*
             * CAPTURE THE POINTER ON THE FAN, not on the panel the press started
             * on. The panels are narrow and the fan is wider than any of them, so a
             * drag travelling more than a panel's width leaves the element the
             * press landed on -- and without capture the browser stops delivering
             * moves there, the gesture dies halfway, and the fan snaps back under
             * a finger that is still moving.
             */
            this.fan.setPointerCapture(event.pointerId);

            // A press is contact with the stage, whatever it turns into.
            this.hold('drag');
        },

        dragMove(event) {
            if (this.dragFrom === null) {
                return;
            }

            const delta = event.clientX - this.dragFrom;

            /*
             * A 6px dead zone before the drag counts.
             *
             * Without it a plain tap is a zero-pixel drag: it flickers the
             * `is-dragging` class, changes the cursor, takes the autoplay hold
             * and releases it again, and on a touch screen competes with the
             * browser's own tap gesture. Six pixels is below the tap threshold
             * on every platform that has one, so a tap is never mistaken
             * for a drag.
             */
            if (!this.dragging && Math.abs(delta) < 6) {
                return;
            }

            if (!this.dragging) {
                this.dragging = true;
                this.fan.classList.add('is-dragging');
            }

            /*
             * THE FAN IS THE ONLY THING THAT TRACKS THE POINTER. Every panel
             * keeps its formation and its own `data-offset` for the whole
             * gesture, so a release into an advance hands the movement to the
             * CSS transition from a known origin instead of from wherever the
             * pointer left off.
             *
             * `* 0.75` is RESISTANCE, not physics: the fan follows three
             * quarters of the pointer's travel, which keeps the gesture
             * legible while making it impossible to pull a panel so far that
             * a cancelled drag reads as a throw. It is deliberately light --
             * heavier resistance makes a swipe feel like wading, and with the
             * threshold now measured off the panel rather than the fan there
             * is no need to soak up distance here.
             */
            this.dragDelta = delta * 0.75;

            this.fan.style.setProperty('--tm-drag-x', `${this.dragDelta}px`);
        },

        dragEnd() {
            if (this.dragFrom === null) {
                return;
            }

            this.dragFrom = null;

            this.release('drag');

            // A press that never cleared the dead zone was a click, not a drag.
            if (!this.dragging) {
                return;
            }

            this.dragging = false;
            this.fan.classList.remove('is-dragging');

            // Read the direction BEFORE the delta is cleared.
            const travelled = Math.abs(this.dragDelta);
            const forwards = this.dragDelta < 0;

            /*
             * The gesture is over and real, so the click the browser is about to
             * synthesise from this same pointer sequence must not also navigate.
             */
            this.suppressClick = true;
            this.dragDelta = 0;

            /*
             * HOME THE FAN FIRST, in both branches.
             *
             * `.tm-fan`'s `transform` transition is suppressed by `is-dragging`,
             * which has just been removed, so this re-centre is instant in a commit
             * and eased in a cancel -- and either way it happens BEFORE the panels
             * start animating, so the two movements are sequential rather than
             * running over the same pixels at once.
             *
             * Only the custom property changes here. `--tm-drag-x` feeds a
             * transitioned `transform`, and the transition applies to the resolved
             * value, so no `@property` registration is needed and none of this
             * needs a second frame to settle.
             */
            this.fan.style.setProperty('--tm-drag-x', '0px');

            if (travelled < this.dragThreshold()) {
                // Too short to commit: a cancel. The fan eases home on its own.
                return;
            }

            if (forwards) {
                this.next();
            } else {
                this.prev();
            }
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

            if (!slide || !slide.imageUrl || this.backdrops.length < 1) {
                return;
            }

            /*
             * `shownBackdrop` is null when the stage OPENS on a destination with no
             * photograph, because there was nothing to cross-fade from. The incoming
             * layer is then whichever exists and it fades in from nothing, which is
             * the right outcome rather than a missing backdrop.
             */
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
            return this.count > 1
                && !this.unreadable
                && !this.paused
                && this.held.length === 0
                && !document.hidden;
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
