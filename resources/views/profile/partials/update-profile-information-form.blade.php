<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <!-- Avatar forms (separate to adhere to SOLID) -->
    <form id="avatar-upload-form" method="post" action="{{ route('user.avatar.update') }}" enctype="multipart/form-data" class="hidden">
        @csrf
        @method('patch')
    </form>
    <form id="avatar-delete-form" method="post" action="{{ route('user.avatar.destroy') }}" class="hidden">
        @csrf
        @method('delete')
    </form>

    <!-- Avatar UI Block (outside of the profile form to avoid nested form issues) -->
    <div class="mt-6 space-y-2">
        <x-input-label for="avatar" :value="__('Avatar')" />
        <div class="mt-2 flex items-center gap-4">
            @php
                $avatarUrl = $user->avatar ? \Illuminate\Support\Facades\Storage::url($user->avatar) : null;
            @endphp
            @if ($avatarUrl)
                <div class="relative inline-block h-12 w-12">
                    <img src="{{ $avatarUrl }}" alt="{{ __('Current avatar') }}" class="h-12 w-12 rounded-full object-cover" />
                    <button type="submit" form="avatar-delete-form"
                            class="absolute top-0 right-0 z-10 h-6 w-6 -translate-y-1/3 translate-x-1/3 rounded-full bg-red-600 text-white text-xs flex items-center justify-center shadow hover:bg-red-700"
                            title="{{ __('Remove avatar') }}" aria-label="{{ __('Remove avatar') }}">
                        ×
                    </button>
                </div>
            @endif
            <input id="avatar" name="avatar" type="file" accept="image/*" class="block"
                   form="avatar-upload-form"
                   onchange="document.getElementById('avatar-upload-form').submit();" />
        </div>
        <p class="text-xs text-gray-500 mt-1">{{ __('Max 10 MB. Accepted: jpg, jpeg, png, webp, gif') }}</p>
        <x-input-error class="mt-2" :messages="$errors->get('avatar')" />
    </div>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        
        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="text-sm mt-2 text-gray-800">
                        {{ __('Your email address is unverified.') }}

                        <button form="send-verification" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-green-600">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
