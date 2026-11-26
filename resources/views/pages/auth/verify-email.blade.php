<x-layouts::auth :title="__('Email verification')">
    @if (auth()->user()?->role === 'teacher')
        <div class="min-h-[60vh]">
            <dialog
                id="teacher-email-verification-modal"
                class="fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[min(28rem,calc(100%-2rem))] overflow-y-auto rounded-2xl border border-neutral-200 bg-white p-6 text-neutral-900 shadow-2xl backdrop:bg-black/50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-zinc-100"
                aria-labelledby="teacher-email-verification-heading"
                aria-describedby="teacher-email-verification-description"
            >
                <h1 id="teacher-email-verification-heading" class="text-xl font-semibold">
                    {{ __('Verify your email') }}
                </h1>
                <p id="teacher-email-verification-description" class="mt-2 text-sm text-neutral-600 dark:text-neutral-300">
                    {{ __('Enter the 6-digit code sent to your email address to access your teacher dashboard.') }}
                </p>

                <x-auth-session-status class="mt-4 text-center" :status="session('status')" />

                @if ($errors->any())
                    <div role="alert" class="mt-4 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('verification.otp.verify') }}" class="mt-5 flex flex-col gap-4">
                    @csrf
                    <flux:input name="otp" :label="__('6-digit code')" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" data-digits-only required autocomplete="one-time-code" autofocus />
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-600 dark:hover:bg-emerald-500">
                        {{ __('Verify email') }}
                    </button>
                </form>

                <div class="mt-4 flex flex-col items-center gap-3">
                    <form method="POST" action="{{ route('verification.otp.send') }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center justify-center rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200 dark:hover:bg-emerald-950/70">
                            {{ __('Resend verification code') }}
                        </button>
                    </form>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium text-neutral-600 transition hover:bg-neutral-100 hover:text-neutral-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:hover:text-white" data-test="logout-button">
                            {{ __('Log out') }}
                        </button>
                    </form>
                </div>
            </dialog>
        </div>

        <script>
            const teacherVerificationModal = document.getElementById('teacher-email-verification-modal');
            if (teacherVerificationModal) {
                teacherVerificationModal.addEventListener('cancel', (event) => event.preventDefault());
                teacherVerificationModal.showModal();
                teacherVerificationModal.querySelector('input[name="otp"]').focus();
            }
        </script>
    @else
        <div class="mt-4 flex flex-col gap-6">
            <flux:text class="text-center">
                {{ __('Enter the 6-digit code sent to your registered email address. Once verified, you will be signed in and sent to your dashboard.') }}
            </flux:text>

            <x-auth-session-status class="text-center" :status="session('status')" />

            @if ($errors->any())
                <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('verification.otp.verify') }}" class="flex flex-col gap-4">
                @csrf
                <flux:input name="otp" :label="__('6-digit code')" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" data-digits-only required autocomplete="one-time-code" />
                <flux:button type="submit" variant="primary" class="w-full">
                    {{ __('Verify email') }}
                </flux:button>
            </form>

            <div class="flex flex-col items-center justify-between space-y-3">
                <form method="POST" action="{{ route('verification.otp.send') }}">
                    @csrf
                    <flux:button type="submit" variant="primary" class="w-full">
                        {{ __('Resend verification code') }}
                    </flux:button>
                </form>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <flux:button variant="ghost" type="submit" class="text-sm cursor-pointer" data-test="logout-button">
                        {{ __('Log out') }}
                    </flux:button>
                </form>
            </div>
        </div>
    @endif
</x-layouts::auth>
