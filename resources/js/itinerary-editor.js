/**
 * Itinerary editor.
 *
 * Everything here works on DOM the Blade view already rendered: one row per
 * stop, carrying hidden inputs named items[<key>][...]. Because a row is keyed
 * by its database id rather than its position, reordering a day only means
 * rewriting the sort_order inputs, never renumbering field names.
 *
 * A key the browser invents is negative (see ItineraryEditor). That is how a
 * stop the traveller just added survives being dragged around before it is
 * saved for the first time.
 *
 * The day window, the lunch block and the travel gap are NOT reimplemented
 * here. Reflow asks the server for them, so an edited day is laid out by the
 * same rules that generated it.
 */

const TIME_PATTERN = /^\d{2}:\d{2}$/;

function toMinutes(value) {
    if (!TIME_PATTERN.test(value ?? '')) {
        return null;
    }

    const [hours, minutes] = value.split(':').map(Number);

    return hours * 60 + minutes;
}

function fromMinutes(total) {
    const clamped = Math.max(0, Math.min(24 * 60 - 1, total));

    return `${String(Math.floor(clamped / 60)).padStart(2, '0')}:${String(clamped % 60).padStart(2, '0')}`;
}

function rowFields(row, field) {
    return row.querySelector(`[data-field="${field}"]`);
}

