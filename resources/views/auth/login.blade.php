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

            <x-text-input
                id="password"
                class="mt-2 block w-full rounded-xl border-boracay-light bg-white focus:border-boracay focus:ring-boracay"
                type="password"
                name="password"
                required
                autocomplete="current-password"
            />

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
                class="w-full rounded-full bg-boracay px-6 py-3 font-bold text-benguet-charcoal transition hover:-translate-y-0.5 hover:bg-boracay-dark hover:text-white"
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
</x-guest-layout>