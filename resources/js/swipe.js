const deck = document.querySelector('[data-swipe-deck]');

if (deck) {
    const cards = Array.from(deck.querySelectorAll('[data-swipe-card]'));
    const endpoint = deck.dataset.endpoint;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const count = document.querySelector('[data-swipe-count]');
    let startX = 0;
    let currentX = 0;
    let dragging = false;

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
            const direction = action === 'liked' ? 1 : -1;
            card.style.transition = 'transform 280ms ease, opacity 280ms ease';
            card.style.transform = `translateX(${direction * 700}px) rotate(${direction * 24}deg)`;
            card.style.opacity = '0';

            window.setTimeout(() => {
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

    /*
     * THE BACKDROP: the page's own ground, a blurred and
     * darkened photograph behind everything, cross-fading
     * to the main photo of whichever card the pointer is
     * over.
     *
     * Two layers, because `src` cannot be cross-faded --
     * it can only be replaced, and a hard swap of a
     * full-bleed blurred photograph behind the page is
     * the single most obvious way this could look broken.
     * The hidden layer is painted with the new photo
     * first, then the two swap which is shown.
     *
     * This is mouse-only on purpose: "hovered" is a mouse
     * concept, and a touch has no hover to follow. A
     * touch fires pointerenter on every swipe instead,
     * which reads as a flicker around the gesture.
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

        if (!src || incoming.getAttribute('src') === src) {
            return;
        }

        incoming.setAttribute('src', src);
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

    cards.forEach((card) => {
        const photograph = card.querySelector('img');

        card.addEventListener('mouseenter', () => showBackdrop(photograph));
        card.addEventListener('mouseleave', clearBackdrop);
    });

    positionCards();
    updateCount();
}