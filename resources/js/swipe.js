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

    const positionCards = () => {
        cards.forEach((card, index) => {
            card.style.zIndex = cards.length - index;
            card.style.pointerEvents = index === 0 ? 'auto' : 'none';
            card.style.transform = index === 0
                ? 'translateY(0) scale(1)'
                : `translateY(${index * 10}px) scale(${1 - index * 0.025})`;
            card.style.opacity = index > 2 ? '0' : '1';
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

            if (Math.abs(currentX) > 110) {
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

    positionCards();
    updateCount();
}