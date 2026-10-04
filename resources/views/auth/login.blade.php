<x-guest-layout>
    <div class="text-center">
        <p class="text-xs font-bold uppercase tracking-[0.28em] text-boracay-dark">
            Welcome back
        </p>

        <h1 class="mt-4 font-display text-4xl font-semibold leading-tight tracking-[-0.04em] text-volcanic-teal">
            Return to your next place.
        </h1>

        <p class="mt-3 text-sm leading-6 text-benguet-charcoal/70">
            Continue discovering destinations that match your travel style.
        </p>
    </div>

    <x-auth-session-status
        class="mt-6"
        :status="session('status')"
    />

    <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5">
        @csrf

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
                autofocus
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

            <div class="relative mt-2">
                <x-text-input
                    id="password"
                    class="block w-full rounded-xl border-boracay-light bg-white pr-12 focus:border-boracay focus:ring-boracay"
                    type="password"
                    name="password"
                    required
                    autocomplete="current-password"
                />

                <button
                    type="button"
                    data-password-toggle="password"
                    aria-label="Show password"
                    class="absolute inset-y-0 right-0 flex items-center pr-4 text-benguet-charcoal/40 transition hover:text-benguet-charcoal"
                >
                    <svg data-icon-show class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <svg data-icon-hide class="hidden h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/>
                    </svg>
                </button>
            </div>

            <x-input-error
                :messages="$errors->get('password')"
                class="mt-2"
            />
        </div>

        <label class="flex items-center gap-3 text-sm text-benguet-charcoal/70">
            <input
                id="remember_me"
                type="checkbox"
                name="remember"
                class="rounded border-boracay-light text-boracay focus:ring-boracay"
            >

            <span>
                {{ __('Remember me') }}
            </span>
        </label>

        <div class="flex flex-col items-center gap-4 pt-2 text-center">
            <button
                type="submit"
                class="w-full rounded-full bg-boracay px-6 py-3 font-bold text-benguet-charcoal transition hover:-translate-y-0.5 hover:bg-boracay-dark hover:text-white active:scale-[0.98] active:duration-75"
            >
                {{ __('Log in') }}
            </button>

            @if (Route::has('password.request'))
                <a
                    href="{{ route('password.request') }}"
                    class="text-sm font-semibold text-boracay-dark underline hover:text-volcanic-teal"
                >
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            @if (Route::has('register'))
                <p class="text-sm text-benguet-charcoal/70">
                    Don't have an account?

                    <a
                        href="{{ route('register') }}"
                        class="font-bold text-boracay-dark hover:text-volcanic-teal"
                    >
                        Create an account
                    </a>
                </p>
            @endif
        </div>
    </form>

    <script>
        document.querySelectorAll('[data-password-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const input = document.getElementById(button.dataset.passwordToggle);
                const revealing = input.type === 'password';
                input.type = revealing ? 'text' : 'password';
                button.querySelector('[data-icon-show]').classList.toggle('hidden', revealing);
                button.querySelector('[data-icon-hide]').classList.toggle('hidden', !revealing);
                button.setAttribute('aria-label', revealing ? 'Hide password' : 'Show password');
            });
        });
    </script>
</x-guest-layout>