{{--
    THE ANSWER TO "DID THAT DO ANYTHING?", FOR A BUTTON THAT TAKES 20 SECONDS.

    The assist is a plain synchronous POST: one request to Gemini, then a
    redirect. Nothing about that flow reports progress on its own, so a traveller
    who clicks "Stuck? Ask for a nudge" watches an unchanged page for as long as
    the model takes -- and the SUCCESS feedback (the suggestion panel) lands
    further down the page where they are not looking, while failure lands in a
    bottom-right toast that takes no layout space. Both are easy to miss at the
    moment they matter.

    Three states, and the middle one is why this exists:

      loading   opened by the script the instant the form is submitted
      success   a check mark, if the redirect landed with `assist_result`
      failure   a cross, if it landed with `assist_message`

    THE FAILURE STATE READS ITS OWN SESSION KEY, NOT THE TOAST KEY. A toast is
    transient text that vanishes in five seconds; a popup that says the nudge
    failed needs to still be on the page in ten, and needs to say which kind of
    failure it was in the same words the toast uses. `assist_message` is flashed
    alongside `toast_message` by the controller's failure branch, so the two
    never disagree about what happened -- and the toast remains for the case
    where scripting is off and this dialog never opens.

    No JavaScript means no dialog: the form still posts, the panel still
    renders, the toast still fires. This component is feedback, never the
    mechanism.
--}}

@php
    /*
     * The outcome, resolved ONCE here so the script and the markup cannot
     * disagree about which state the page is in. `null` means this is an
     * ordinary page load with no assist behind it -- the dialog renders but
     * stays shut, and only the submit hook can open it.
     */
    $assistResult = session('assist_result');
    $assistMessage = session('assist_message');

    if (($assistResult['status'] ?? '') === 'ok') {
        $outcome = 'ok';
        $heading = 'A nudge is ready';
        $detail = 'The picks are below, and the next card on the deck has been brought forward.';
    } elseif (is_string($assistMessage) && $assistMessage !== '') {
        $outcome = 'error';
        // The controller's own sentence, verbatim: this popup and the toast
        // must not describe one failure two ways.
        $heading = 'That nudge did not come through';
        $detail = $assistMessage;
    } else {
        $outcome = null;
        $heading = '';
        $detail = '';
    }
@endphp

<dialog
    id="assist-feedback"
    data-assist-feedback
    data-assist-outcome="{{ $outcome }}"
    aria-labelledby="assist-feedback-title"
    class="tm-assist-dialog m-auto w-[calc(100%-2rem)] max-w-sm rounded-2xl border border-white/15 bg-volcanic-teal p-0 text-island-white shadow-2xl backdrop:bg-volcanic-teal/70 backdrop:backdrop-blur-sm"
>
    <div class="flex flex-col items-center px-7 py-9 text-center">
        {{--
            THE THREE MARKS, AND ONLY ONE IS EVER SHOWN.

            `[data-assist-spinner]` is the resting state -- visible whenever
            neither of the two finished marks is. That ordering matters: the
            success and failure branches are EMPTY on an ordinary page load, so
            a rule that showed a mark by default would flash a tick or a cross
            on the way in.

            The spinner is a stroked arc with a gap, the same shape the
            navigation loader uses, so "working" looks the same everywhere in
            this app.
        --}}
        <div class="relative flex h-16 w-16 items-center justify-center" aria-hidden="true">
            <span data-assist-mark="loading" class="tm-assist-mark absolute inset-0 items-center justify-center text-boracay-light">
                <svg class="h-10 w-10" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle
                        cx="20"
                        cy="20"
                        r="16"
                        stroke="currentColor"
                        stroke-width="3"
                        stroke-linecap="round"
                        stroke-dasharray="26 74"
                        class="tm-assist-spin"
                    />
                </svg>
            </span>

            <span data-assist-mark="ok" class="tm-assist-mark absolute inset-0 items-center justify-center text-boracay-light">
                <svg class="h-14 w-14" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
            </span>

            <span data-assist-mark="error" class="tm-assist-mark absolute inset-0 items-center justify-center text-red-300">
                <svg class="h-14 w-14" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </span>
        </div>

        {{--
            THE HEADINGS AND THE DETAIL ARE WRITTEN ONCE, IN BLADE, AND THE
            SCRIPT ONLY SWAPS WHICH PAIR IS SHOWING.

            A script that composed this copy would be a second place the
            failure sentence lives, and the failure sentence is the
            controller's `assistMessage()` -- one implementation, quoted.
        --}}
        <h2 id="assist-feedback-title" class="mt-6 font-display text-xl font-semibold text-island-white">
            {{ $heading }}
        </h2>

        <p class="mt-2 max-w-xs text-sm leading-6 text-island-white/65">
            {{ $detail }}
        </p>

        {{--
            THE CLOSE BUTTON IS RENDERED ALWAYS AND HIDDEN WHILE LOADING.

            While the request is in flight there is nothing to close -- the
            browser is already navigating -- and a visible button that does
            nothing is worse than no button. It appears with the finished
            state. It is never the ONLY way out: the dialog is a `<dialog>`, so
            Escape closes it, and it closes itself after a few seconds.
        --}}
        <button
            type="button"
            data-assist-dismiss
            hidden
            class="mt-6 min-h-10 rounded-md bg-boracay px-5 py-2 text-sm font-bold text-benguet-charcoal transition hover:bg-boracay-dark hover:text-island-white active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-philippine-gold"
        >
            Back to the deck
        </button>
    </div>
