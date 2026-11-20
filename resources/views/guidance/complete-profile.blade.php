<x-layouts::auth :title="__('Complete guidance counselor account')">
    <div class="min-h-[60vh]">
        <dialog
            id="counselor-profile-setup-modal"
            class="fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[min(28rem,calc(100%-2rem))] overflow-y-auto rounded-2xl border border-neutral-200 bg-white p-6 text-neutral-900 shadow-2xl backdrop:bg-black/50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-zinc-100"
            aria-labelledby="counselor-profile-setup-heading"
            aria-describedby="counselor-profile-setup-description"
        >
            <h1 id="counselor-profile-setup-heading" class="text-xl font-semibold">
                {{ __('Complete your guidance counselor account') }}
            </h1>
            <p id="counselor-profile-setup-description" class="mt-2 text-sm text-neutral-600 dark:text-neutral-300">
                {{ __('Enter your name and email address, and replace the initial password before continuing.') }}
            </p>

            @if ($errors->any())
                <div role="alert" class="mt-4 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('counselor.profile.update') }}" class="mt-5 flex flex-col gap-5">
                @csrf
                @method('PUT')

                <flux:input
                    name="name"
                    :label="__('Full name')"
                    :value="old('name', $user->name === 'Administrator' ? '' : $user->name)"
                    type="text"
                    required
                    autocomplete="name"
                />

                <flux:input
                    name="email"
                    :label="__('Email address')"
                    :value="old('email', $user->email)"
                    type="email"
                    required
                    autocomplete="email"
                />

                <flux:input
                    name="password"
                    :label="__('New password')"
                    type="password"
                    required
                    minlength="8"
                    pattern="(?=.*[A-Za-z])(?=.*[0-9])[A-Za-z0-9]+"
                    title="Use letters and numbers only, with at least one of each."
                    autocomplete="new-password"
                />

                <flux:input
                    name="password_confirmation"
                    :label="__('Confirm new password')"
                    type="password"
                    required
                    minlength="8"
                    pattern="(?=.*[A-Za-z])(?=.*[0-9])[A-Za-z0-9]+"
                    title="Use letters and numbers only, with at least one of each."
                    autocomplete="new-password"
                />

                <flux:button type="submit" variant="primary" class="w-full">
                    {{ __('Save details and update password') }}
                </flux:button>
            </form>

            <form method="POST" action="{{ route('logout') }}" class="mt-3 text-center">
                @csrf
                <flux:button variant="ghost" type="submit">
                    {{ __('Log out') }}
                </flux:button>
            </form>
        </dialog>
    </div>

    <script>
        const counselorProfileSetupModal = document.getElementById('counselor-profile-setup-modal');
        if (counselorProfileSetupModal) {
            counselorProfileSetupModal.addEventListener('cancel', (event) => event.preventDefault());
            counselorProfileSetupModal.showModal();
            counselorProfileSetupModal.querySelector('input[name="name"]').focus();
        }
    </script>
</x-layouts::auth>
