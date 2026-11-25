<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <p class="text-sm text-zinc-600 dark:text-zinc-300">
            Use an email address you can access. We will send a verification code; verify it to use your account.
        </p>

        @unless ($hasImportedStudentList)
            <p role="status" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                The guidance counselor has not imported the student list yet. Student registration will be available after it is imported.
            </p>
        @endunless
        <form method="POST" action="{{ route('register.store') }}" enctype="multipart/form-data" class="flex flex-col gap-6">
            @csrf
            <flux:input name="first_name" :label="__('First name')" :value="old('first_name')" type="text" required autofocus autocomplete="given-name" />
            <flux:input name="last_name" :label="__('Last name')" :value="old('last_name')" type="text" required autocomplete="family-name" />
            <flux:input name="middle_initial" :label="__('M.I. (optional)')" :value="old('middle_initial')" type="text" autocomplete="additional-name" placeholder="e.g. A" />
            <flux:input name="name_extension" :label="__('Name extension (optional)')" :value="old('name_extension')" type="text" placeholder="e.g. Jr., III" />

            <!-- Student ID -->
            <flux:input
                name="id_number"
                :label="__('Student ID')"
                :value="old('id_number')"
                type="text"
                inputmode="numeric"
                pattern="[0-9]*"
                maxlength="255"
                data-digits-only
                required
                autocomplete="off"
                :placeholder="__('Enter your ID number')"
            />
            <p class="-mt-4 text-xs text-zinc-500 dark:text-zinc-400">Digits only.</p>

            <!-- Gender -->
            <flux:select name="gender" label="Gender" required>
                <flux:select.option value="">Select gender</flux:select.option>
                <flux:select.option value="male" :selected="old('gender') === 'male'">Male</flux:select.option>
                <flux:select.option value="female" :selected="old('gender') === 'female'">Female</flux:select.option>
            </flux:select>

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />
            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                minlength="8"
                pattern="(?=.*[A-Za-z])(?=.*[0-9])[A-Za-z0-9]+"
                title="Use letters and numbers only, with at least one of each."
                autocomplete="new-password"
                :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                minlength="8"
                pattern="(?=.*[A-Za-z])(?=.*[0-9])[A-Za-z0-9]+"
                title="Use letters and numbers only, with at least one of each."
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <input type="hidden" name="teacher_request_confirmation" value="0" data-teacher-request-confirmation>
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Create account') }}
                </flux:button>
            </div>

            <dialog
                class="w-[min(92vw,32rem)] rounded-2xl border border-zinc-200 bg-white p-0 text-zinc-900 shadow-2xl backdrop:bg-zinc-950/60 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                @if (session('show_teacher_verification_modal')) open @endif
                data-teacher-verification-modal
            >
                <div class="space-y-5 p-6">
                    <div class="space-y-2">
                        <h2 class="text-xl font-semibold">Faculty verification required</h2>
                        <p class="text-sm text-zinc-600 dark:text-zinc-300">
                            We could not match these credentials with the imported student list. If you are a faculty member, upload an ID photo or take a selfie so the guidance counselor can review your request.
                        </p>
                    </div>

                    <flux:input
                        name="verification_photo"
                        :label="__('Faculty verification photo')"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        capture="user"
                    />
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">JPG, PNG, or WebP up to 5 MB. The image is used only for counselor review.</p>

                    @error('verification_photo')
                        <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror

                    <div class="flex justify-end gap-3">
                        <button type="button" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800" data-close-teacher-modal>
                            Cancel
                        </button>
                        <button type="submit" class="rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-800" data-confirm-teacher-request>
                            Submit for review
                        </button>
                    </div>
                </div>
            </dialog>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
                <flux:link :href="route('home')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
        <p class="text-center text-sm text-zinc-600 dark:text-zinc-400">
            Teacher?
            <flux:text>{{ __('Teacher requests are reviewed by the guidance counselor after you submit the registration form.') }}</flux:text>
        </p>
    </div>
</x-layouts::auth>

<script>
    (() => {
        const modal = document.querySelector('[data-teacher-verification-modal]');
        const confirmation = document.querySelector('[data-teacher-request-confirmation]');

        if (!modal || !confirmation) return;

        document.querySelector('[data-close-teacher-modal]')?.addEventListener('click', () => {
            modal.close();
            confirmation.value = '0';
        });

        document.querySelector('[data-confirm-teacher-request]')?.addEventListener('click', () => {
            confirmation.value = '1';
        });
    })();
</script>
