<x-layouts::auth :title="__('Complete teacher account')">
    <div class="min-h-[60vh]">
        <dialog
            id="teacher-profile-setup-modal"
            class="fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[min(28rem,calc(100%-2rem))] overflow-y-auto rounded-2xl border border-neutral-200 bg-white p-6 text-neutral-900 shadow-2xl backdrop:bg-black/50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-zinc-100"
            aria-labelledby="teacher-profile-setup-heading"
            aria-describedby="teacher-profile-setup-description"
        >
            <h1 id="teacher-profile-setup-heading" class="text-xl font-semibold">
                {{ __('Complete your teacher account') }}
            </h1>
            <p id="teacher-profile-setup-description" class="mt-2 text-sm text-neutral-600 dark:text-neutral-300">
                {{ __('Enter your email and teacher ID, and replace the initial password before continuing.') }}
            </p>

            @if ($errors->any())
                <div role="alert" class="mt-4 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('teacher.profile.update') }}" class="mt-5 flex flex-col gap-5">
                @csrf
                @method('PUT')

                <flux:input
                    name="email"
                    :label="__('Email address')"
                    :value="old('email', $user->email)"
                    type="email"
                    required
                    autocomplete="email"
                />

                <flux:input
                    name="id_number"
                    :label="__('Teacher ID number')"
                    :value="old('id_number', $user->id_number)"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    maxlength="255"
                    data-digits-only
                    required
                    autocomplete="off"
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
        const teacherProfileSetupModal = document.getElementById('teacher-profile-setup-modal');
        if (teacherProfileSetupModal) {
            teacherProfileSetupModal.addEventListener('cancel', (event) => event.preventDefault());
            teacherProfileSetupModal.showModal();
            teacherProfileSetupModal.querySelector('input[name="email"]').focus();
        }
    </script>
</x-layouts::auth>
