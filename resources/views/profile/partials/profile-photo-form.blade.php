<section>
    <header>
        <h2 class="font-display text-2xl font-semibold text-volcanic-teal">
            Profile photo
        </h2>
        <p class="mt-2 text-sm leading-6 text-benguet-charcoal/65">
            Choose a photo to personalize your account.
        </p>
    </header>

    <div class="mt-6 flex items-center gap-4">
        <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full bg-volcanic-teal text-lg font-semibold text-white ring-2 ring-boracay-light">
            @if ($user->profile_photo_path)
                <img src="{{ asset('storage/' . $user->profile_photo_path) }}" alt="" class="h-full w-full object-cover">
            @else
                {{ strtoupper(substr($user->first_name ?: 'T', 0, 1) . substr($user->last_name, 0, 1)) }}
            @endif
        </div>
        <p class="text-sm text-benguet-charcoal/65">
            JPG, PNG, or WebP. Maximum size 2 MB.
        </p>
    </div>

    <form id="profile-photo-form" method="post" action="{{ route('profile.photo.update') }}" enctype="multipart/form-data" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="photo" class="block text-sm font-semibold text-benguet-charcoal">
                Choose a new photo
            </label>
            <input
                id="photo"
                name="photo"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                required
                class="mt-2 block w-full text-sm text-benguet-charcoal file:mr-4 file:rounded-md file:border-0 file:bg-boracay-light file:px-4 file:py-2 file:font-semibold file:text-boracay-dark hover:file:bg-boracay"
            >
            <x-input-error class="mt-2" :messages="$errors->get('photo')" />
        </div>

        <button
            type="button"
            onclick="document.getElementById('confirm-profile-photo').showModal()"
            class="inline-flex min-h-11 items-center justify-center rounded-md bg-volcanic-teal px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-boracay-dark focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-volcanic-teal"
        >
            Save photo
        </button>
    </form>

    <x-confirm-dialog
        id="confirm-profile-photo"
        title="Save this profile photo?"
        description="Your current photo will be replaced with the selected image."
        submit-label="Confirm upload"
        form="profile-photo-form"
    />

    @if ($user->profile_photo_path)
        <form id="remove-profile-photo-form" method="post" action="{{ route('profile.photo.destroy') }}" class="mt-5 border-t border-boracay-light pt-5">
            @csrf
            @method('delete')
            <button
                type="button"
                onclick="document.getElementById('confirm-remove-profile-photo').showModal()"
                class="inline-flex min-h-10 items-center justify-center rounded-md px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700"
            >
                Remove photo
            </button>
        </form>

        <x-confirm-dialog
            id="confirm-remove-profile-photo"
            title="Remove your profile photo?"
            description="Your initials avatar will be shown instead."
            submit-label="Remove photo"
            cancel-label="Keep photo"
            form="remove-profile-photo-form"
            destructive
        />
    @endif

    @if (session('photoUpdated') || session('photoRemoved'))
        <p class="mt-4 text-sm font-medium text-boracay-dark" role="status">
            {{ session('photoRemoved') ? 'Profile photo removed.' : 'Profile photo updated.' }}
        </p>
    @endif
</section>