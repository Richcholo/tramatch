<x-guest-layout>
    <div class="mb-4 text-sm text-gray-600">
        We emailed a six-digit code to <strong>{{ auth()->user()->email }}</strong>.
        Enter it below to finish signing up. The code expires in 15 minutes.
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 font-medium text-sm text-green-600">
            A new code is on its way to your inbox.
        </div>
    @endif

    <form method="POST" action="{{ route('verification.code') }}">
        @csrf

        <div>
            <x-input-label for="code" value="Verification code" />
            <x-text-input
                id="code"
                name="code"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                required
                autofocus
                placeholder="123456"
                class="mt-1 block w-full tracking-[0.5em] uppercase"
            />
            <x-input-error :messages="$errors->get('code')" class="mt-2" />
        </div>

        <div class="mt-4 flex items-center justify-between">
            <x-primary-button>
                Verify email
            </x-primary-button>

            <button type="button" onclick="document.getElementById('logout-confirmation').showModal()" class="underline text-sm text-red-600 hover:text-red-700 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                Log Out
            </button>
        </div>
    </form>

    <div class="mt-6">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf

            <button type="submit" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-boracay">
                Didn't get the code? Send another
            </button>
        </form>
    </div>

    <x-logout-confirmation />
</x-guest-layout>
