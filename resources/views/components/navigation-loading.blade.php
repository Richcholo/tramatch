<div
    data-navigation-loading
    role="status"
    aria-live="polite"
    class="pointer-events-none fixed inset-0 z-[100000] hidden bg-sea-glass"
>
    <span class="sr-only">Loading page...</span>

    <div aria-hidden="true" class="border-b border-white/10 bg-volcanic-teal">
        <div class="mx-auto flex h-[81px] max-w-[1600px] items-center justify-between px-5 sm:px-8 lg:px-12">
            <div class="h-10 w-32 animate-pulse rounded bg-white/20"></div>
            <div class="h-11 w-11 animate-pulse rounded-full bg-white/15"></div>
        </div>
    </div>

    <div aria-hidden="true" class="mx-auto max-w-[1600px] px-5 py-10 sm:px-8 lg:px-12">
        <div class="mb-10 max-w-2xl">
            <div class="h-3 w-24 animate-pulse rounded bg-boracay/40"></div>
            <div class="mt-4 h-9 w-3/4 animate-pulse rounded bg-benguet-charcoal/10"></div>
            <div class="mt-3 h-4 w-full max-w-lg animate-pulse rounded bg-benguet-charcoal/10"></div>
        </div>

        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @for ($card = 0; $card < 3; $card++)
                <div class="rounded-md border border-boracay-light bg-island-white p-5">
                    <div class="aspect-[16/9] animate-pulse rounded bg-boracay-light"></div>
                    <div class="mt-5 h-5 w-2/3 animate-pulse rounded bg-benguet-charcoal/10"></div>
                    <div class="mt-3 h-3 w-full animate-pulse rounded bg-benguet-charcoal/10"></div>
                    <div class="mt-2 h-3 w-4/5 animate-pulse rounded bg-benguet-charcoal/10"></div>
                </div>
            @endfor
        </div>
    </div>
</div>
