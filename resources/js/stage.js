/**
 * The destination hero stage.
 *
 * Progressive enhancement over a server-rendered five-card fan. Everything below
 * already works as plain HTML: the cards are in the DOM in order, the dots are
 * real buttons, and the controls are real links-sized buttons. What this adds is
 * moving the active photo around the arc, and telling assistive tech which photo
 * is showing.
 *
 * WHY THERE IS NO AUTO-ADVANCE
 *
 * The reference design advances every 3.6s and flashes white between slides.
 * Neither is here. This is a detail page that scrolls, below a stage the reader
 * is meant to move past: a timer that keeps swapping the backdrop out from under
 * someone reading the description is worse than no timer. And the flash needs a
 * client-side route swap to mask, which a server-rendered page with ordinary links
 * cannot do without hand-rolling a fragment swap and owning a half-swapped page
 * whenever that fetch failed. A page that cannot break is worth more than a
 * transition. See AGENTS.md.
 *
 * Not verified by any test. There is no browser automation in this project, so the
 * transform choreography, the swipe threshold and the reduced-motion path below
 * have never been run. What IS pinned is the server-rendered fallback: five slots
 * present, the title outside them, and one dot per photo.
 */

/** How far a pointer must travel before a drag counts as a swipe. */
const SWIPE_THRESHOLD = 48;

const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

/*
 * Entry point, invoked immediately.
 *
 * Not an exported default on purpose. This is a standalone Vite entry loaded by
 * the destination page's own `@push('scripts')`, and an unused export gets
 * tree-shaken: the first build produced a 0.00 kB `stage-*.js` because nothing
 * imported the default. `itinerary-editor.js` self-invokes the same way.
 *
 * Called at module scope rather than on DOMContentLoaded because a `@vite` tag in
 * a `@stack` renders before `</body>`, so the markup is already parsed by the time
 * this runs.
 */
