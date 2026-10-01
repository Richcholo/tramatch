<section>
    <header>
        <h2 class="font-display text-2xl font-semibold text-volcanic-teal">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-2 text-sm leading-6 text-benguet-charcoal/65">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form id="profile-information-form" method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="first_name" class="block text-sm font-semibold text-benguet-charcoal">First name</label>
                <input id="first_name" name="first_name" type="text" value="{{ old('first_name', $user->first_name) }}" required maxlength="100" autocomplete="given-name" class="mt-2 block w-full rounded-md border border-boracay-light bg-white px-3 py-2.5 text-sm text-benguet-charcoal shadow-sm focus:border-volcanic-teal focus:outline-none focus:ring-2 focus:ring-volcanic-teal/20">
                <x-input-error class="mt-2" :messages="$errors->get('first_name')" />
            </div>

            <div>
                <label for="last_name" class="block text-sm font-semibold text-benguet-charcoal">Last name</label>
                <input id="last_name" name="last_name" type="text" value="{{ old('last_name', $user->last_name) }}" required maxlength="100" autocomplete="family-name" class="mt-2 block w-full rounded-md border border-boracay-light bg-white px-3 py-2.5 text-sm text-benguet-charcoal shadow-sm focus:border-volcanic-teal focus:outline-none focus:ring-2 focus:ring-volcanic-teal/20">
                <x-input-error class="mt-2" :messages="$errors->get('last_name')" />
            </div>
        </div>

        <div>
            <label for="email" class="block text-sm font-semibold text-benguet-charcoal">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required autocomplete="username" class="mt-2 block w-full rounded-md border border-boracay-light bg-white px-3 py-2.5 text-sm text-benguet-charcoal shadow-sm focus:border-volcanic-teal focus:outline-none focus:ring-2 focus:ring-volcanic-teal/20">
            <x-input-error class="mt-2" :messages="$errors->get('email')" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div class="mt-4 rounded-md bg-philippine-gold/10 p-4">
                    <p class="text-sm text-benguet-charcoal">
                        {{ __('Your email address is unverified.') }}

                        <button form="send-verification" class="ml-1 font-semibold text-volcanic-teal underline underline-offset-2 hover:text-boracay-dark focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-volcanic-teal">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 text-sm font-medium text-boracay-dark">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-4">
            <button type="button" onclick="document.getElementById('confirm-profile-information').showModal()" class="inline-flex min-h-11 items-center justify-center rounded-md bg-volcanic-teal px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-boracay-dark focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-volcanic-teal">
                {{ __('Save changes') }}
            </button>

            @if (session('status') === 'profile-updated')
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
        id="confirm-profile-information"
        title="Save profile changes?"
        description="Your name and email address will be updated."
        submit-label="Confirm changes"
        form="profile-information-form"
    />
</section>