function initItineraryEditor(root) {
    const form = root.querySelector('[data-editor-form]');
    const readView = root.querySelector('[data-editor-read]');
    const editView = root.querySelector('[data-editor-edit]');
    const dialog = root.querySelector('[data-add-dialog]');
    const notice = root.querySelector('[data-editor-notice]');

    if (!form || !readView || !editView || !dialog) {
        return;
    }

    const scheduleUrl = form.dataset.scheduleUrl;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    let editing = false;
    let dirty = false;
    let nextKey = -1;

    const lists = () => [...editView.querySelectorAll('[data-stop-list]')];

    function say(message, tone = 'info') {
        if (!notice) {
            return;
        }

        notice.textContent = message ?? '';
        notice.dataset.tone = message ? tone : 'info';
        notice.hidden = !message;
    }

    function markDirty() {
        dirty = true;
    }

    /* ---------------------------------------------------------------- order */

    function renumber(list) {
        [...list.querySelectorAll('[data-stop]')].forEach((row, index) => {
            rowFields(row, 'sort-order').value = String(index + 1);
        });

        updateArrows(list);
    }

    function updateArrows(list) {
        const rows = [...list.querySelectorAll('[data-stop]')];

        rows.forEach((row, index) => {
            row.querySelector('[data-move="up"]').disabled = index === 0;
            row.querySelector('[data-move="down"]').disabled = index === rows.length - 1;
        });
    }

    function move(row, offset) {
        const list = row.closest('[data-stop-list]');
        const sibling = offset < 0 ? row.previousElementSibling : row.nextElementSibling;

        if (!sibling) {
            return;
        }

        if (offset < 0) {
            list.insertBefore(row, sibling);
        } else {
            list.insertBefore(sibling, row);
        }

        renumber(list);
        markDirty();
        row.querySelector('[data-drag-handle]').focus();
    }

    /* ------------------------------------------------------------- reflow */

    function draftPayload() {
        const items = {};
        const data = new FormData(form);

        for (const [name, value] of data.entries()) {
            const match = /^items\[(-?\d+)\]\[(\w+)\]$/.exec(name);

            if (!match) {
                continue;
            }

            items[match[1]] ??= {};
            items[match[1]][match[2]] = value;
        }

        return { items };
    }

    function applyTimes(dayId) {
        const list = editView.querySelector(`[data-stop-list][data-day-id="${dayId}"]`);
        const card = list?.closest('[data-day-card]');
        const button = card?.querySelector('[data-reflow]');

        button?.setAttribute('aria-busy', 'true');

        fetch(scheduleUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(draftPayload()),
        })
            .then((response) => response.json().then((body) => ({ ok: response.ok, body })))
            .then(({ ok, body }) => {
                if (!ok) {
                    say(body?.message ?? 'Those times could not be recomputed.', 'error');

                    return;
                }

                const slots = body.days?.[String(dayId)] ?? {};
                let latest = 0;

                Object.entries(slots).forEach(([key, slot]) => {
                    const row = list.querySelector(`[data-stop][data-key="${key}"]`);

                    if (!row) {
                        return;
                    }

                    rowFields(row, 'start-time').value = slot.start_time;
                    rowFields(row, 'end-time').value = slot.end_time;

                    latest = Math.max(latest, toMinutes(slot.end_time) ?? 0);
                });

                refreshTravel(list);
                markDirty();

                say(
                    latest > 18 * 60
                        ? `Day ${card.dataset.dayNumber} now runs to ${fromMinutes(latest)}. That is past the ${fromMinutes(18 * 60)} the planner aims for.`
                        : `Day ${card.dataset.dayNumber} refits into ${fromMinutes(latest)}.`,
                    latest > 18 * 60 ? 'warn' : 'info'
                );
            })
            .catch(() => say('Those times could not be recomputed.', 'error'))
            .finally(() => button?.removeAttribute('aria-busy'));
    }

    /* -------------------------------------------------------------- travel */

    function refreshTravel(list) {
        let previousEnd = null;

        [...list.querySelectorAll('[data-stop]')].forEach((row) => {
            const start = toMinutes(rowFields(row, 'start-time').value);
            const end = toMinutes(rowFields(row, 'end-time').value);
            const badge = row.querySelector('[data-travel]');

            if (start === null || end === null || end <= start) {
                badge.textContent = '';
                badge.hidden = true;
            } else if (previousEnd === null) {
                badge.textContent = 'First stop';
                badge.hidden = false;
            } else {
                const gap = Math.max(0, start - previousEnd);

                badge.textContent = gap === 0 ? 'Back to back' : `${gap} min travel`;
                badge.hidden = false;
            }

            previousEnd = end ?? previousEnd;
        });
    }

    /* ----------------------------------------------------------------- add */

    function addStop(destinationId, dayId) {
        const template = root.querySelector('[data-stop-template]');
        const list = editView.querySelector(`[data-stop-list][data-day-id="${dayId}"]`);
        const checkbox = dialog.querySelector(`[data-destination="${destinationId}"]`);

        if (!template || !list || !checkbox) {
            return;
        }

        const key = String(nextKey--);
        const fragment = template.content.cloneNode(true);
        const row = fragment.querySelector('[data-stop]');

        row.dataset.key = key;

        fragment.querySelectorAll('[name]').forEach((input) => {
            input.name = input.name.replace('__KEY__', key);
        });

        const field = (name) => row.querySelector(`[data-field="${name}"]`);

        field('day-id').value = dayId;
        field('destination-id').value = destinationId;
        field('sort-order').value = String(list.querySelectorAll('[data-stop]').length + 1);
        field('start-time').value = '';
        field('end-time').value = '';
        field('estimated-cost').value = checkbox.dataset.cost ?? '';
        field('note').value = '';

        const link = row.querySelector('[data-stop-name]');

        link.textContent = checkbox.dataset.name;
        link.href = checkbox.dataset.url;
        row.querySelector('[data-stop-place]').textContent = checkbox.dataset.place;
        row.querySelector('[data-stop-cost]').textContent = checkbox.dataset.fee;
        row.querySelector('[data-remove]').setAttribute('aria-label', `Remove ${checkbox.dataset.name}`);

        for (const [label, verb] of [
            ['[data-drag-handle]', 'Drag to reorder'],
            ['[data-move="up"]', 'Move earlier'],
            ['[data-move="down"]', 'Move later'],
        ]) {
            row.querySelector(label).setAttribute('aria-label', `${verb} ${checkbox.dataset.name}`);
        }

        list.appendChild(row);

        renumber(list);
        refreshTravel(list);
        markDirty();
    }

    /* ---------------------------------------------------------------- drag */

    function draggable(row) {
        const handle = row.querySelector('[data-drag-handle]');

        handle.addEventListener('pointerdown', (event) => {
            if (event.button !== 0) {
                return;
            }

            event.preventDefault();

            const list = row.closest('[data-stop-list]');
            const placeholder = document.createElement('li');
            const box = row.getBoundingClientRect();
            const offsetY = event.clientY - box.top;

            placeholder.style.height = `${box.height}px`;
            placeholder.className = 'rounded-xl border-2 border-dashed border-boracay bg-boracay-light/40';
            placeholder.setAttribute('aria-hidden', 'true');

            list.insertBefore(placeholder, row);
            row.style.position = 'fixed';
            row.style.zIndex = '40';
            row.style.top = `${event.clientY - offsetY}px`;
            row.style.width = `${box.width}px`;
            row.style.pointerEvents = 'none';
            document.body.style.userSelect = 'none';

            let pointerY = event.clientY;
            let frame = 0;

            const place = () => {
                frame = 0;
                row.style.top = `${pointerY - offsetY}px`;

                const edge = 90;

                if (pointerY < edge) {
                    window.scrollBy(0, -12);
                } else if (pointerY > window.innerHeight - edge) {
                    window.scrollBy(0, 12);
                }
            };

            const onMove = (moveEvent) => {
                pointerY = moveEvent.clientY;

                const rest = [...list.querySelectorAll('[data-stop]')].filter((node) => node !== row);
                let index = rest.length;

                for (let i = 0; i < rest.length; i += 1) {
                    const box = rest[i].getBoundingClientRect();

                    if (pointerY < box.top + box.height / 2) {
                        index = i;
                        break;
                    }
                }

                const reference = rest[index] ?? null;

                if (reference) {
                    list.insertBefore(placeholder, reference);
                } else {
                    list.appendChild(placeholder);
                }

                if (!frame) {
                    frame = requestAnimationFrame(place);
                }
            };

            const onEnd = () => {
                cancelAnimationFrame(frame);
                window.removeEventListener('pointermove', onMove);
                window.removeEventListener('pointerup', onEnd);
                window.removeEventListener('pointercancel', onEnd);

                row.style.position = '';
                row.style.zIndex = '';
                row.style.width = '';
                row.style.pointerEvents = '';
                document.body.style.userSelect = '';

                placeholder.replaceWith(row);

                renumber(list);
                refreshTravel(list);
                markDirty();
            };

            handle.setPointerCapture(event.pointerId);
            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onEnd);
            window.addEventListener('pointercancel', onEnd);
        });
    }

    /* --------------------------------------------------------------- wiring */

    editView.addEventListener('click', (event) => {
        const target = event.target.closest('[data-move], [data-remove], [data-reflow], [data-add]');

        if (!target) {
            return;
        }

        const row = target.closest('[data-stop]');

        if (target.matches('[data-move]')) {
            move(row, target.dataset.move === 'up' ? -1 : 1);
        } else if (target.matches('[data-remove]')) {
            const list = row.closest('[data-stop-list]');

            row.remove();
            renumber(list);
            refreshTravel(list);
            markDirty();
        } else if (target.matches('[data-reflow]')) {
            applyTimes(target.closest('[data-day-card]').dataset.dayId);
        } else if (target.matches('[data-add]')) {
            openDialog(target.closest('[data-day-card]').dataset.dayId);
        }
    });

    function openDialog(dayId) {
        dialog.dataset.targetDay = dayId;
        dialog.querySelectorAll('[data-day-option]').forEach((option) => {
            option.checked = option.value === dayId;
        });

        dialog.showModal();
    }

    dialog.addEventListener('change', (event) => {
        if (event.target.matches('[data-destination]')) {
            const picked = dialog.querySelectorAll('[data-destination]:checked');

            dialog.querySelector('[data-add-count]').textContent = String(picked.length);
            dialog.querySelector('[data-add-confirm]').disabled = picked.length === 0;
        }
    });

    dialog.querySelector('[data-add-confirm]').addEventListener('click', () => {
        const dayId = dialog.dataset.targetDay;
        const picked = [...dialog.querySelectorAll('[data-destination]:checked')];

        picked.forEach((checkbox) => addStop(checkbox.dataset.destination, dayId));

        dialog.close();
        say(
            `${picked.length} ${picked.length === 1 ? 'stop' : 'stops'} added. Give ${picked.length === 1 ? 'it a time' : 'them times'} or reflow the day.`
        );
    });

    root.querySelectorAll('[data-editor-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            editing = !editing;

            readView.hidden = editing;
            editView.hidden = !editing;

            root.querySelectorAll('[data-editor-toggle]').forEach((each) => {
                each.textContent = editing ? 'Stop editing' : 'Edit itinerary';
                each.setAttribute('aria-pressed', String(editing));
            });

            lists().forEach((list) => {
                renumber(list);
                refreshTravel(list);
            });

            if (!editing) {
                dirty = false;
                say('');
            }
        });
    });

    root.querySelector('[data-editor-cancel]')?.addEventListener('click', () => {
        window.location.reload();
    });

    editView.querySelectorAll('[data-stop]').forEach(draggable);

    editView.addEventListener('input', (event) => {
        if (event.target.matches('[data-field="start-time"], [data-field="end-time"]')) {
            refreshTravel(event.target.closest('[data-stop-list]'));
        }

        markDirty();
    });

    form.addEventListener('submit', () => {
        dirty = false;
        say('');
    });

    window.addEventListener('beforeunload', (event) => {
        if (editing && dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
}

document.querySelectorAll('[data-itinerary-editor]').forEach(initItineraryEditor);