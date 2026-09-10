<x-guest-layout>
    <div class="text-center">
        <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
            Begin your journey
        </p>

        <h1 class="mt-4 font-display text-4xl font-semibold leading-tight tracking-[-0.04em] text-volcanic-teal">
            Make your next trip feel like yours.
        </h1>

        <p class="mt-3 text-sm leading-6 text-benguet-charcoal/70">
            Create an account and start discovering places through your own preferences.
        </p>
    </div>

    <form method="POST" action="{{ route('register') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <x-input-label
                for="name"
                :value="__('Name')"
                class="text-benguet-charcoal"
            />

            <x-text-input
                id="name"
                class="mt-2 block w-full rounded-xl border-boracay-light bg-white focus:border-boracay focus:ring-boracay"
                type="text"
                name="name"
                :value="old('name')"
                required
                autofocus
                autocomplete="name"
            />

            <x-input-error
                :messages="$errors->get('name')"
                class="mt-2"
            />
        </div>

        <div>
            <x-input-label
                for="email"
                :value="__('Email')"
                class="text-benguet-charcoal"
            />

            <x-text-input
                id="email"
                class="mt-2 block w-full rounded-xl border-boracay-light bg-white focus:border-boracay focus:ring-boracay"
                type="email"
                name="email"
                :value="old('email')"
                required
                autocomplete="username"
            />

            <x-input-error
                :messages="$errors->get('email')"
                class="mt-2"
            />
        </div>

        <div>
            <x-input-label
                for="password"
                :value="__('Password')"
                class="text-benguet-charcoal"
            />

            <x-text-input
                id="password"
                class="mt-2 block w-full rounded-xl border-boracay-light bg-white focus:border-boracay focus:ring-boracay"
                type="password"
                name="password"
                required
                autocomplete="new-password"
            />

            <x-input-error
                :messages="$errors->get('password')"
                class="mt-2"
            />
        </div>

        <div>
            <x-input-label
                for="password_confirmation"
                :value="__('Confirm Password')"
                class="text-benguet-charcoal"
            />

            <x-text-input
                id="password_confirmation"
                class="mt-2 block w-full rounded-xl border-boracay-light bg-white focus:border-boracay focus:ring-boracay"
                type="password"
                name="password_confirmation"
                required
                autocomplete="new-password"
            />

            <x-input-error
                :messages="$errors->get('password_confirmation')"
                class="mt-2"
            />
        </div>

        <div class="flex flex-col items-center gap-4 pt-2 text-center">
            <button
                type="submit"
                class="w-full rounded-full bg-boracay px-6 py-3 font-bold text-benguet-charcoal transition hover:-translate-y-0.5 hover:bg-boracay-dark hover:text-white"
            >
                {{ __('Create account') }}
            </button>

            @if (Route::has('login'))
                <p class="text-sm text-benguet-charcoal/70">
                    Already have an account?

                    <a
                        href="{{ route('login') }}"
                        class="font-bold text-boracay-dark hover:text-volcanic-teal"
                    >
                        Log in
                    </a>
                </p>
            @endif
        </div>
    </form>
</x-guest-layout>