function initStage() {
    for (const root of document.querySelectorAll('[data-destination-stage]')) {
        const slots = [...root.querySelectorAll('[data-stage-slot]')];
        const backdrops = [...root.querySelectorAll('[data-stage-backdrop]')];
        const dots = [...root.querySelectorAll('[data-stage-dot]')];
        const prev = root.querySelector('[data-stage-prev]');
        const next = root.querySelector('[data-stage-next]');
        const status = root.querySelector('[data-stage-status]');

        if (slots.length === 0) {
            continue;
        }

        const photos = Number(root.dataset.stagePhotoCount || 0);
        const count = Math.max(photos, 1);

        /*
         * `active` is a SLOT index, not a photo index, and the two are NOT the same
         * length. The fan is five slots wide; the photo count is whatever the
         * destination happens to have.
         *
         * Conflating them is the bug this shape invites. `backdrops` and `dots` are
         * both indexed by PHOTO, so reading them at `active` works only while
         * active < photoCount -- with three photos the first two clicks index past
         * the end of both arrays. The active slot already carries its photo in
         * `data-stage-photo`, so that is the single source of truth and everything
         * else derives from it.
         */
        let active = 2;

        /**
         * The slots that currently have real layout, as [first, last] indices.
         *
         * Below 64rem the far pair is hidden with `opacity: 0`, exactly as the
         * reference does on narrow screens -- NOT `display: none`, which would make
         * the arc visibly collapse and re-expand on resize. The side effect is that
         * those two slots are still in the DOM and still measure non-zero, so
         * walking slot 0 to slot 4 blindly would step onto an invisible card and
         * the fan would appear frozen on a phone.
         *
         * Measured rather than read off a media query, so the script has no copy
         * of the breakpoint to keep in step with the stylesheet. Recomputed on
         * every paint because it changes when the viewport does.
         */
        const visibleBounds = () => {
            let first = -1;
            let last = -1;

            slots.forEach((slot, index) => {
                if (slot.getBoundingClientRect().width > 0) {
                    if (first === -1) {
                        first = index;
                    }

                    last = index;
                }
            });

            return first === -1 ? [active, active] : [first, last];
        };

        /**
         * Bring `active` back inside the visible range.
         *
         * Needed because a resize can hide the slot the fan was left on -- rotate a
         * phone to landscape on the far pair and the active card is now the one the
         * reference hides. Without this the stage shows no active card at all.
         */
        const clampActive = () => {
            const [first, last] = visibleBounds();

            active = Math.min(Math.max(active, first), last);
        };

        /** The photo the currently active slot is showing. */
        const activePhoto = () => {
            const slot = slots[active];

            return slot ? Number(slot.dataset.stagePhoto || 0) : 0;
        };

        /**
         * The slot that shows a given photo, nearest the centre.
         *
         * Used by the dots. A dot means "show me photo N", and with fewer photos
         * than slots several slots show the same one, so the dot has to resolve to
         * a slot rather than assuming slot N is photo N.
         *
         * Searched from the centre outwards so the chosen slot is the one a viewer
         * would expect to be active -- the centre-most card showing that photo --
         * rather than whichever duplicate happens to come first in the DOM.
         */
        const slotForPhoto = (photo) => {
            const centre = slots.findIndex(
                (slot) => slot.dataset.stageOffset === '0'
            );

            const order = slots
                .map((slot, index) => index)
                .sort((a, b) =>
                    Math.abs(a - centre) - Math.abs(b - centre)
                );

            const match = order.find(
                (index) =>
                    Number(slots[index].dataset.stagePhoto || 0) === photo
            );

            return match === undefined ? centre : match;
        };

        /**
         * Paint a slot as active or inactive.
         *
         * Active slots get the elevated z-index and full opacity, which is what
         * makes the middle card read as the subject. Inactive ones are dimmed here
         * rather than by CSS so the state is inspectable from the DOM.
         *
         * The dim is `opacity-90`, matching the class the Blade renders. It used to
         * be `opacity-70 saturate-[0.7]` here AND a `bg-volcanic-teal/55` plate over
         * the image, which compounded into near-black outer cards -- the reference
         * separates the outer cards by size, rotation and drop, not by blacking them
         * out. A mismatch here is silent: JS would re-add the heavy dim on the first
         * paint and the server-rendered HTML would look right until it ran.
         */
        const paintSlot = (slot, isActive) => {
            slot.classList.toggle('z-30', isActive);
            slot.classList.toggle('z-20', !isActive);
            slot.classList.toggle('opacity-90', !isActive);
            slot.setAttribute('aria-current', isActive ? 'true' : 'false');
        };

        const paint = () => {
            clampActive();

            slots.forEach((slot, index) => {
                paintSlot(slot, index === active);
            });

            const shown = activePhoto();

            // `hidden` rather than opacity 0, so only the active photo is
            // composited and the inactive ones are genuinely out of the tree.
            backdrops.forEach((backdrop, index) => {
                backdrop.hidden = index !== shown;
            });

            dots.forEach((dot, index) => {
                const current = index === shown;

                dot.setAttribute('aria-current', current ? 'true' : 'false');
                dot.classList.toggle('bg-white', current);
                dot.classList.toggle('bg-white/30', !current);
            });

            /*
             * Disabled at the ends of the VISIBLE arc, not of the photo list and not
             * of all five slots.
             *
             * Two separate reasons. With two photos and five slots, `active` can
             * legitimately be 4 -- off the right-hand end of the arc but still
             * showing a real photo, and disabling there would strand the traveller.
             * And on a phone the far pair is hidden, so slot 0 and slot 4 are not
             * where the arc actually begins and ends.
             */
            const [first, last] = visibleBounds();

            if (prev) {
                prev.disabled = active === first;
            }

            if (next) {
                next.disabled = active === slots.length - 1;
            }

            if (status) {
                // `shown + 1`, not `active`. The slot index has no relation to the
                // photo number: on a five-slot fan starting at slot 2, `active` is 2
                // on load, which announced "Photo 1 of 3" by accident and "Photo 0"
                // on the way back. This is the only line a screen reader user hears
                // when the fan moves, so it has to be the photo, not the slot.
                status.textContent =
                    count > 1 ? `Photo ${shown + 1} of ${count}` : '';
            }
        };

        const goTo = (index) => {
            // Clamp to the VISIBLE arc, not to 0..slots.length-1. On a phone the
            // outer pair is hidden and slot 4 is unreachable, so clamping to the
            // full range would let a dot or a swipe land on an invisible card.
            const [first, last] = visibleBounds();
            const target = clamp(index, first, last);

            if (target === active) {
                return;
            }

            active = target;
            paint();
        };

        prev?.addEventListener('click', () => goTo(active - 1));
        next?.addEventListener('click', () => goTo(active + 1));

        dots.forEach((dot, index) => {
            // Resolve the dot's photo to a slot rather than using the index as one.
            // With three photos and five slots, dot 2 means "photo 3", not "slot 3"
            // -- clicking it used to land on a slot showing photo 1.
            dot.addEventListener('click', () => goTo(slotForPhoto(index)));
        });

        /*
         * Keyboard, on the stage rather than the window.
         *
         * Left/Right are not universally reserved for carousels -- VoiceOver users
         * navigate with them too -- so the keys only apply while the stage has
         * focus, and the stage is a labelled group rather than a focus sink.
         */
        root.tabIndex = 0;
        root.setAttribute('role', 'group');

        root.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                goTo(active - 1);
            }

            if (event.key === 'ArrowRight') {
                event.preventDefault();
                goTo(active + 1);
            }

            if (event.key === 'Home') {
                event.preventDefault();
                goTo(visibleBounds()[0]);
            }

            if (event.key === 'End') {
                event.preventDefault();
                goTo(visibleBounds()[1]);
            }
        });

        /*
         * Swipe.
         *
         * Pointer events rather than touch events, so one path covers touch, pen
         * and mouse. The threshold matters: a swipe is a deliberate gesture, and a
         * 10px wobble while someone is reaching for the dots should not flip the
         * photo.
         *
         * `pointercancel` is handled alongside `pointerup` because a gesture
         * interrupted by a scroll or a system gesture never fires `up`, and
         * without it `startX` would stay latched and the next stray move would
         * count as a swipe.
         */
        let startX = null;

        root.addEventListener('pointerdown', (event) => {
            if (event.pointerType === 'mouse' && event.button !== 0) {
                return;
            }

            startX = event.clientX;
        });

        const endSwipe = (event) => {
            if (startX === null) {
                return;
            }

            const distance = event.clientX - startX;

            startX = null;

            if (Math.abs(distance) < SWIPE_THRESHOLD) {
                return;
            }

            goTo(active + (distance < 0 ? 1 : -1));
        };

        root.addEventListener('pointerup', endSwipe);
        root.addEventListener('pointercancel', () => {
            startX = null;
        });

        paint();
    }
}

document.querySelectorAll('[data-destination-stage]').forEach(initStage);
