<dialog
    id="logout-confirmation"
    aria-labelledby="logout-confirmation-title"
    aria-describedby="logout-confirmation-description"
    onclick="if (event.target === this) this.close()"
    class="m-auto w-[calc(100%-2rem)] max-w-md rounded-lg border border-line bg-surface p-0 text-ink shadow-2xl backdrop:bg-volcanic-teal/60 backdrop:backdrop-blur-sm"
>
    <div class="p-6 sm:p-7">
        <div class="flex items-start gap-4">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-line text-boracay-dark">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6A2.25 2.25 0 005.25 5.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M18 15l3-3m0 0l-3-3m3 3H9" />
                </svg>
            </span>

            <div>
                <h2 id="logout-confirmation-title" class="font-display text-xl font-semibold text-ink-strong">
                    Log out of TraMatch?
                </h2>

                <p id="logout-confirmation-description" class="mt-2 text-sm leading-6 text-ink/70">
                    You can sign back in any time to continue planning your next trip.
                </p>
            </div>
        </div>

        <form method="POST" action="{{ route('logout') }}" class="mt-7 flex justify-end gap-3">
            @csrf

            <button
                type="button"
                autofocus
                onclick="this.closest('dialog').close()"
                class="rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-boracay focus:ring-offset-2 dark:focus:ring-offset-volcanic-teal"
            >
                Stay signed in
            </button>

            <button
                type="submit"
 class="rounded-full bg-red-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-offset-2 dark:focus:ring-offset-volcanic-teal active:scale-[0.98] active:duration-75"
            >
                Log out
            </button>
        </form>
    </div>
</dialog>
