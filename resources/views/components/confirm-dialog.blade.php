@props([
    'id',
    'title',
    'description' => null,
    'submitLabel' => 'Confirm',
    'cancelLabel' => 'Cancel',
    'form' => null,
    'destructive' => false,
    'open' => false,
])

<dialog
    id="{{ $id }}"
    aria-labelledby="{{ $id }}-title"
    @if ($description)
        aria-describedby="{{ $id }}-description"
    @endif
    onclick="if (event.target === this) this.close()"
    @if ($open)
        open
    @endif
    class="m-auto w-[calc(100%-2rem)] max-w-md rounded-lg border border-boracay-light bg-palawan-sand p-0 text-benguet-charcoal shadow-2xl backdrop:bg-volcanic-teal/60 backdrop:backdrop-blur-sm"
>
    <div class="p-6 sm:p-7">
        <div class="flex items-start gap-4">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full {{ $destructive ? 'bg-red-100 text-red-700' : 'bg-boracay-light text-boracay-dark' }}">
                @if ($destructive)
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                    </svg>
                @else
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                    </svg>
                @endif
            </span>

            <div>
                <h2 id="{{ $id }}-title" class="font-display text-xl font-semibold {{ $destructive ? 'text-red-800' : 'text-volcanic-teal' }}">
                    {{ $title }}
                </h2>

                @if ($description)
                    <p id="{{ $id }}-description" class="mt-2 text-sm leading-6 text-benguet-charcoal/70">
                        {{ $description }}
                    </p>
                @endif
            </div>
        </div>

        @if (! trim($slot))
            <div class="mt-7 flex justify-end gap-3">
                <button
                    type="button"
                    autofocus
                    onclick="this.closest('dialog').close()"
                    class="rounded-full border border-boracay-light px-4 py-2 text-sm font-semibold text-benguet-charcoal transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-boracay focus:ring-offset-2"
                >{{ $cancelLabel }}</button>

                <button
                    type="submit"
                    @if ($form)
                        form="{{ $form }}"
                    @endif
                    class="rounded-full px-4 py-2 text-sm font-bold text-white transition focus:outline-none focus:ring-2 focus:ring-offset-2 {{ $destructive ? 'bg-red-700 hover:bg-red-800 focus:ring-red-700' : 'bg-volcanic-teal hover:bg-boracay-dark focus:ring-volcanic-teal' }}"
                >{{ $submitLabel }}</button>
            </div>
        @else
            {{ $slot }}
        @endif
    </div>
</dialog>