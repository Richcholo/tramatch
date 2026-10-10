@php
    $message = session('toast_message');
@endphp

@if ($message)
    <div
        data-toast
        role="status"
        aria-live="polite"
        class="pointer-events-auto fixed bottom-6 right-6 z-50 flex max-w-sm translate-y-3 items-start gap-4 rounded-2xl border border-boracay bg-volcanic-teal px-5 py-4 text-white opacity-0 shadow-2xl transition-all duration-300"
    >
        <p class="text-sm font-semibold leading-6">{{ $message }}</p>

        <button
            type="button"
            data-toast-close
            aria-label="Dismiss"
            class="-mr-1 shrink-0 rounded-full px-2 text-lg leading-none text-white/60 transition hover:text-white"
        >
            &times;
        </button>
    </div>
@endif

@if ($message)
    <script data-toast-script>
        (() => {
            const toast = document.querySelector('[data-toast]');

            if (!toast) {
                return;
            }

            requestAnimationFrame(() => {
                toast.classList.remove('translate-y-3', 'opacity-0');
            });

            let timer = null;

            const dismiss = () => {
                toast.classList.add('translate-y-3', 'opacity-0');

                window.setTimeout(() => toast.remove(), 300);
            };

            document
                .querySelector('[data-toast-close]')
                ?.addEventListener('click', () => {
                    window.clearTimeout(timer);
                    dismiss();
                });

            timer = window.setTimeout(dismiss, 5000);
        })();
    </script>
@endif