</dialog>

<script data-assist-feedback-script>
    (() => {
        const dialog = document.querySelector('[data-assist-feedback]');

        if (!dialog || typeof dialog.showModal !== 'function') {
            // No `<dialog>` support: the form still posts, the panel and the
            // toast still render. Nothing is lost but the spinner.
            return;
        }

        const outcome = dialog.getAttribute('data-assist-outcome');

        const dismiss = dialog.querySelector('[data-assist-dismiss]');

        // Exactly one mark is showing at a time, and `loading` is the resting
        // state -- see the note in the markup for why that ordering matters.
        const showMark = (name) => {
            dialog.querySelectorAll('[data-assist-mark]').forEach((mark) => {
                mark.classList.toggle('is-shown', mark.getAttribute('data-assist-mark') === name);
            });
        };

        showMark('loading');

        /*
         * WHAT A FINISHED ASSIST SAYS.

         * `ok` and `error` share the two text nodes, swapped here. The strings
         * are not in this script for a reason: the failure sentence is the
         * controller's, rendered into the markup, and this only reveals it.
         */
        const settle = (kind) => {
            showMark(kind);
            dismiss?.removeAttribute('hidden');
        };

        let timer = null;

        const open = () => {
            if (dialog.open) {
                return;
            }

            // `showModal` can throw if the element is already open or not
            // connected; neither should happen, and neither should break the
            // page if it does.
            try {
                dialog.showModal();
            } catch (error) {
                return;
            }
        };

        const close = () => {
            window.clearTimeout(timer);

            if (dialog.open) {
                dialog.close();
            }
        };

        dismiss?.addEventListener('click', close);

        /*
         * THE LOADING STATE, ARMING BEFORE THE BROWSER LEAVES.

         * `submit` fires whether the form was sent by a click on the submit
         * button or by Enter in a field, which a `click` listener would miss.
         * Nothing is prevented: the navigation is the point, and this is only
         * the curtain drawn over it.

         * THE BUTTON IS DISABLED TOO. A second click during a request that can
         * take twenty seconds spends another of the free tier's requests on a
         * cooldown refusal; disabling it is the cheapest way to say "already
         * asking". It is re-enabled nowhere, because the page is about to
         * become a different page -- except on a failed navigation, where the
         * browser restores the form and this leaves a control that cannot be
         * pressed again. `pageshow` below is what hands it back.
         */
        document.querySelectorAll('[data-assist-form]').forEach((form) => {
            const submit = form.querySelector('button[type="submit"]');

            form.addEventListener('submit', () => {
                open();
                showMark('loading');
                dismiss?.setAttribute('hidden', '');

                if (submit) {
                    submit.disabled = true;
                }
            });
        });

        // A back/forward restore can bring this page back with the button
        // still disabled and the dialog still open from the state it left in.
        window.addEventListener('pageshow', () => {
            document.querySelectorAll('[data-assist-form] button[type="submit"]').forEach((button) => {
                button.disabled = false;
            });

            close();
        });

        /*
         * A STANDING OUTCOME OPENS THE DIALOG ON ARRIVAL.

         * This is the half that makes the feature readable: the success panel
         * is BELOW the fold and the failure toast is a corner, so without this
         * a traveller who was looking at the button sees the page change and
         * still cannot tell what happened.
         */
        if (outcome === 'ok' || outcome === 'error') {
            settle(outcome);
            open();

            // A MODEST AUTO-DISMISS, AND IT DIFFERS BY OUTCOME. A success needs
            // long enough to register as one; a failure carries a sentence the
            // traveller may actually need to read, so it stays and waits for
            // them -- the close button, Escape, or a click on the backdrop.
            if (outcome === 'ok') {
                timer = window.setTimeout(close, 2600);
            }
        }

        // Clicking the backdrop closes it. `<dialog>`'s own backdrop is a
        // pseudo-element, so a click that lands on the dialog element itself is
        // a click outside its content box.
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                close();
            }
        });
    })();
</script>
