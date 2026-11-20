<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email address or account name and password to log in')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
                {{ $errors->first() }}
            </div>
        @endif

        <x-passkey-verify />

        <form id="login-form" method="POST" action="{{ route('login.root') }}" class="flex flex-col gap-6">
            @csrf
            <input type="hidden" name="login_latitude" id="login-latitude">
            <input type="hidden" name="login_longitude" id="login-longitude">

            <!-- Email or account name -->
            <flux:input
                id="login-email"
                name="email"
                :label="__('Email address or account name')"
                :value="old('email')"
                type="text"
                required
                autofocus
                autocomplete="username"
                placeholder="Email address or account name"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    id="login-password"
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <label class="flex items-start gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                <input
                    id="share-login-location"
                    name="share_location"
                    type="checkbox"
                    value="1"
                    class="mt-1 rounded border-zinc-300 text-emerald-700 focus:ring-emerald-700 dark:border-zinc-600 dark:bg-zinc-800"
                >
                <span>Share this device’s location in my account logs. Your browser will ask permission; coordinates stay in this system and are never sent to a location provider.</span>
            </label>

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button" data-loading-button data-loading-label="Signing in...">
                    <span data-button-label>{{ __('Log in') }}</span>
                    <span class="loading-spinner" data-loading-spinner aria-hidden="true" hidden></span>
                </flux:button>
                <span class="loading-status" data-submit-status role="status" aria-live="polite" hidden></span>
            </div>
        </form>

        <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Don\'t have an account?') }}</span>
            <flux:link :href="route('register')" wire:navigate>{{ __('Sign up') }}</flux:link>
        </div>
        <section aria-labelledby="login-about-heading" class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900/50 dark:text-zinc-300">
            <h2 id="login-about-heading" class="font-semibold text-zinc-900 dark:text-zinc-100">About Us</h2>
            <div class="mt-2 space-y-4">
                <div>
                    <h3 class="font-medium text-zinc-900 dark:text-zinc-100">What is KajoRec?</h3>
                    <p class="mt-1">KajoRec is a web-based Guidance Office Record Management and Appointment System developed for Benguet State University – Bokod Campus. It is designed to help the Guidance Office manage student records, appointment requests, referrals, and guidance-related forms in an organized and accessible manner.</p>
                    <p class="mt-1">By providing a centralized platform for managing guidance-related information, KajoRec aims to reduce manual recordkeeping, simplify appointment management, and support the efficient delivery of guidance services.</p>
                </div>
                <div>
                    <h3 class="font-medium text-zinc-900 dark:text-zinc-100">Our Purpose</h3>
                    <p class="mt-1">To provide the Guidance Office with a reliable and organized system for handling student records and managing guidance-related transactions while maintaining the confidentiality and security of student information.</p>
                </div>
                <div>
                    <h3 class="font-medium text-zinc-900 dark:text-zinc-100">Our Goals</h3>
                    <ul class="mt-1 list-disc space-y-1 ps-5">
                        <li>Improve the organization and management of student records.</li>
                        <li>Simplify appointment scheduling and management.</li>
                        <li>Provide an organized way to record and manage referrals.</li>
                        <li>Make guidance-related forms more accessible to authorized users.</li>
                        <li>Support secure and efficient Guidance Office operations.</li>
                    </ul>
                </div>
                <div>
                    <h3 class="font-medium text-zinc-900 dark:text-zinc-100">Who Can Use KajoRec?</h3>
                    <dl class="mt-1 space-y-2">
                        <div>
                            <dt class="font-medium text-zinc-800 dark:text-zinc-200">Guidance Counselors</dt>
                            <dd>Manage student records, appointments, referrals, and guidance-related activities.</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-zinc-800 dark:text-zinc-200">Administrative Staff</dt>
                            <dd>Assist in managing and organizing Guidance Office information.</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-zinc-800 dark:text-zinc-200">Students and Teachers</dt>
                            <dd>Access available system services, submit appointment requests, referrals, and guidance-related forms according to their assigned roles.</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </section>
        <section aria-labelledby="login-contact-heading" class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900/50 dark:text-zinc-300">
            <h2 id="login-contact-heading" class="font-semibold text-zinc-900 dark:text-zinc-100">Contact Us</h2>
            <p class="mt-1">We are the researchers behind KajoRec. For account or login assistance, email us at <a class="font-medium text-emerald-700 underline hover:text-emerald-800 dark:text-emerald-300 dark:hover:text-emerald-200" href="mailto:kajorec@gmail.com">kajorec@gmail.com</a>.</p>
            <p class="mt-1">Support hours: 8:00 AM–3:00 PM.</p>
        </section>
    </div>

    <script>
        document.getElementById('login-form')?.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' || event.isComposing || event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) {
                return;
            }

            const email = document.getElementById('login-email');
            const password = document.getElementById('login-password');

            if (email && !email.value.trim()) {
                event.preventDefault();
                email.focus();
            } else if (password && !password.value) {
                event.preventDefault();
                password.focus();
            }
        });

        const loginForm = document.getElementById('login-form');
        const shareLocation = document.getElementById('share-login-location');
        let locationSubmissionStarted = false;
        let loginSubmitted = false;

        loginForm?.addEventListener('submit', (event) => {
            const button = loginForm.querySelector('[data-loading-button]');
            button.disabled = true;
            button.querySelector('[data-button-label]').textContent = button.dataset.loadingLabel;
            button.querySelector('[data-loading-spinner]').hidden = false;
            const status = loginForm.querySelector('[data-submit-status]');
            status.textContent = button.dataset.loadingLabel;
            status.hidden = false;

            if (!shareLocation?.checked || locationSubmissionStarted) {
                return;
            }

            event.preventDefault();
            locationSubmissionStarted = true;

            const submitLogin = () => {
                if (loginSubmitted) {
                    return;
                }

                loginSubmitted = true;
                HTMLFormElement.prototype.submit.call(loginForm);
            };
            const submitWithLocation = (position) => {
                document.getElementById('login-latitude').value = position.coords.latitude;
                document.getElementById('login-longitude').value = position.coords.longitude;
                submitLogin();
            };

            if (!navigator.geolocation) {
                submitLogin();
                return;
            }

            navigator.geolocation.getCurrentPosition(submitWithLocation, submitLogin, {
                enableHighAccuracy: false,
                maximumAge: 60000,
                timeout: 5000,
            });
            window.setTimeout(submitLogin, 5500);
        });
    </script>
</x-layouts::auth>
