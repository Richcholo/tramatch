<section class="space-y-6">
    <header>
        <h2 class="font-display text-2xl font-semibold text-red-800">
            {{ __('Delete Account') }}
        </h2>

        <p class="mt-2 text-sm leading-6 text-benguet-charcoal/70">
            {{ __('Your account will be deactivated and you will be signed out. You will no longer be able to access your profile or saved trips.') }}
        </p>
    </header>

    <button
        type="button"
        onclick="document.getElementById('confirm-user-deletion').showModal()"
        class="mt-5 inline-flex min-h-11 items-center justify-center rounded-md bg-red-700 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-red-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
    >{{ __('Delete account') }}</button>

    <x-confirm-dialog
        id="confirm-user-deletion"
        title="Delete your account?"
        :open="$errors->userDeletion->isNotEmpty()"
        destructive
    >
        <form method="post" action="{{ route('profile.destroy') }}" class="mt-7">
            @csrf
            @method('delete')

            <p class="text-sm leading-6 text-benguet-charcoal/70">
                {{ __('Your account will be deactivated and you will be signed out. Enter your password to confirm.') }}
            </p>

            <div class="mt-5">
                <x-input-label for="password" value="{{ __('Password') }}" class="sr-only" />

                <x-text-input
                    id="password"
                    name="password"
                    type="password"
                    class="mt-2 block w-full rounded-md border border-boracay-light bg-white px-3 py-2.5 text-sm text-benguet-charcoal shadow-sm focus:border-volcanic-teal focus:outline-none focus:ring-2 focus:ring-volcanic-teal/20"
                    placeholder="{{ __('Password') }}"
                />

                <x-input-error :messages="$errors->userDeletion->get('password')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <button
                    type="button"
                    onclick="this.closest('dialog').close()"
                    class="rounded-full border border-boracay-light px-4 py-2 text-sm font-semibold text-benguet-charcoal transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-boracay focus:ring-offset-2"
                >{{ __('Cancel') }}</button>

                <button
                    type="submit"
                    class="rounded-full bg-red-700 px-4 py-2 text-sm font-bold text-white transition hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-700 focus:ring-offset-2"
                >{{ __('Delete Account') }}</button>
            </div>
        </form>
    </x-confirm-dialog>
</section>
