const deck = document.querySelector('[data-swipe-deck]');

if (deck) {
    const cards = Array.from(deck.querySelectorAll('[data-swipe-card]'));
    const endpoint = deck.dataset.endpoint;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const count = document.querySelector('[data-swipe-count]');
    let startX = 0;
    let currentX = 0;
    let dragging = false;

    /*
     * THE TAKE-BACK'S HAND half. After a decision the card element is
     * kept -- detached but alive -- together with its id, so undo can put
     * the very card the traveller saw back on top instead of re-fetching
     * the deck. `flying` guards the window between the decision and the
     * fly-off's cleanup timer: an undo inside that window would fight the
     * timer over which card the deck's front is.
     */
    const undoButton = document.querySelector('[data-swipe-undo]');
    const lastSwipe = { card: null, destinationId: null };
    let flying = false;

    const updateCount = () => {
        if (count) {
            count.textContent = cards.length;
        }
    };

    /*
     * THE FAN. The two cards behind the top one spread the way
     * the flanking pair does on the destinations stage -- one
     * each side of the top card, scaled down, rotated a few
     * degrees and dimmed -- so the deck reads as a spread of
     * places rather than a pile of them. The top card is the
     * only one that can be swiped; the rest are the formation
     * it leaves behind when it goes.
     *
     * `null` at index 0 is the top card's own resting place,
     * and the absence of a step past index 2 is the same
     * "only three are ever on the table" rule the old pile
     * had: the fourth card is invisible until a swipe promotes
     * it into the formation.
     *
     * The fan is only visible because the deck's frame is
     * WIDER than the card in front -- the cards are inset from
     * its edges -- which is the stage's own geometry. A fan
     * whose frame is exactly as wide as its front card crops
     * its own fan to nothing.
     */
    const FAN_STEPS = [
        null,
        { x: -14, y: 2, rotate: -4, scale: 0.93, brightness: 0.86 },
        { x: 14, y: 4, rotate: 5, scale: 0.87, brightness: 0.74 },
    ];

    /*
     * THE GROUND: a blurred and darkened photograph
     * behind the whole page, cross-fading to the main
     * photo of whichever card the pointer is over.
     *
     * Two layers, because `src` cannot be cross-faded
     * -- it can only be replaced, and a hard swap of a
     * full-bleed blurred photograph behind the page is
     * the single most obvious way this could look
     * broken. The hidden layer is painted with the new
     * photo first, then the two swap which is shown.
     *
     * The card under the pointer is found by HIT-TEST,
     * not by per-card mouseenter, for one reason: a
     * swipe PROMOTES a card under a stationary pointer,
     * and no mouseenter ever fires for a card that
     * moved under the cursor. `positionCards()` re-runs
     * the hit-test after every swipe, so the ground
     * follows the deck the way the stage's backdrop
     * follows its fan.
     *
     * Mouse-only on purpose: "hovered" is a mouse
     * concept and a touch has no hover to follow. This
     * is why the tracking event is `mousemove`, an
     * event a touch never raises -- a pointerenter
     * would fire on every swipe and read as a flicker
     * around the gesture.
     *
     * A card with no photograph (the typographic plate)
     * changes nothing: there is no photo to show, and
     * fading the ground out to plain teal for the length
     * of a hover reads as a fault, not a feature.
     */
    const backdrops = Array.from(
        document.querySelectorAll('[data-discover-backdrop]')
    );

    let shownBackdrop = null;

    const showBackdrop = (photograph) => {
        if (!photograph || backdrops.length < 2) {
            return;
        }

        const incoming = backdrops.find((layer) => layer !== shownBackdrop);

        const src = photograph.currentSrc || photograph.src;

        if (!src) {
            return;
        }

        /*
         * Paint the incoming layer only when it does not
         * already hold this photograph. SHOWING it is not
         * conditional on that: a layer keeps its `src`
         * after the ground fades back to plain teal, so
         * re-hovering the same card has to bring the
         * wash back. Returning early there is exactly
         * how the ground used to disappear.
         */
        if (incoming.getAttribute('src') !== src) {
            incoming.setAttribute('src', src);
        }

        incoming.classList.add('is-shown');

        if (shownBackdrop) {
            shownBackdrop.classList.remove('is-shown');
        }

        shownBackdrop = incoming;
    };

    const clearBackdrop = () => {
        if (!shownBackdrop) {
            return;
        }

        shownBackdrop.classList.remove('is-shown');
        shownBackdrop = null;
    };

    /*
     * Which card is the pointer over? The fanned cards
     * carry `pointer-events: none`, so the top card is
     * the only one a hit-test can find -- and the
     * frame's own margins find nothing, because the
     * ground follows the card, not the deck.
     */
    let pointerX = null;
    let pointerY = null;

    const syncBackdropToPointer = () => {
        if (pointerX === null) {
            return;
        }

        const under = document.elementFromPoint(pointerX, pointerY);
        const card = under
            ? cards.find((candidate) => candidate.contains(under))
            : null;

        if (card) {
            showBackdrop(card.querySelector('img'));

            return;
        }

        clearBackdrop();
    };

    deck.addEventListener('mousemove', (event) => {
        pointerX = event.clientX;
        pointerY = event.clientY;

        syncBackdropToPointer();
    });

    deck.addEventListener('mouseleave', () => {
        pointerX = null;
        pointerY = null;

        clearBackdrop();
    });

    const positionCards = () => {
        cards.forEach((card, index) => {
            card.style.zIndex = cards.length - index;
            card.style.pointerEvents = index === 0 ? 'auto' : 'none';

            const step = FAN_STEPS[index] || null;

            if (!step) {
                card.style.transform = 'translateY(0) scale(1)';
                card.style.filter = 'none';
                card.style.opacity = index === 0 ? '1' : '0';
                return;
            }

            card.style.transform =
                `translateX(${step.x}%) translateY(${step.y}%) ` +
                `rotate(${step.rotate}deg) scale(${step.scale})`;
            card.style.filter = `brightness(${step.brightness})`;
            card.style.opacity = '1';
        });

        /*
         * The formation just changed, so the card under
         * the pointer just changed too -- and nothing
         * will tell us about it, because a card that
         * moved under a stationary pointer raises no
         * mouseenter. Re-run the hit-test, and the
         * ground follows the deck.
         */
        syncBackdropToPointer();
    };

    const saveSwipe = async (action) => {
        const card = cards[0];

        if (!card) {
            return;
        }

        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({
                destination_id: card.dataset.destinationId,
                action,
            }),
        });

        if (!response.ok) {
            throw new Error('Swipe could not be saved.');
        }
    };

    const completeSwipe = async (action) => {
        const card = cards[0];

        if (!card) {
            return;
        }

        try {
            await saveSwipe(action);
            /*
             * THREE DECISIONS, THREE EXITS. A like flies right and a pass
             * flies left, but a skip is an opinion neither way, so it
             * drops straight down -- passing through the bottom of the
             * deck rather than judging a side of the screen it never
             * chose.
             */
            const direction = action === 'liked' ? 1 : -1;
            card.style.transition = 'transform 280ms ease, opacity 280ms ease';
            card.style.transform = action === 'skip'
                ? 'translateY(700px)'
                : `translateX(${direction * 700}px) rotate(${direction * 24}deg)`;
            card.style.opacity = '0';

            lastSwipe.card = card;
            lastSwipe.destinationId = card.dataset.destinationId;
            flying = true;

            window.setTimeout(() => {
                flying = false;

                /*
                 * GUARD AGAINST THE TAKE-BACK. If undo ran while this
                 * timer was pending, the restored card is now the deck's
                 * front and `card` is no longer cards[0] -- shifting then
                 * would tear the restored card back off the deck. Only
                 * the flight's own card is cleaned up.
                 */
                if (cards[0] !== card) {
                    return;
                }

                card.remove();
                cards.shift();
                positionCards();
                updateCount();

                if (cards.length === 0) {
                    window.location.reload();
                }
            }, 280);
        } catch (error) {
            window.alert(error.message);
        }
    };

    /*
     * UNDO POPS ONE ROW AND THE BROWSER RESTORES ONE CARD. The server
     * decides WHICH row is the most recent decision -- it is the truth,
     * and another tab may have decided since this page loaded. When its
     * answer is the card in hand, the card goes back exactly where it sat
     * (before the current front, first in the deck as it was rendered);
     * when it is not, a reload re-deals from the database. The listeners
     * survive, because it is the same element -- a card that comes back
     * swipeable without any rebinding.
     */
    const undoSwipe = async () => {
        if (!undoButton || flying) {
            return;
        }

        let payload = null;

        try {
            const response = await fetch(undoButton.dataset.undoEndpoint, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });

            if (!response.ok) {
                throw new Error('The take-back could not be saved.');
            }

            payload = response.status === 204 ? null : await response.json();
        } catch (error) {
            window.alert(error.message);
            return;
        }

        if (
            lastSwipe.card &&
            payload &&
            String(lastSwipe.destinationId) === String(payload.destination_id)
        ) {
            const card = lastSwipe.card;

            cards.unshift(card);
            card.style.transition = 'none';
            card.style.transform = 'translateY(0) scale(1)';
            card.style.filter = 'none';
            card.style.opacity = '1';

            if (cards[1]) {
                cards[1].insertAdjacentElement('beforebegin', card);
            } else if (deck) {
                deck.appendChild(card);
            }

            lastSwipe.card = null;
            lastSwipe.destinationId = null;
            positionCards();
            updateCount();
        } else {
            window.location.reload();
        }
    };

    undoButton?.addEventListener('click', undoSwipe);

    const activeCard = () => cards[0];

    /*
     * How far the top card must travel before a release
     * commits to a swipe. A FIFTH OF THE CARD IN FRONT OF
     * YOU, floored and capped -- measured off the card, never
     * a fixed number, because the card is the thing being
     * grabbed and its width changes with the screen. A fixed
     * threshold is a third of the card on a phone and a
     * gesture that means nothing on a wide one.
     */
    const dragThreshold = () => {
        const card = activeCard();
        const width = card ? card.offsetWidth : 576;

        return Math.min(Math.max(width * 0.2, 48), 120);
    };

    cards.forEach((card) => {
        card.addEventListener('pointerdown', (event) => {
            if (event.target.closest('button')) {
                return;
            }

            if (card !== activeCard()) {
                return;
            }

            dragging = true;
            startX = event.clientX;
            currentX = 0;
            card.setPointerCapture(event.pointerId);
            card.style.transition = 'none';
        });

        card.addEventListener('pointermove', (event) => {
            if (!dragging || card !== activeCard()) {
                return;
            }

            currentX = event.clientX - startX;
            card.style.transform = `translateX(${currentX}px) rotate(${currentX / 18}deg)`;
        });

        const finishDrag = () => {
            if (!dragging || card !== activeCard()) {
                return;
            }

            dragging = false;
            card.style.transition = 'transform 180ms ease';

            if (Math.abs(currentX) > dragThreshold()) {
                completeSwipe(currentX > 0 ? 'liked' : 'passed');
            } else {
                card.style.transform = 'translateY(0) scale(1)';
            }
        };

        card.addEventListener('pointerup', finishDrag);
        card.addEventListener('pointercancel', finishDrag);

        card.querySelectorAll('[data-swipe-action]').forEach((button) => {
            button.addEventListener('click', () => completeSwipe(button.dataset.swipeAction));
        });
    });

    window.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft') {
            completeSwipe('passed');
        }

        if (event.key === 'ArrowRight') {
            completeSwipe('liked');
        }

        /*
         * The keyboard's third decision, mirroring the three buttons:
         * down is the exit that judges neither side of the screen.
         */
        if (event.key === 'ArrowDown') {
            completeSwipe('skip');
        }
    });

    /*
     * THE BROWSER'S OWN DRAG IS THE ENEMY HERE, exactly as it
     * is on the destinations stage: every card wraps a
     * photograph, and a photograph is draggable by default,
     * so pulling it sideways starts a native drag-and-drop --
     * a ghost image follows the pointer, `pointercancel`
     * fires, and the card's gesture dies halfway. `dragstart`
     * bubbles, so this one listener on the deck cancels it
     * for every card whatever the gesture started on. The
     * `draggable="false"` each photograph carries is the
     * first line of defence; this is the net under it.
     */
    deck.addEventListener('dragstart', (event) => {
        event.preventDefault();
    });

    positionCards();
    updateCount();
}