<section>
    <header>
        <h2 class="font-display text-2xl font-semibold text-volcanic-teal">
            {{ __('Update Password') }}
        </h2>

        <p class="mt-2 text-sm leading-6 text-benguet-charcoal/65">
            {{ __('Ensure your account is using a long, random password to stay secure.') }}
        </p>
    </header>

    <form id="profile-password-form" method="post" action="{{ route('password.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('put')

        <div>
            <label for="update_password_current_password" class="block text-sm font-semibold text-benguet-charcoal">Current password</label>
            <input id="update_password_current_password" name="current_password" type="password" autocomplete="current-password" class="mt-2 block w-full rounded-md border border-boracay-light bg-white px-3 py-2.5 text-sm text-benguet-charcoal shadow-sm focus:border-volcanic-teal focus:outline-none focus:ring-2 focus:ring-volcanic-teal/20">
            <x-input-error :messages="$errors->updatePassword->get('current_password')" class="mt-2" />
        </div>

        <div>
            <label for="update_password_password" class="block text-sm font-semibold text-benguet-charcoal">New password</label>
            <input id="update_password_password" name="password" type="password" autocomplete="new-password" class="mt-2 block w-full rounded-md border border-boracay-light bg-white px-3 py-2.5 text-sm text-benguet-charcoal shadow-sm focus:border-volcanic-teal focus:outline-none focus:ring-2 focus:ring-volcanic-teal/20">
            <x-input-error :messages="$errors->updatePassword->get('password')" class="mt-2" />
        </div>

        <div>
            <label for="update_password_password_confirmation" class="block text-sm font-semibold text-benguet-charcoal">Confirm new password</label>
            <input id="update_password_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="mt-2 block w-full rounded-md border border-boracay-light bg-white px-3 py-2.5 text-sm text-benguet-charcoal shadow-sm focus:border-volcanic-teal focus:outline-none focus:ring-2 focus:ring-volcanic-teal/20">
            <x-input-error :messages="$errors->updatePassword->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex flex-wrap items-center gap-4">
            <button type="button" onclick="document.getElementById('confirm-profile-password').showModal()" class="inline-flex min-h-11 items-center justify-center rounded-md bg-volcanic-teal px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-boracay-dark focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-volcanic-teal">
                {{ __('Update password') }}
            </button>

            @if (session('status') === 'password-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm font-medium text-boracay-dark"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>

    <x-confirm-dialog
        id="confirm-profile-password"
        title="Update your password?"
        description="Your new password will replace the current one."
        submit-label="Confirm password change"
        form="profile-password-form"
    />
</section>
